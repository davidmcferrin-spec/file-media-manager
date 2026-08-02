<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\BroadcastEraRepository;
use MediaManager\Repositories\BroadcastEraWindowRepository;
use MediaManager\Repositories\ProgramScheduleRepository;
use MediaManager\Repositories\ShowRepository;
use MediaManager\Support\ScheduleEntryParser;

/**
 * Network broadcast eras: on-air windows + adopting shows onto the Timeline.
 */
final class BroadcastEraService
{
    public function __construct(
        private readonly BroadcastEraRepository $eras = new BroadcastEraRepository(),
        private readonly BroadcastEraWindowRepository $windows = new BroadcastEraWindowRepository(),
        private readonly ProgramScheduleRepository $schedule = new ProgramScheduleRepository(),
        private readonly ShowRepository $shows = new ShowRepository(),
    ) {
    }

    /**
     * Coverage index for Completeness: date ISO => list of [days mask, startMin, endMin].
     *
     * @return array<string, list<array{days: int, start: int, end: int}>>
     */
    public function coverageIndex(string $fromIso, string $toIso): array
    {
        $eras = $this->eras->listOverlapping($fromIso, $toIso);
        if ($eras === []) {
            return [];
        }

        $eraIds = array_map(static fn (array $e): int => (int) $e['id'], $eras);
        $windows = $this->windows->listForEras($eraIds);
        $windowsByEra = [];
        foreach ($windows as $w) {
            $windowsByEra[(int) $w['era_id']][] = $w;
        }

        $index = [];
        $tz = new \DateTimeZone('America/New_York');
        $cursor = \DateTimeImmutable::createFromFormat('Y-m-d', $fromIso, $tz);
        $end = \DateTimeImmutable::createFromFormat('Y-m-d', $toIso, $tz);
        if ($cursor === false || $end === false) {
            return [];
        }

        while ($cursor <= $end) {
            $iso = $cursor->format('Y-m-d');
            $ranges = [];
            foreach ($eras as $era) {
                $effFrom = (string) $era['effective_from'];
                $effTo = $era['effective_to'] !== null ? (string) $era['effective_to'] : null;
                if ($iso < $effFrom || ($effTo !== null && $iso > $effTo)) {
                    continue;
                }
                foreach ($windowsByEra[(int) $era['id']] ?? [] as $w) {
                    $start = self::timeToMinutes((string) $w['hour_start_et']);
                    $endMin = self::timeToMinutes((string) $w['hour_end_et']);
                    if ($start === null || $endMin === null) {
                        continue;
                    }
                    if ($endMin === 0 && $start > 0) {
                        $endMin = 24 * 60;
                    }
                    if ($endMin <= $start) {
                        continue;
                    }
                    $ranges[] = [
                        'days'  => (int) $w['days_of_week'],
                        'start' => $start,
                        'end'   => $endMin,
                    ];
                }
            }
            if ($ranges !== []) {
                $index[$iso] = $ranges;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $index;
    }

    /**
     * True when no era covers the date, or the hour falls inside an era window for that weekday.
     *
     * @param array<string, list<array{days: int, start: int, end: int}>> $coverage
     */
    public static function hourAllowed(array $coverage, string $dateIso, int $hourMinutes, int $dayBit): bool
    {
        if (!isset($coverage[$dateIso])) {
            // No era defined for this date — keep legacy Timeline-only behavior.
            return true;
        }
        foreach ($coverage[$dateIso] as $range) {
            if (($range['days'] & $dayBit) === 0) {
                continue;
            }
            if ($hourMinutes >= $range['start'] && $hourMinutes < $range['end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *   in_era: list<array<string, mixed>>,
     *   adoptable: list<array<string, mixed>>,
     *   slots: list<array<string, mixed>>
     * }
     */
    public function eraShowMembership(int $eraId): array
    {
        $era = $this->eras->findById($eraId);
        if ($era === null) {
            return ['in_era' => [], 'adoptable' => [], 'slots' => []];
        }

        $from = (string) $era['effective_from'];
        $to = $era['effective_to'] !== null ? (string) $era['effective_to'] : null;
        $linkedIds = $this->schedule->showIdsForEra($eraId, $from, $to);
        $linkedSet = array_fill_keys($linkedIds, true);
        $slots = $this->schedule->listForEra($eraId, 500);

        $inEra = [];
        $adoptable = [];
        foreach ($this->shows->all(true) as $show) {
            $id = (int) $show['id'];
            if (isset($linkedSet[$id])) {
                $inEra[] = $show;
            } else {
                $adoptable[] = $show;
            }
        }

        return ['in_era' => $inEra, 'adoptable' => $adoptable, 'slots' => $slots];
    }

    /**
     * Create Timeline blocks for a show using the era's on-air windows.
     *
     * @param list<int>|null $windowIds null = all windows
     * @return int number of schedule rows created
     */
    public function adoptShow(int $eraId, int $showId, ?array $windowIds = null): int
    {
        $era = $this->eras->findById($eraId);
        $show = $this->shows->findById($showId);
        if ($era === null || $show === null) {
            throw new \InvalidArgumentException('Era or show not found.');
        }

        $windows = $this->windows->listForEra($eraId);
        if ($windowIds !== null) {
            $allow = array_fill_keys($windowIds, true);
            $windows = array_values(array_filter(
                $windows,
                static fn (array $w): bool => isset($allow[(int) $w['id']])
            ));
        }
        if ($windows === []) {
            throw new \InvalidArgumentException('Era has no broadcast windows to adopt.');
        }

        $from = (string) $era['effective_from'];
        $to = $era['effective_to'] !== null ? (string) $era['effective_to'] : null;
        $title = (string) $show['canonical_name'];
        $eraName = (string) $era['name'];
        $created = 0;

        foreach ($windows as $w) {
            $this->schedule->insert([
                'show_id'          => $showId,
                'title'            => $title,
                'hour_start_et'    => self::normalizeDbTime((string) $w['hour_start_et']),
                'hour_end_et'      => self::normalizeDbTime((string) $w['hour_end_et']),
                'days_of_week'     => (int) $w['days_of_week'],
                'effective_from'   => $from,
                'effective_to'     => $to,
                'era_name'         => $eraName,
                'broadcast_era_id' => $eraId,
                'active'           => true,
                'notes'            => 'Adopted from era: ' . $eraName,
            ]);
            $created++;
        }

        return $created;
    }

    private static function normalizeDbTime(string $raw): string
    {
        $parsed = ScheduleEntryParser::normalizeTime(substr($raw, 0, 5));
        if ($parsed !== null) {
            return $parsed;
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}/', $raw) === 1) {
            return substr($raw, 0, 8);
        }

        return '00:00:00';
    }

    private static function timeToMinutes(string $raw): ?int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})/', $raw, $m) !== 1) {
            return null;
        }

        return ((int) $m[1] * 60) + (int) $m[2];
    }
}
