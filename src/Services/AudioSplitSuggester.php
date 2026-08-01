<?php

declare(strict_types=1);

namespace MediaManager\Services;

/**
 * Suggest split in/out from audio silence (when captions are unavailable).
 *
 * Long quiet regions (≥ content gap) separate programs; short dips (ads) are ignored.
 * Continuous multi-hour activity falls back to schedule hourly blocks, refined near quieter gaps.
 */
final class AudioSplitSuggester
{
    public const DEFAULT_CONTENT_GAP_SECONDS = 1800.0; // 30 min — no-content / dead air
    public const DEFAULT_MIN_PROGRAM_SECONDS = 540.0;  // 9 min sustained activity
    public const DEFAULT_AD_IGNORE_SECONDS = 300.0;    // 5 min — commercial-length dips
    public const DEFAULT_SILENCE_NOISE_DB = -35.0;
    public const HOUR_WINDOW_SECONDS = 180.0;

    public function __construct(
        private readonly AudioSilenceDetector $detector = new AudioSilenceDetector(),
        private readonly int $flagThresholdSeconds = ScheduleSplitSuggester::DEFAULT_FLAG_THRESHOLD_SECONDS,
        private readonly float $contentGapSeconds = self::DEFAULT_CONTENT_GAP_SECONDS,
        private readonly float $minProgramSeconds = self::DEFAULT_MIN_PROGRAM_SECONDS,
        private readonly float $adIgnoreSeconds = self::DEFAULT_AD_IGNORE_SECONDS,
    ) {
    }

    /**
     * @return array{
     *   segments: list<array{start: float, end: float, show_id: ?int, label: string, confidence: string, note: string}>,
     *   notes: string,
     *   gap_count: int,
     *   content_gap_count: int
     * }
     */
    public function suggest(
        string $mediaPath,
        ?float $durationSeconds,
        ?string $dateYmd = null,
        ?string $timeHhmm = null,
    ): array {
        $gaps = $this->detector->detect($mediaPath);

        return $this->suggestFromGaps($gaps, $durationSeconds, $dateYmd, $timeHhmm);
    }

    /**
     * @param list<array{start: float, end: float, duration?: float}> $rawGaps
     * @return array{
     *   segments: list<array{start: float, end: float, show_id: ?int, label: string, confidence: string, note: string}>,
     *   notes: string,
     *   gap_count: int,
     *   content_gap_count: int
     * }
     */
    public function suggestFromGaps(
        array $rawGaps,
        ?float $durationSeconds,
        ?string $dateYmd = null,
        ?string $timeHhmm = null,
    ): array {
        $normalized = [];
        foreach ($rawGaps as $gap) {
            $start = (float) ($gap['start'] ?? 0);
            $end = (float) ($gap['end'] ?? 0);
            if ($end <= $start) {
                continue;
            }
            $normalized[] = [
                'start'    => $start,
                'end'      => $end,
                'duration' => $end - $start,
            ];
        }
        usort($normalized, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $duration = $durationSeconds !== null && $durationSeconds > 0
            ? $durationSeconds
            : $this->inferDuration($normalized);

        if ($duration <= 0) {
            return [
                'segments'           => [],
                'notes'              => 'No duration available for audio split suggestion.',
                'gap_count'          => 0,
                'content_gap_count'  => 0,
            ];
        }

        $contentGap = max($this->adIgnoreSeconds + 1.0, $this->contentGapSeconds);
        $adIgnore = max(1.0, $this->adIgnoreSeconds);
        $minProgram = max(30.0, $this->minProgramSeconds);

        $contentGaps = array_values(array_filter(
            $normalized,
            static fn (array $g): bool => $g['duration'] >= $contentGap
        ));
        $cutGaps = array_values(array_filter(
            $normalized,
            static fn (array $g): bool => $g['duration'] >= $adIgnore
        ));

        $activeBlocks = $this->activeBlocks($contentGaps, $duration, $minProgram);

        $schedule = null;
        $threshold = $this->flagThresholdSeconds >= 1
            ? $this->flagThresholdSeconds
            : ScheduleSplitSuggester::DEFAULT_FLAG_THRESHOLD_SECONDS;
        if ($dateYmd !== null && $timeHhmm !== null && $duration > 0) {
            $schedule = (new ScheduleSplitSuggester(flagThresholdSeconds: $threshold))
                ->suggest($dateYmd, $timeHhmm, $duration);
        }

        $segments = [];
        if (count($activeBlocks) >= 2) {
            // Distinct program islands separated by long quiet — prefer content blocks.
            $segments = $this->segmentsFromActiveBlocks($activeBlocks, $schedule);
        } elseif ($schedule !== null && !empty($schedule['segments'])) {
            // Continuous activity (ads only) — hourly schedule, refine with quieter gaps.
            $segments = $this->refineScheduleSegments(
                $schedule['segments'],
                $cutGaps,
                $activeBlocks,
                $duration
            );
        } elseif ($activeBlocks !== []) {
            $segments = $this->segmentsFromActiveBlocks($activeBlocks, null);
        }

        $lines = [
            sprintf(
                'Audio-based split suggestion (%d quiet gap(s) ≥ %d min; min program %d min):',
                count($contentGaps),
                (int) round($contentGap / 60),
                (int) round($minProgram / 60)
            ),
        ];
        foreach ($segments as $seg) {
            $lines[] = sprintf(
                '  %s–%s → %s [%s]%s',
                SrtCaptionParser::secondsToTimecode($seg['start']),
                SrtCaptionParser::secondsToTimecode($seg['end']),
                $seg['label'] !== '' ? $seg['label'] : 'Segment',
                $seg['confidence'],
                $seg['note'] !== '' ? ' — ' . $seg['note'] : ''
            );
        }

        if ($segments === []) {
            $lines[] = '  (no segments — check audio stream / thresholds)';
        }

        return [
            'segments'          => $segments,
            'notes'             => implode("\n", $lines),
            'gap_count'         => count($cutGaps),
            'content_gap_count' => count($contentGaps),
        ];
    }

    /**
     * @param list<array{start: float, end: float, duration: float}> $contentGaps
     * @return list<array{start: float, end: float}>
     */
    private function activeBlocks(array $contentGaps, float $duration, float $minProgram): array
    {
        $blocks = [];
        $cursor = 0.0;
        foreach ($contentGaps as $gap) {
            if ($gap['start'] > $cursor + 1.0) {
                $blocks[] = ['start' => $cursor, 'end' => $gap['start']];
            }
            $cursor = max($cursor, $gap['end']);
        }
        if ($duration > $cursor + 1.0) {
            $blocks[] = ['start' => $cursor, 'end' => $duration];
        }

        // Drop short activity blips (noise / false starts); keep at least one if everything is short.
        $kept = [];
        foreach ($blocks as $block) {
            if (($block['end'] - $block['start']) >= $minProgram) {
                $kept[] = $block;
            }
        }

        return $kept !== [] ? $kept : $blocks;
    }

    /**
     * @param list<array{start_offset: float, end_offset: float, show_id: ?int, show_abbr: ?string, title: string}> $scheduleSegs
     * @param list<array{start: float, end: float, duration: float}> $cutGaps
     * @param list<array{start: float, end: float}> $activeBlocks
     * @return list<array{start: float, end: float, show_id: ?int, label: string, confidence: string, note: string}>
     */
    private function refineScheduleSegments(
        array $scheduleSegs,
        array $cutGaps,
        array $activeBlocks,
        float $duration,
    ): array {
        $out = [];
        $count = count($scheduleSegs);
        $contentStart = $activeBlocks[0]['start'] ?? 0.0;
        $contentEnd = $activeBlocks !== []
            ? (float) $activeBlocks[array_key_last($activeBlocks)]['end']
            : $duration;

        for ($i = 0; $i < $count; $i++) {
            $seg = $scheduleSegs[$i];
            $start = (float) $seg['start_offset'];
            $end = (float) $seg['end_offset'];
            $notes = [];
            $confidence = 'schedule';

            if ($i === 0 && $contentStart > $start + 1.0) {
                $start = min($end - 1.0, $contentStart);
                $notes[] = sprintf('trimmed leading quiet to %.0fs', $contentStart);
                $confidence = 'audio';
            }

            if ($i < $count - 1) {
                $boundary = (float) $seg['end_offset'];
                $gap = $this->nearestGap($boundary, $cutGaps);
                if ($gap !== null) {
                    $end = max($start + 1.0, $gap['start']);
                    $notes[] = sprintf(
                        'cut at quiet %s–%s (%.0f min near hour)',
                        SrtCaptionParser::secondsToTimecode($gap['start']),
                        SrtCaptionParser::secondsToTimecode($gap['end']),
                        $gap['duration'] / 60.0
                    );
                    $confidence = 'audio';
                }
            } else {
                if ($contentEnd < $end - 1.0) {
                    $end = max($start + 1.0, $contentEnd);
                    $notes[] = sprintf('trimmed trailing quiet from %.0fs', $contentEnd);
                    $confidence = 'audio';
                }
            }

            $label = (string) ($seg['show_abbr'] ?? $seg['title'] ?? 'Segment');
            $out[] = [
                'start'      => round(max(0.0, $start), 1),
                'end'        => round(min($end, $duration), 1),
                'show_id'    => isset($seg['show_id']) ? (int) $seg['show_id'] : null,
                'label'      => $label,
                'confidence' => $confidence,
                'note'       => implode('; ', $notes),
            ];
        }

        return array_values(array_filter(
            $out,
            static fn (array $s): bool => $s['end'] > $s['start']
        ));
    }

    /**
     * @param list<array{start: float, end: float}> $blocks
     * @param array{segments?: list<array{show_id: ?int, show_abbr: ?string, title: string}>}|null $schedule
     * @return list<array{start: float, end: float, show_id: ?int, label: string, confidence: string, note: string}>
     */
    private function segmentsFromActiveBlocks(array $blocks, ?array $schedule): array
    {
        $scheduleSegs = $schedule['segments'] ?? [];
        $out = [];
        $i = 0;
        foreach ($blocks as $block) {
            $showId = null;
            $label = 'Part ' . ($i + 1);
            if (isset($scheduleSegs[$i])) {
                $showId = isset($scheduleSegs[$i]['show_id']) ? (int) $scheduleSegs[$i]['show_id'] : null;
                $label = (string) ($scheduleSegs[$i]['show_abbr'] ?? $scheduleSegs[$i]['title'] ?? $label);
            }
            $out[] = [
                'start'      => round((float) $block['start'], 1),
                'end'        => round((float) $block['end'], 1),
                'show_id'    => $showId,
                'label'      => $label,
                'confidence' => 'audio',
                'note'       => sprintf(
                    'Separated by ≥%d min quiet (no content)',
                    (int) round($this->contentGapSeconds / 60)
                ),
            ];
            $i++;
        }

        return $out;
    }

    /**
     * @param list<array{start: float, end: float, duration: float}> $gaps
     * @return array{start: float, end: float, duration: float}|null
     */
    private function nearestGap(float $boundary, array $gaps): ?array
    {
        $best = null;
        $bestDist = PHP_FLOAT_MAX;
        foreach ($gaps as $gap) {
            $mid = ($gap['start'] + $gap['end']) / 2.0;
            $dist = abs($mid - $boundary);
            if ($dist <= self::HOUR_WINDOW_SECONDS && $dist < $bestDist) {
                $best = $gap;
                $bestDist = $dist;
            }
        }

        return $best;
    }

    /**
     * @param list<array{start: float, end: float, duration: float}> $gaps
     */
    private function inferDuration(array $gaps): float
    {
        $max = 0.0;
        foreach ($gaps as $gap) {
            $max = max($max, $gap['end']);
        }

        return $max;
    }
}
