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

$root = dirname(__DIR__);
$service = new CaptionExtractJobService(projectRoot: $root);

try {
    if ($jobId !== null && $jobId > 0) {
        $service->runJob($jobId);
        echo "Caption extract job {$jobId} finished.\n";
        exit(0);
    }

    $ran = $service->runNextPending();
    if ($ran === null) {
        echo "No pending caption extract jobs.\n";
        exit(0);
    }
    echo "Caption extract job {$ran} finished.\n";
    exit(0);
} catch (Throwable $e) {
    $label = $jobId !== null && $jobId > 0 ? (string) $jobId : 'pending';
    fwrite(STDERR, 'Caption extract job ' . $label . ' failed: ' . $e->getMessage() . "\n");
    exit(1);
}
