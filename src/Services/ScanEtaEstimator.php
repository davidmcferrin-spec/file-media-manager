<?php

declare(strict_types=1);

namespace MediaManager\Services;

/**
 * File-count ETA / timing summary for scan jobs.
 *
 * @phpstan-type Timing array{
 *   started_label: string,
 *   ended_label: string,
 *   elapsed_seconds: ?int,
 *   elapsed_label: string,
 *   eta_seconds: ?int,
 *   eta_label: string,
 *   rate_label: string,
 *   pct: float
 * }
 */
final class ScanEtaEstimator
{
    /**
     * @param array<string, mixed> $job
     * @return Timing
     */
    public static function estimate(array $job): array
    {
        $status = (string) ($job['status'] ?? '');
        $processed = max(0, (int) ($job['processed_files'] ?? 0));
        $total = max(0, (int) ($job['total_files'] ?? 0));
        $pct = $total > 0 ? min(100.0, round(100.0 * $processed / $total, 1)) : 0.0;

        $startedTs = self::parseTs($job['started_at'] ?? null);
        $endedTs = self::parseTs($job['completed_at'] ?? null);

        $elapsedSeconds = null;
        if ($startedTs !== null) {
            $end = $endedTs ?? time();
            $elapsedSeconds = max(0, $end - $startedTs);
        }

        $etaSeconds = null;
        $rateLabel = '—';
        if (in_array($status, ['RUNNING', 'PENDING'], true) && $startedTs !== null && $processed >= 2 && $elapsedSeconds !== null && $elapsedSeconds > 0) {
            $remaining = max(0, $total - $processed);
            if ($remaining === 0) {
                $etaSeconds = 0;
                $rateLabel = 'finishing…';
            } else {
                $filesPerSec = $processed / $elapsedSeconds;
                if ($filesPerSec > 0.0001) {
                    $etaSeconds = (int) ceil($remaining / $filesPerSec);
                    $rateLabel = sprintf('%.1f files/min', $filesPerSec * 60.0);
                }
            }
        } elseif ($status === 'COMPLETED' || $status === 'CANCELLED' || $status === 'FAILED' || $status === 'PAUSED') {
            $rateLabel = $elapsedSeconds !== null && $processed > 0 && $elapsedSeconds > 0
                ? sprintf('%.1f files/min', ($processed / $elapsedSeconds) * 60.0)
                : '—';
        }

        return [
            'started_label'   => self::labelTs($job['started_at'] ?? null) ?: '—',
            'ended_label'     => self::labelTs($job['completed_at'] ?? null) ?: (
                in_array($status, ['RUNNING', 'PENDING'], true) ? '—' : '—'
            ),
            'elapsed_seconds' => $elapsedSeconds,
            'elapsed_label'   => $elapsedSeconds === null
                ? '—'
                : self::formatElapsed($elapsedSeconds),
            'eta_seconds'     => $etaSeconds,
            'eta_label'       => match (true) {
                $status === 'COMPLETED' => 'Done',
                $status === 'CANCELLED' => 'Stopped',
                $status === 'FAILED' => 'Failed',
                $status === 'PAUSED' => 'Paused',
                $etaSeconds !== null => ContinuityEtaEstimator::formatDuration($etaSeconds),
                default => ContinuityEtaEstimator::formatDuration(null),
            },
            'rate_label'      => $rateLabel,
            'pct'             => $status === 'COMPLETED' ? 100.0 : $pct,
        ];
    }

    public static function formatElapsed(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        $mins = intdiv($seconds, 60);
        $secs = $seconds % 60;
        if ($mins < 60) {
            return $secs > 0 ? $mins . 'm ' . $secs . 's' : $mins . 'm';
        }
        $hours = intdiv($mins, 60);
        $remMins = $mins % 60;

        return $remMins > 0 ? $hours . 'h ' . $remMins . 'm' : $hours . 'h';
    }

    private static function parseTs(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime((string) $value);

        return $ts !== false ? $ts : null;
    }

    private static function labelTs(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return substr((string) $value, 0, 19);
    }
}
