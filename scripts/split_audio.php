#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One-shot split audio job runner (WORKER_MODE=spawn / CLI).
 *
 *   php scripts/split_audio.php --job-id=12
 *   php scripts/split_audio.php          # next pending
 */

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../src/bootstrap.php';

use MediaManager\Services\SplitAudioJobService;

$jobId = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--job-id=')) {
        $jobId = (int) substr($arg, strlen('--job-id='));
    }
}

$service = new SplitAudioJobService(projectRoot: dirname(__DIR__));
if ($jobId !== null && $jobId > 0) {
    $done = $service->runJob($jobId);
    exit($done === null ? 1 : 0);
}

$done = $service->runNextPending();
exit($done === null ? 0 : 0);
