#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One-shot thumbnail job runner (WORKER_MODE=spawn / CLI).
 *
 *   php scripts/thumbnail.php --job-id=12
 *   php scripts/thumbnail.php          # next pending
 */

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../src/bootstrap.php';

use MediaManager\Services\ThumbnailJobService;

$jobId = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--job-id=')) {
        $jobId = (int) substr($arg, 9);
    }
}

$root = dirname(__DIR__);
$service = new ThumbnailJobService(projectRoot: $root);

try {
    if ($jobId !== null && $jobId > 0) {
        $service->runJob($jobId);
        echo "Thumbnail job {$jobId} finished.\n";
        exit(0);
    }

    $ran = $service->runNextPending();
    if ($ran === null) {
        echo "No pending thumbnail jobs.\n";
        exit(0);
    }
    echo "Thumbnail job {$ran} finished.\n";
    exit(0);
} catch (Throwable $e) {
    $label = $jobId !== null && $jobId > 0 ? (string) $jobId : 'pending';
    fwrite(STDERR, 'Thumbnail job ' . $label . ' failed: ' . $e->getMessage() . "\n");
    exit(1);
}
