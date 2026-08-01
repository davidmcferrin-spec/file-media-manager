#!/usr/bin/env php
<?php

declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../src/bootstrap.php';

use MediaManager\Services\CaptionExtractJobService;

$jobId = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--job-id=')) {
        $jobId = (int) substr($arg, 9);
    }
}

if ($jobId === null || $jobId <= 0) {
    fwrite(STDERR, "Usage: php scripts/caption_extract.php --job-id=N\n");
    exit(1);
}

$root = dirname(__DIR__);
$service = new CaptionExtractJobService(projectRoot: $root);

try {
    $service->runJob($jobId);
    echo "Caption extract job {$jobId} finished.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Caption extract job ' . $jobId . ' failed: ' . $e->getMessage() . "\n");
    exit(1);
}
