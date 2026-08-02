#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Long-running Catalog thumbnail queue worker for systemd.
 *
 *   php scripts/thumbnail_worker.php
 *   systemctl start media-manager-thumbnail
 */

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../src/bootstrap.php';

use MediaManager\Services\ThumbnailJobService;
use MediaManager\Support\WorkerMode;

$poll = WorkerMode::pollSeconds();
$projectRoot = dirname(__DIR__);
$logFile = $projectRoot . '/storage/logs/thumbnail-worker.log';
$shutdown = false;

$log = static function (string $level, string $message, array $ctx = []) use ($logFile): void {
    $line = '[' . gmdate('Y-m-d\TH:i:s\Z') . "] {$level} {$message}";
    if ($ctx !== []) {
        $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES);
    }
    $line .= "\n";
    fwrite(STDOUT, $line);
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
};

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
}
if (function_exists('pcntl_signal')) {
    $handler = static function () use (&$shutdown, $log): void {
        $shutdown = true;
        $log('INFO', 'Shutdown signal received — finishing current job then exiting');
    };
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT, $handler);
}

$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

$log('INFO', 'Thumbnail worker started', [
    'pid'  => getmypid(),
    'poll' => $poll,
]);

$service = new ThumbnailJobService(projectRoot: $projectRoot);

while (!$shutdown) {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    try {
        $jobId = $service->runNextPending();
        if ($jobId === null) {
            sleep($poll);
            continue;
        }
        $log('INFO', 'Thumbnail job finished', ['job_id' => $jobId]);
    } catch (Throwable $e) {
        $log('ERROR', 'Thumbnail worker error', [
            'exception' => $e::class,
            'message'   => $e->getMessage(),
        ]);
        sleep(min($poll, 15));
    }
}

$log('INFO', 'Thumbnail worker stopped', ['pid' => getmypid()]);
exit(0);
