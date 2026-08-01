<?php

declare(strict_types=1);

namespace MediaManager\Services;

/**
 * Duration-weighted ETA for caption extract jobs.
 * Longer media files are expected to take longer to extract.
 */
final class CaptionExtractEtaEstimator
{
    /** Fallback average when a file has no stored duration (seconds). */
    public const DEFAULT_DURATION_SECONDS = 3600.0;

    /** Seconds on current file before UI treats it as possibly hung. */
    public const HANG_WARN_SECONDS = 1200;

    /**
     * @param array<string, mixed> $job
     * @return array{
     *   pct: float,
     *   remaining_files: int,
     *   remaining_duration: float,
     *   eta_seconds: ?int,
     *   eta_label: string,
     *   rate_label: string,
     *   method: string,
     *   hang_seconds: ?int,
     *   hang_warning: bool
     * }
     */
    public static function estimate(array $job): array
    {
        $processed = max(0, (int) ($job['processed_files'] ?? 0));
        $total = max(0, (int) ($job['total_files'] ?? 0));
        $remainingFiles = $total > 0 ? max(0, $total - $processed) : 0;
        $pct = $total > 0 ? min(100.0, round(100.0 * $processed / $total, 1)) : 0.0;

        $totalDur = (float) ($job['total_duration_seconds'] ?? 0);
        $doneDur = (float) ($job['processed_duration_seconds'] ?? 0);
        $remainingDur = max(0.0, $totalDur - $doneDur);

        // If totals under-count duration (many null durations), pad remaining by average.
        if ($total > 0 && $remainingFiles > 0) {
            $avg = $totalDur > 0
                ? max(self::DEFAULT_DURATION_SECONDS * 0.25, $totalDur / $total)
                : self::DEFAULT_DURATION_SECONDS;
            $impliedRemaining = $remainingFiles * $avg;
            if ($remainingDur < $impliedRemaining * 0.5) {
                $remainingDur = $impliedRemaining;
            }
        }

        $hangSeconds = null;
        $hangWarning = false;
        if (($job['status'] ?? '') === 'RUNNING' && !empty($job['current_started_at'])) {
            $started = strtotime((string) $job['current_started_at']);
            if ($started !== false) {
                $hangSeconds = max(0, time() - $started);
                $hangWarning = $hangSeconds >= self::HANG_WARN_SECONDS;
            }
        }

        $status = (string) ($job['status'] ?? '');
        if (!in_array($status, ['RUNNING', 'PENDING'], true) || $remainingFiles === 0) {
            return [
                'pct'                => $status === 'COMPLETED' ? 100.0 : $pct,
                'remaining_files'    => $remainingFiles,
                'remaining_duration' => $remainingDur,
                'eta_seconds'        => $status === 'RUNNING' && $remainingFiles === 0 ? 0 : null,
                'eta_label'          => $status === 'COMPLETED' ? 'Done' : (
                    $status === 'RUNNING' && $remainingFiles === 0 ? 'Finishing…' : ContinuityEtaEstimator::formatDuration(null)
                ),
                'rate_label'         => '—',
                'method'             => 'idle',
                'hang_seconds'       => $hangSeconds,
                'hang_warning'       => $hangWarning,
            ];
        }

        $etaSeconds = null;
        $method = 'bootstrap';
        $rateLabel = 'warming up…';

        $jobStarted = !empty($job['started_at']) ? strtotime((string) $job['started_at']) : false;
        $elapsed = ($jobStarted !== false) ? max(1, time() - $jobStarted) : 0;

        if ($processed >= 2 && $doneDur > 0 && $elapsed > 0) {
            // Seconds of media processed per wall-clock second.
            $mediaPerWall = $doneDur / $elapsed;
            if ($mediaPerWall > 0.0001) {
                $etaSeconds = (int) ceil($remainingDur / $mediaPerWall);
                $method = 'duration';
                $rateLabel = sprintf(
                    '%.1fx realtime (%s media / %s wall)',
                    $mediaPerWall,
                    ContinuityEtaEstimator::formatDuration((int) round($doneDur)),
                    ContinuityEtaEstimator::formatDuration($elapsed)
                );
                // Strip leading ~ from formatDuration for nested use — keep simple:
                $rateLabel = sprintf('%.2f media-sec / wall-sec', $mediaPerWall);
            }
        } elseif ($processed >= 2 && $elapsed > 0) {
            $filesPerSec = $processed / $elapsed;
            if ($filesPerSec > 0.0001) {
                $etaSeconds = (int) ceil($remainingFiles / $filesPerSec);
                $method = 'count';
                $rateLabel = sprintf('%.2f files/min', $filesPerSec * 60.0);
            }
        } else {
            // Bootstrap: assume extract ~0.05–0.15× realtime for subtitle demux; use 0.08.
            $assumed = 0.08;
            $etaSeconds = (int) ceil($remainingDur * $assumed);
            $method = 'bootstrap';
            $rateLabel = 'estimate (0.08× media duration)';
        }

        return [
            'pct'                => $pct,
            'remaining_files'    => $remainingFiles,
            'remaining_duration' => $remainingDur,
            'eta_seconds'        => $etaSeconds,
            'eta_label'          => ContinuityEtaEstimator::formatDuration($etaSeconds),
            'rate_label'         => $rateLabel,
            'method'             => $method,
            'hang_seconds'       => $hangSeconds,
            'hang_warning'       => $hangWarning,
        ];
    }
}
