<?php

declare(strict_types=1);

namespace MediaManager\Support;

/**
 * How background jobs are executed.
 *
 * - daemon / systemd — long-running workers poll the queue (preferred in production)
 * - spawn — web UI forks a one-shot PHP process per job (legacy / local without systemd)
 */
final class WorkerMode
{
    public static function mode(): string
    {
        $mode = strtolower(trim((string) env('WORKER_MODE', 'daemon')));

        return match ($mode) {
            'spawn', 'fork', 'cli' => 'spawn',
            default => 'daemon',
        };
    }

    public static function isDaemon(): bool
    {
        return self::mode() === 'daemon';
    }

    /** Web UI should fork a one-shot worker process. */
    public static function shouldSpawn(): bool
    {
        return self::mode() === 'spawn';
    }

    /** Idle sleep between queue polls for daemon workers. */
    public static function pollSeconds(): int
    {
        return max(1, min(300, (int) env('WORKER_POLL_SECONDS', 5)));
    }
}
