<?php

declare(strict_types=1);

namespace MediaManager\Services;

/**
 * ETA for Continuity Lab while a scan job is RUNNING.
 * Accounts for CONTINUITY_CHECK_CONCURRENCY (and optional OLLAMA_NUM_PARALLEL).
 */
final class ContinuityEtaEstimator
{
    /**
     * Effective parallel slots = min(app concurrency, engine parallel when known).
     */
    public static function parallelSlots(): int
    {
        $app = ContinuityCheckService::concurrency();
        $engine = (int) env('OLLAMA_NUM_PARALLEL', 0);
        if ($engine < 1) {
            return $app;
        }

        return max(1, min($app, $engine));
    }

    /**
     * @param array<string, mixed>|null $runningJob
     * @return array{
     *   active: bool,
     *   parallel: int,
     *   processed: int,
     *   total: int,
     *   remaining: int,
     *   pct: float,
     *   eta_seconds: ?int,
     *   eta_label: string,
     *   rate_per_sec: ?float,
     *   method: string,
     *   job_id: ?int,
     *   source_name: string
     * }
     */
    public static function estimate(
        ?array $runningJob,
        ?float $avgDecideMs,
        int $recentDecides5m
    ): array {
        $parallel = self::parallelSlots();
        $empty = [
            'active'       => false,
            'parallel'     => $parallel,
            'processed'    => 0,
            'total'        => 0,
            'remaining'    => 0,
            'pct'          => 0.0,
            'eta_seconds'  => null,
            'eta_label'    => 'No active scan',
            'rate_per_sec' => null,
            'method'       => 'idle',
            'job_id'       => null,
            'source_name'  => '',
        ];

        if ($runningJob === null) {
            return $empty;
        }

        $processed = max(0, (int) ($runningJob['processed_files'] ?? 0));
        $total = max(0, (int) ($runningJob['total_files'] ?? 0));
        $remaining = $total > 0 ? max(0, $total - $processed) : 0;
        $pct = $total > 0 ? min(100.0, round(100.0 * $processed / $total, 1)) : 0.0;
        $jobId = (int) ($runningJob['id'] ?? 0);
        $source = (string) ($runningJob['source_name'] ?? '');

        if ($total <= 0) {
            return [
                'active'       => true,
                'parallel'     => $parallel,
                'processed'    => $processed,
                'total'        => $total,
                'remaining'    => 0,
                'pct'          => $pct,
                'eta_seconds'  => null,
                'eta_label'    => 'Discovering files…',
                'rate_per_sec' => null,
                'method'       => 'discovering',
                'job_id'       => $jobId > 0 ? $jobId : null,
                'source_name'  => $source,
            ];
        }

        if ($remaining === 0) {
            return [
                'active'       => true,
                'parallel'     => $parallel,
                'processed'    => $processed,
                'total'        => $total,
                'remaining'    => 0,
                'pct'          => 100.0,
                'eta_seconds'  => 0,
                'eta_label'    => 'Finishing…',
                'rate_per_sec' => null,
                'method'       => 'done',
                'job_id'       => $jobId > 0 ? $jobId : null,
                'source_name'  => $source,
            ];
        }

        $etaSeconds = null;
        $rate = null;
        $method = 'model';

        // Observed wall-clock rate already includes parallelism + skips.
        $minSamples = max(4, $parallel * 2);
        if ($recentDecides5m >= $minSamples) {
            $rate = $recentDecides5m / 300.0;
            if ($rate > 0.0001) {
                $etaSeconds = (int) ceil($remaining / $rate);
                $method = 'observed';
            }
        }

        // Fallback: avg per-request decide ms / parallel slots.
        if ($etaSeconds === null && $avgDecideMs !== null && $avgDecideMs > 0) {
            $wallMsPerFile = $avgDecideMs / $parallel;
            $etaSeconds = (int) ceil($remaining * $wallMsPerFile / 1000.0);
            $rate = $wallMsPerFile > 0 ? 1000.0 / $wallMsPerFile : null;
            $method = 'modeled';
        }

        return [
            'active'       => true,
            'parallel'     => $parallel,
            'processed'    => $processed,
            'total'        => $total,
            'remaining'    => $remaining,
            'pct'          => $pct,
            'eta_seconds'  => $etaSeconds,
            'eta_label'    => self::formatDuration($etaSeconds),
            'rate_per_sec' => $rate,
            'method'       => $method,
            'job_id'       => $jobId > 0 ? $jobId : null,
            'source_name'  => $source,
        ];
    }

    public static function formatDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return 'ETA unavailable';
        }
        if ($seconds <= 0) {
            return '< 1 min';
        }
        if ($seconds < 60) {
            return '~' . $seconds . 's';
        }
        $mins = (int) ceil($seconds / 60);
        if ($mins < 60) {
            return '~' . $mins . ' min';
        }
        $hours = intdiv($mins, 60);
        $remMins = $mins % 60;
        if ($remMins === 0) {
            return '~' . $hours . 'h';
        }

        return '~' . $hours . 'h ' . $remMins . 'm';
    }
}
