#!/usr/bin/env php
<?php

declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../src/bootstrap.php';

use MediaManager\Services\ScanService;

$jobId = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--job-id=')) {
        $jobId = (int) substr($arg, 9);
    }
}

$service = new ScanService();

try {
    if ($jobId !== null && $jobId > 0) {
        $service->runJob($jobId);
        echo "Scan job {$jobId} completed.\n";
        exit(0);
    }

    $ranJobId = $service->runNextPending();
    if ($ranJobId === null) {
        echo "No pending scan jobs.\n";
        exit(0);
    }

    echo "Scan job {$ranJobId} completed.\n";
    exit(0);
} catch (\Throwable $e) {
    $label = $jobId !== null && $jobId > 0 ? (string) $jobId : 'pending';
    fwrite(STDERR, "Scan job {$label} failed: " . $e->getMessage() . "\n");
    exit(1);
}
