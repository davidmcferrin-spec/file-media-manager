<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\CompletenessRepository;
use MediaManager\Repositories\ExpectedGapRepository;
use MediaManager\Repositories\ProgramScheduleRepository;

/**
 * Compare Timeline schedule expected hourly slots against Program/Clean inventory.
 */
final class CompletenessService
{
    public const MATCH_WINDOW_MINUTES = 20;
    public const ONE_PROGRAM_MAX_SECONDS = 5400;      // < 1.5h → one program
    public const AMBIGUOUS_MAX_SECONDS = 9000;        // < 2.5h → could be 1–2 (user judges)
    public const MULTI_PROGRAM_SECONDS = 9000;        // ≥ 2.5h → multi-program candidate

    public function __construct(
        private readonly ProgramScheduleRepository $schedule = new ProgramScheduleRepository(),
        private readonly CompletenessRepository $files = new CompletenessRepository(),
        private readonly ExpectedGapRepository $gaps = new ExpectedGapRepository(),
        private readonly ScheduleLookupService $lookup = new ScheduleLookupService(),
    ) {
    }

    /**
     * @return array{
     *   from_ymd: string,
     *   to_ymd: string,
     *   mode: string,
     *   grain: string,
     *   metrics: array<string, int>,
     *   slots: list<array<string, mixed>>,
     *   duplicates: list<array<string, mixed>>,
     *   expected_gaps: list<array<string, mixed>>,
     *   show_rollups: list<array<string, mixed>>,
     *   day_rollups: list<array<string, mixed>>,
     *   unmatched_count: int
     * }
     */
    public function audit(
        string $fromYmd,
        string $toYmd,
        string $mode = 'either',
        string $grain = 'hourly',
        ?int $showId = null,
        ?string $statusFilter = null
    ): array {
        $mode = in_array($mode, ['program', 'clean', 'either', 'both'], true) ? $mode : 'either';
        $grain = in_array($grain, ['hourly', 'daily', 'show'], true) ? $grain : 'hourly';

        $fromIso = self::ymdToIso($fromYmd);
        $toIso = self::ymdToIso($toYmd);
        if ($fromIso === null || $toIso === null) {
            throw new \InvalidArgumentException('Invalid date range.');
        }

        $expected = $this->expandExpectedSlots($fromYmd, $toYmd, $showId);
        $evidence = $this->files->listEvidence($fromYmd, $toYmd, $showId);
        $gapRows = $this->gaps->listForRange($fromIso, $toIso, $showId);
        $gapIndex = $this->indexExpectedGaps($gapRows);

        $coverage = $this->buildCoverageIndex($evidence);
        $allSlots = [];
        $duplicates = [];

        foreach ($expected as $slot) {
            $keyBase = self::slotKey((int) $slot['show_id'], (string) $slot['air_date'], (int) $slot['hour_minutes']);
            $programFiles = $coverage[$keyBase . '|program'] ?? [];
            $cleanFiles = $coverage[$keyBase . '|clean'] ?? [];

            $programStatus = $this->laneStatus($programFiles, $gapIndex[$keyBase . '|program'] ?? $gapIndex[$keyBase . '|both'] ?? null);
            $cleanStatus = $this->laneStatus($cleanFiles, $gapIndex[$keyBase . '|clean'] ?? $gapIndex[$keyBase . '|both'] ?? null);

            $rollStatus = $this->rollStatus($programStatus, $cleanStatus, $mode);
            $row = [
                'show_id'         => (int) $slot['show_id'],
                'show_abbr'       => (string) $slot['show_abbr'],
                'show_name'       => (string) $slot['show_name'],
                'title'           => (string) $slot['title'],
                'air_date'        => (string) $slot['air_date'],
                'air_date_iso'    => (string) $slot['air_date_iso'],
                'hour_minutes'    => (int) $slot['hour_minutes'],
                'hour_label'      => DateNormalizer::minutesToHhmm((int) $slot['hour_minutes']),
                'hour_start_et'   => ScheduleTimeParser::minutesToTime((int) $slot['hour_minutes']),
                'program_status'  => $programStatus['status'],
                'clean_status'    => $cleanStatus['status'],
                'program_note'    => $programStatus['note'],
                'clean_note'      => $cleanStatus['note'],
                'program_files'   => $programFiles,
                'clean_files'     => $cleanFiles,
                'status'          => $rollStatus['status'],
                'note'            => $rollStatus['note'],
                'expected_gap'    => $rollStatus['status'] === 'expected_gap',
                'gap_reason'      => $rollStatus['gap_reason'],
            ];

            $allSlots[] = $row;

            if (count($programFiles) > 1) {
                $duplicates[] = $this->duplicateGroup($row, 'program', $programFiles);
            }
            if (count($cleanFiles) > 1) {
                $duplicates[] = $this->duplicateGroup($row, 'clean', $cleanFiles);
            }
        }

        $metrics = $this->computeMetrics($allSlots);
        $metrics['unmatched'] = $this->files->countUnmatched();
        $metrics['expected_slots'] = count($expected);
        $metrics['open_ended_schedule'] = $this->schedule->countOpenEnded();

        $slots = $allSlots;
        if ($statusFilter !== null && $statusFilter !== '') {
            $slots = array_values(array_filter(
                $allSlots,
                static fn (array $row): bool => $row['status'] === $statusFilter
            ));
        }

        return [
            'from_ymd'       => $fromYmd,
            'to_ymd'         => $toYmd,
            'mode'           => $mode,
            'grain'          => $grain,
            'metrics'        => $metrics,
            'slots'          => $slots,
            'duplicates'     => $duplicates,
            'expected_gaps'  => $gapRows,
            'show_rollups'   => $this->rollupByShow($allSlots),
            'day_rollups'    => $this->rollupByDay($allSlots),
            'unmatched_count'=> $metrics['unmatched'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function expandExpectedSlots(string $fromYmd, string $toYmd, ?int $showId = null): array
    {
        $fromIso = self::ymdToIso($fromYmd);
        $toIso = self::ymdToIso($toYmd);
        if ($fromIso === null || $toIso === null) {
            return [];
        }

        $entries = $this->schedule->listOverlapping($fromIso, $toIso, $showId);
        $slots = [];
        $tz = new \DateTimeZone('America/New_York');
        $cursor = \DateTimeImmutable::createFromFormat('Y-m-d', $fromIso, $tz);
        $end = \DateTimeImmutable::createFromFormat('Y-m-d', $toIso, $tz);
        if ($cursor === false || $end === false) {
            return [];
        }

        while ($cursor <= $end) {
            $ymd = $cursor->format('Ymd');
            $iso = $cursor->format('Y-m-d');
            $dayBit = ScheduleTimeParser::dayBitFromDate($ymd);

            foreach ($entries as $entry) {
                $effFrom = (string) $entry['effective_from'];
                $effTo = $entry['effective_to'] !== null ? (string) $entry['effective_to'] : null;
                if ($iso < $effFrom || ($effTo !== null && $iso > $effTo)) {
                    continue;
                }
                if (((int) $entry['days_of_week'] & $dayBit) === 0) {
                    continue;
                }

                $startMin = self::timeStringToMinutes((string) $entry['hour_start_et']);
                $endMin = self::timeStringToMinutes((string) $entry['hour_end_et']);
                if ($startMin === null || $endMin === null) {
                    continue;
                }
                if ($endMin === 0 && $startMin > 0) {
                    $endMin = 24 * 60;
                }
                if ($endMin <= $startMin) {
                    continue;
                }

                $hour = (int) (floor($startMin / 60) * 60);
                while ($hour < $endMin) {
                    $slots[] = [
                        'show_id'       => (int) $entry['show_id'],
                        'show_abbr'     => (string) $entry['show_abbr'],
                        'show_name'     => (string) $entry['show_name'],
                        'title'         => (string) $entry['title'],
                        'air_date'      => $ymd,
                        'air_date_iso'  => $iso,
                        'hour_minutes'  => $hour,
                        'schedule_id'   => (int) $entry['id'],
                    ];
                    $hour += 60;
                }
            }

            $cursor = $cursor->modify('+1 day');
        }

        usort($slots, static function (array $a, array $b): int {
            return [$a['air_date'], $a['hour_minutes'], $a['show_abbr']]
                <=> [$b['air_date'], $b['hour_minutes'], $b['show_abbr']];
        });

        return $slots;
    }

    /**
     * @param list<array<string, mixed>> $evidence
     * @return array<string, list<array<string, mixed>>>
     */
    private function buildCoverageIndex(array $evidence): array
    {
        $index = [];

        foreach ($evidence as $file) {
            $lane = strtolower((string) ($file['media_type_abbr'] ?? ''));
            if ($lane !== 'program' && $lane !== 'clean') {
                continue;
            }

            $covered = $this->coveredSlotsForFile($file);
            foreach ($covered as $cov) {
                $key = self::slotKey((int) $cov['show_id'], (string) $cov['air_date'], (int) $cov['hour_minutes'])
                    . '|' . $lane;
                $payload = $file;
                $payload['coverage_note'] = $cov['note'];
                $payload['coverage_kind'] = $cov['kind'];
                $index[$key][] = $payload;
            }
        }

        return $index;
    }

    /**
     * @param array<string, mixed> $file
     * @return list<array{show_id: int, air_date: string, hour_minutes: int, kind: string, note: string}>
     */
    private function coveredSlotsForFile(array $file): array
    {
        $date = (string) ($file['file_date'] ?? '');
        $time = (string) ($file['file_time'] ?? '');
        $showId = (int) ($file['show_id'] ?? 0);
        $startMin = DateNormalizer::timeToMinutes($time);
        if (!DateNormalizer::isValidDate($date) || $startMin === null || $showId <= 0) {
            return [];
        }

        $duration = isset($file['duration_seconds']) ? (float) $file['duration_seconds'] : 0.0;
        $needsSplitFlag = !empty($file['needs_split']);
        $primaryHour = (int) (floor($startMin / 60) * 60);
        // Snap to nearest hour within ±20 minutes
        $deltaToHour = $startMin - $primaryHour;
        if ($deltaToHour > 30 && ($startMin - ($primaryHour + 60)) >= -self::MATCH_WINDOW_MINUTES) {
            $primaryHour += 60;
        } elseif (abs($startMin - $primaryHour) > self::MATCH_WINDOW_MINUTES
            && abs($startMin - ($primaryHour + 60)) <= self::MATCH_WINDOW_MINUTES) {
            $primaryHour += 60;
        }

        // Reject if outside ±20 of any hour boundary for short files handled below
        $nearestHour = (int) (round($startMin / 60) * 60);
        if ($nearestHour >= 24 * 60) {
            $nearestHour = 23 * 60;
        }

        if ($duration <= 0 || $duration < self::ONE_PROGRAM_MAX_SECONDS) {
            if (abs($startMin - $nearestHour) > self::MATCH_WINDOW_MINUTES) {
                // Still credit file's show at floored/nearest hour if within window of primaryHour
                if (abs($startMin - $primaryHour) > self::MATCH_WINDOW_MINUTES) {
                    return [];
                }
                $nearestHour = $primaryHour;
            }

            $kind = $needsSplitFlag ? 'needs_split' : 'confirmed';
            $note = $needsSplitFlag ? 'Present — flagged needs split' : 'Matched within ±20 min';

            return [[
                'show_id'      => $showId,
                'air_date'     => $date,
                'hour_minutes' => $nearestHour,
                'kind'         => $kind,
                'note'         => $note,
            ]];
        }

        if ($duration < self::AMBIGUOUS_MAX_SECONDS) {
            // 1.5h–<2.5h: one program by default; schedule may suggest a second — user judges
            $slots = [[
                'show_id'      => $showId,
                'air_date'     => $date,
                'hour_minutes' => $nearestHour,
                'kind'         => 'needs_review',
                'note'         => 'Duration 1.5–2.5h — could be 1 or 2 programs; user must evaluate',
            ]];

            $endMin = $startMin + (int) ceil($duration / 60);
            $cursor = $nearestHour + 60;
            while ($cursor < $endMin) {
                $hhmm = DateNormalizer::minutesToHhmm($cursor);
                $match = $this->lookup->match($date, $hhmm);
                if ($match !== null) {
                    $slots[] = [
                        'show_id'      => (int) $match['show_id'],
                        'air_date'     => $date,
                        'hour_minutes' => $cursor,
                        'kind'         => 'needs_review',
                        'note'         => 'Possible second program in long take — verify content',
                    ];
                }
                $cursor += 60;
            }

            return $slots;
        }

        // ≥ 2.5h: expand across schedule hours for the duration span
        $slots = [];
        $endMin = $startMin + (int) ceil($duration / 60);
        $cursor = (int) (floor($startMin / 60) * 60);
        if (abs($startMin - $cursor) > self::MATCH_WINDOW_MINUTES
            && abs($startMin - ($cursor + 60)) <= self::MATCH_WINDOW_MINUTES) {
            $cursor += 60;
        }

        while ($cursor < $endMin) {
            $hhmm = DateNormalizer::minutesToHhmm($cursor);
            $match = $this->lookup->match($date, $hhmm);
            $slotShow = $match['show_id'] ?? $showId;
            $slots[] = [
                'show_id'      => (int) $slotShow,
                'air_date'     => $date,
                'hour_minutes' => $cursor,
                'kind'         => 'needs_split',
                'note'         => 'Present in multi-hour file (≥2.5h) — needs split',
            ];
            $cursor += 60;
        }

        return $slots;
    }

    /**
     * @param list<array<string, mixed>> $files
     * @param array<string, mixed>|null $gap
     * @return array{status: string, note: string, gap_reason: string}
     */
    private function laneStatus(array $files, ?array $gap): array
    {
        if ($gap !== null) {
            return [
                'status'     => 'expected_gap',
                'note'       => (string) ($gap['reason'] ?? 'Expected gap'),
                'gap_reason' => (string) ($gap['reason'] ?? ''),
            ];
        }

        if ($files === []) {
            return ['status' => 'missing', 'note' => 'No recording found', 'gap_reason' => ''];
        }

        if (count($files) > 1) {
            return [
                'status'     => 'duplicate',
                'note'       => count($files) . ' files for this slot',
                'gap_reason' => '',
            ];
        }

        $file = $files[0];
        $kind = (string) ($file['coverage_kind'] ?? 'confirmed');
        $note = (string) ($file['coverage_note'] ?? '');

        return match ($kind) {
            'needs_split' => [
                'status'     => 'needs_split',
                'note'       => $note !== '' ? $note : 'Present — needs split',
                'gap_reason' => '',
            ],
            'needs_review' => [
                'status'     => 'needs_review',
                'note'       => $note !== '' ? $note : 'Present — user must evaluate duration',
                'gap_reason' => '',
            ],
            default => [
                'status'     => !empty($file['needs_split']) ? 'needs_split' : 'confirmed',
                'note'       => !empty($file['needs_split'])
                    ? 'Present — flagged needs split'
                    : ($note !== '' ? $note : 'Confirmed'),
                'gap_reason' => '',
            ],
        };
    }

    /**
     * @param array{status: string, note: string, gap_reason: string} $program
     * @param array{status: string, note: string, gap_reason: string} $clean
     * @return array{status: string, note: string, gap_reason: string}
     */
    private function rollStatus(array $program, array $clean, string $mode): array
    {
        return match ($mode) {
            'program' => $program,
            'clean'   => $clean,
            'both'    => $this->combineBoth($program, $clean),
            default   => $this->combineEither($program, $clean),
        };
    }

    /**
     * @param array{status: string, note: string, gap_reason: string} $program
     * @param array{status: string, note: string, gap_reason: string} $clean
     * @return array{status: string, note: string, gap_reason: string}
     */
    private function combineEither(array $program, array $clean): array
    {
        $rank = self::statusRank();
        $best = $rank[$program['status']] <= $rank[$clean['status']] ? $program : $clean;

        // Prefer any present-ish over missing; expected_gap only if both are
        if ($program['status'] === 'expected_gap' && $clean['status'] === 'expected_gap') {
            return $program;
        }
        if ($program['status'] === 'expected_gap') {
            return $clean['status'] === 'missing' ? $program : $clean;
        }
        if ($clean['status'] === 'expected_gap') {
            return $program['status'] === 'missing' ? $clean : $program;
        }

        return $best;
    }

    /**
     * @param array{status: string, note: string, gap_reason: string} $program
     * @param array{status: string, note: string, gap_reason: string} $clean
     * @return array{status: string, note: string, gap_reason: string}
     */
    private function combineBoth(array $program, array $clean): array
    {
        if ($program['status'] === 'expected_gap' && $clean['status'] === 'expected_gap') {
            return $program;
        }
        if ($program['status'] === 'expected_gap') {
            return $clean;
        }
        if ($clean['status'] === 'expected_gap') {
            return $program;
        }

        $present = static fn (string $s): bool => !in_array($s, ['missing', 'expected_gap'], true);

        if (!$present($program['status']) || !$present($clean['status'])) {
            if ($program['status'] === 'missing' || $clean['status'] === 'missing') {
                return [
                    'status'     => 'missing',
                    'note'       => 'Need both Program and Clean — '
                        . 'P:' . $program['status'] . ' / C:' . $clean['status'],
                    'gap_reason' => '',
                ];
            }
        }

        $rank = self::statusRank();
        // Worst of the two present statuses (duplicate/needs_* beats confirmed)
        return $rank[$program['status']] >= $rank[$clean['status']] ? $program : $clean;
    }

    /** @return array<string, int> lower = better for either-mode */
    private static function statusRank(): array
    {
        return [
            'confirmed'     => 0,
            'needs_split'   => 1,
            'needs_review'  => 2,
            'duplicate'     => 3,
            'expected_gap'  => 4,
            'missing'       => 5,
        ];
    }

    /**
     * @param list<array<string, mixed>> $slots
     * @return array<string, int>
     */
    private function computeMetrics(array $slots): array
    {
        $metrics = [
            'confirmed'    => 0,
            'needs_split'  => 0,
            'needs_review' => 0,
            'duplicate'    => 0,
            'missing'      => 0,
            'expected_gap' => 0,
            'filled'       => 0,
            'unfilled'     => 0,
        ];

        foreach ($slots as $slot) {
            $status = (string) $slot['status'];
            if (isset($metrics[$status])) {
                $metrics[$status]++;
            }
            if (in_array($status, ['confirmed', 'needs_split', 'needs_review', 'duplicate'], true)) {
                $metrics['filled']++;
            }
            if ($status === 'missing') {
                $metrics['unfilled']++;
            }
        }

        return $metrics;
    }

    /**
     * @param list<array<string, mixed>> $gapRows
     * @return array<string, array<string, mixed>>
     */
    private function indexExpectedGaps(array $gapRows): array
    {
        $index = [];
        foreach ($gapRows as $gap) {
            $iso = (string) $gap['air_date'];
            $ymd = str_replace('-', '', substr($iso, 0, 10));
            $hourMin = self::timeStringToMinutes((string) $gap['hour_start_et']);
            if ($hourMin === null) {
                continue;
            }
            $lane = (string) $gap['media_lane'];
            $key = self::slotKey((int) $gap['show_id'], $ymd, $hourMin) . '|' . $lane;
            $index[$key] = $gap;
        }

        return $index;
    }

    /**
     * @param array<string, mixed> $slot
     * @param list<array<string, mixed>> $files
     * @return array<string, mixed>
     */
    private function duplicateGroup(array $slot, string $lane, array $files): array
    {
        return [
            'show_id'      => $slot['show_id'],
            'show_abbr'    => $slot['show_abbr'],
            'show_name'    => $slot['show_name'],
            'air_date'     => $slot['air_date'],
            'air_date_iso' => $slot['air_date_iso'],
            'hour_label'   => $slot['hour_label'],
            'lane'         => $lane,
            'files'        => $files,
        ];
    }

    /**
     * @param list<array<string, mixed>> $slots
     * @return list<array<string, mixed>>
     */
    private function rollupByShow(array $slots): array
    {
        $map = [];
        foreach ($slots as $slot) {
            $id = (int) $slot['show_id'];
            if (!isset($map[$id])) {
                $map[$id] = [
                    'show_id'      => $id,
                    'show_abbr'    => $slot['show_abbr'],
                    'show_name'    => $slot['show_name'],
                    'expected'     => 0,
                    'confirmed'    => 0,
                    'needs_split'  => 0,
                    'needs_review' => 0,
                    'duplicate'    => 0,
                    'missing'      => 0,
                    'expected_gap' => 0,
                    'filled'       => 0,
                    'unfilled'     => 0,
                ];
            }
            $map[$id]['expected']++;
            $status = (string) $slot['status'];
            if (isset($map[$id][$status])) {
                $map[$id][$status]++;
            }
            if (in_array($status, ['confirmed', 'needs_split', 'needs_review', 'duplicate'], true)) {
                $map[$id]['filled']++;
            }
            if ($status === 'missing') {
                $map[$id]['unfilled']++;
            }
        }

        $list = array_values($map);
        usort($list, static fn (array $a, array $b): int => $b['unfilled'] <=> $a['unfilled']);

        return $list;
    }

    /**
     * @param list<array<string, mixed>> $slots
     * @return list<array<string, mixed>>
     */
    private function rollupByDay(array $slots): array
    {
        $map = [];
        foreach ($slots as $slot) {
            $day = (string) $slot['air_date'];
            if (!isset($map[$day])) {
                $map[$day] = [
                    'air_date'     => $day,
                    'air_date_iso' => $slot['air_date_iso'],
                    'expected'     => 0,
                    'confirmed'    => 0,
                    'needs_split'  => 0,
                    'needs_review' => 0,
                    'duplicate'    => 0,
                    'missing'      => 0,
                    'expected_gap' => 0,
                    'filled'       => 0,
                    'unfilled'     => 0,
                ];
            }
            $map[$day]['expected']++;
            $status = (string) $slot['status'];
            if (isset($map[$day][$status])) {
                $map[$day][$status]++;
            }
            if (in_array($status, ['confirmed', 'needs_split', 'needs_review', 'duplicate'], true)) {
                $map[$day]['filled']++;
            }
            if ($status === 'missing') {
                $map[$day]['unfilled']++;
            }
        }

        ksort($map);

        return array_values($map);
    }

    private static function slotKey(int $showId, string $airDate, int $hourMinutes): string
    {
        return $showId . '|' . $airDate . '|' . $hourMinutes;
    }

    private static function ymdToIso(string $ymd): ?string
    {
        if (!DateNormalizer::isValidDate($ymd)) {
            return null;
        }

        return substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
    }

    private static function timeStringToMinutes(string $time): ?int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?/', $time, $m) === 1) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
                return $h * 60 + $min;
            }
        }

        return DateNormalizer::timeToMinutes($time);
    }
}
