<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\ProgramScheduleRepository;

final class ScheduleLookupService
{
    public function __construct(
        private readonly ProgramScheduleRepository $schedule = new ProgramScheduleRepository(),
    ) {
    }

    /**
     * @return array{show_id: int, show_abbr: string, title: string, signal: string, confidence: string}|null
     */
    public function match(?string $dateYmd, ?string $timeHhmm): ?array
    {
        if ($dateYmd === null || $timeHhmm === null || strlen($timeHhmm) < 4) {
            return null;
        }

        $minutes = DateNormalizer::normalizeTime($timeHhmm);
        if ($minutes === null) {
            return null;
        }

        $dayBit = ScheduleTimeParser::dayBitFromDate($dateYmd);
        if ($dayBit === 0) {
            return null;
        }

        $rows = $this->schedule->matchAt($dateYmd, (int) $minutes, $dayBit);
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $hour = substr((string) $row['hour_start_et'], 0, 5);

        return [
            'show_id'    => (int) $row['show_id'],
            'show_abbr'  => (string) $row['show_abbr'],
            'title'      => (string) $row['title'],
            'signal'     => 'schedule:' . $row['title'] . ' @ ' . $hour . ' ET',
            'confidence' => count($rows) > 1 ? 'LOW' : 'MEDIUM',
        ];
    }
}
