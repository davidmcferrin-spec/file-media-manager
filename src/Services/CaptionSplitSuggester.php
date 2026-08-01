<?php

declare(strict_types=1);

namespace MediaManager\Services;

/**
 * Suggest split in/out from caption silence gaps (≥5 minutes to avoid commercial breaks).
 */
final class CaptionSplitSuggester
{
    /** Minimum no-caption gap treated as a split/trim candidate (commercials are shorter). */
    public const MIN_GAP_SECONDS = 300.0;

    /** How close a long gap must be to a schedule hour boundary to refine that cut. */
    public const HOUR_WINDOW_SECONDS = 180.0;

    public function __construct(
        private readonly int $flagThresholdSeconds = ScheduleSplitSuggester::DEFAULT_FLAG_THRESHOLD_SECONDS,
    ) {
    }

    /**
     * @param list<array{start: float, end: float, text?: string}> $cues
     * @return array{
     *   segments: list<array{start: float, end: float, show_id: ?int, label: string, confidence: string, note: string}>,
     *   notes: string,
     *   gap_count: int
     * }
     */
    public function suggest(
        array $cues,
        ?float $durationSeconds,
        ?string $dateYmd = null,
        ?string $timeHhmm = null,
    ): array {
        if ($cues === []) {
            return [
                'segments'  => [],
                'notes'     => 'No caption cues available.',
                'gap_count' => 0,
            ];
        }

        usort($cues, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $firstStart = (float) $cues[0]['start'];
        $lastEnd = (float) $cues[array_key_last($cues)]['end'];
        $duration = $durationSeconds !== null && $durationSeconds > 0
            ? $durationSeconds
            : $lastEnd;

        $gaps = $this->findLongGaps($cues, $duration);
        $schedule = null;
        if ($dateYmd !== null && $timeHhmm !== null && $duration > 0) {
            $threshold = $this->flagThresholdSeconds >= 1
                ? $this->flagThresholdSeconds
                : ScheduleSplitSuggester::DEFAULT_FLAG_THRESHOLD_SECONDS;
            $schedule = (new ScheduleSplitSuggester(flagThresholdSeconds: $threshold))
                ->suggest($dateYmd, $timeHhmm, $duration);
        }

        $segments = [];
        if ($schedule !== null && !empty($schedule['segments'])) {
            $segments = $this->refineScheduleSegments($schedule['segments'], $gaps, $cues, $duration);
        } else {
            $segments = $this->segmentsFromContentBlocks($cues, $gaps, $duration);
        }

        $gapCount = count($gaps);
        $lines = [
            sprintf(
                'Caption-based split suggestion (%d gap(s) ≥ %d min):',
                $gapCount,
                (int) (self::MIN_GAP_SECONDS / 60)
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

        return [
            'segments'  => $segments,
            'notes'     => implode("\n", $lines),
            'gap_count' => $gapCount,
        ];
    }

    /**
     * @param list<array{start: float, end: float}> $cues
     * @return list<array{start: float, end: float, duration: float}>
     */
    private function findLongGaps(array $cues, float $duration): array
    {
        $gaps = [];
        $prevEnd = 0.0;
        foreach ($cues as $cue) {
            $gapStart = $prevEnd;
            $gapEnd = (float) $cue['start'];
            $len = $gapEnd - $gapStart;
            if ($len >= self::MIN_GAP_SECONDS) {
                $gaps[] = ['start' => $gapStart, 'end' => $gapEnd, 'duration' => $len];
            }
            $prevEnd = max($prevEnd, (float) $cue['end']);
        }
        $tail = $duration - $prevEnd;
        if ($tail >= self::MIN_GAP_SECONDS) {
            $gaps[] = ['start' => $prevEnd, 'end' => $duration, 'duration' => $tail];
        }

        return $gaps;
    }

    /**
     * @param list<array{start_offset: float, end_offset: float, show_id: ?int, show_abbr: ?string, title: string}> $scheduleSegs
     * @param list<array{start: float, end: float, duration: float}> $gaps
     * @param list<array{start: float, end: float}> $cues
     * @return list<array{start: float, end: float, show_id: ?int, label: string, confidence: string, note: string}>
     */
    private function refineScheduleSegments(
        array $scheduleSegs,
        array $gaps,
        array $cues,
        float $duration,
    ): array {
        $out = [];
        $count = count($scheduleSegs);

        for ($i = 0; $i < $count; $i++) {
            $seg = $scheduleSegs[$i];
            $start = (float) $seg['start_offset'];
            $end = (float) $seg['end_offset'];
            $notes = [];
            $confidence = 'schedule';

            // Trim leading silence into this hour block.
            if ($i === 0) {
                $lead = $this->gapCovering(0.0, $gaps);
                if ($lead !== null && $lead['duration'] >= self::MIN_GAP_SECONDS) {
                    $start = min($end - 1.0, $lead['end']);
                    $notes[] = sprintf('trimmed leading silence %.0fs', $lead['duration']);
                    $confidence = 'caption';
                }
            }

            // Refine cut at end of this block using a long gap near the hour boundary.
            if ($i < $count - 1) {
                $boundary = (float) $seg['end_offset'];
                $gap = $this->nearestGap($boundary, $gaps);
                if ($gap !== null) {
                    $end = max($start + 1.0, $gap['start']);
                    $notes[] = sprintf(
                        'cut at caption gap %s–%s (%.0f min silence near hour)',
                        SrtCaptionParser::secondsToTimecode($gap['start']),
                        SrtCaptionParser::secondsToTimecode($gap['end']),
                        $gap['duration'] / 60.0
                    );
                    $confidence = 'caption';
                }
            } else {
                $tail = $this->gapCovering($duration, $gaps) ?? $this->nearestGap($duration, $gaps);
                if ($tail !== null && $tail['end'] >= $duration - 1.0 && $tail['duration'] >= self::MIN_GAP_SECONDS) {
                    $end = max($start + 1.0, $tail['start']);
                    $notes[] = sprintf('trimmed trailing silence %.0fs', $tail['duration']);
                    $confidence = 'caption';
                }
            }

            // Snap start to first cue inside the window when possible.
            $firstCue = $this->firstCueInRange($cues, $start, $end);
            if ($firstCue !== null && $firstCue > $start + 1.0) {
                $start = $firstCue;
            }

            $label = (string) ($seg['show_abbr'] ?? $seg['title'] ?? 'Segment');
            $out[] = [
                'start'      => round($start, 1),
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
     * @param list<array{start: float, end: float}> $cues
     * @param list<array{start: float, end: float, duration: float}> $gaps
     * @return list<array{start: float, end: float, show_id: ?int, label: string, confidence: string, note: string}>
     */
    private function segmentsFromContentBlocks(array $cues, array $gaps, float $duration): array
    {
        if ($gaps === []) {
            $start = (float) $cues[0]['start'];
            $end = (float) $cues[array_key_last($cues)]['end'];

            return [[
                'start'      => round($start, 1),
                'end'        => round(min($end, $duration), 1),
                'show_id'    => null,
                'label'      => 'Content',
                'confidence' => 'caption',
                'note'       => 'No ≥5 min caption gaps; single content block',
            ]];
        }

        $blocks = [];
        $cursor = (float) $cues[0]['start'];
        foreach ($gaps as $gap) {
            if ($gap['start'] > $cursor + 1.0) {
                $blocks[] = [$cursor, $gap['start']];
            }
            $cursor = max($cursor, $gap['end']);
        }
        $lastEnd = (float) $cues[array_key_last($cues)]['end'];
        if ($lastEnd > $cursor + 1.0) {
            $blocks[] = [$cursor, $lastEnd];
        }

        $out = [];
        $i = 1;
        foreach ($blocks as [$start, $end]) {
            $out[] = [
                'start'      => round($start, 1),
                'end'        => round(min($end, $duration), 1),
                'show_id'    => null,
                'label'      => 'Part ' . $i,
                'confidence' => 'caption',
                'note'       => 'Separated by ≥5 min caption silence',
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
     * @return array{start: float, end: float, duration: float}|null
     */
    private function gapCovering(float $point, array $gaps): ?array
    {
        foreach ($gaps as $gap) {
            if ($point >= $gap['start'] - 0.5 && $point <= $gap['end'] + 0.5) {
                return $gap;
            }
        }

        return null;
    }

    /**
     * @param list<array{start: float, end: float}> $cues
     */
    private function firstCueInRange(array $cues, float $start, float $end): ?float
    {
        foreach ($cues as $cue) {
            $s = (float) $cue['start'];
            if ($s >= $start && $s < $end) {
                return $s;
            }
        }

        return null;
    }
}
