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

if ($jobId === null || $jobId <= 0) {
    fwrite(STDERR, "Usage: php scripts/scan.php --job-id=ID\n");
    exit(1);
}

try {
    (new ScanService())->runJob($jobId);
    echo "Scan job {$jobId} completed.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Scan job {$jobId} failed: " . $e->getMessage() . "\n");
    exit(1);
}
