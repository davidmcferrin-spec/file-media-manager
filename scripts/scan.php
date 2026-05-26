#!/usr/bin/env php
<?php

declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../src/bootstrap.php';

use MediaManager\Services\ScanService;

$jobId = null;
$verbose = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--job-id=')) {
        $jobId = (int) substr($arg, 9);
    } elseif ($arg === '--verbose' || $arg === '-v') {
        $verbose = true;
    } elseif ($arg === '--quiet' || $arg === '-q') {
        $verbose = false;
    }
}

if ($verbose === null) {
    $verbose = PHP_SAPI === 'cli' && defined('STDOUT') && @stream_isatty(STDOUT);
}

/** @param array<string, mixed> $data */
$progress = static function (string $event, array $data) use ($verbose): void {
    if (!$verbose) {
        return;
    }

    switch ($event) {
        case 'start':
            echo sprintf(
                "Scan job #%d — %s\n  Root: %s\n  Metadata: %s\n",
                (int) $data['job_id'],
                (string) $data['source_name'],
                (string) $data['scan_root'],
                !empty($data['extract']) ? 'yes' : 'no'
            );
            break;

        case 'warning':
            echo '  Warning: ' . (string) $data['message'] . "\n";
            break;

        case 'collecting':
            echo 'Discovering media files...' . "\n";
            break;

        case 'discovered':
            echo sprintf("Found %d media file(s). Processing...\n", (int) $data['total']);
            break;

        case 'file':
            static $lastUpdate = 0.0;
            $processed = (int) $data['processed'];
            $total     = (int) $data['total'];
            $now       = microtime(true);

            if ($processed < $total && ($now - $lastUpdate) < 0.2) {
                break;
            }
            $lastUpdate = $now;

            $pct  = $total > 0 ? (int) round(($processed / $total) * 100) : 0;
            $name = basename((string) $data['path']);
            if (strlen($name) > 48) {
                $name = substr($name, 0, 45) . '...';
            }

            $line = sprintf('  [%d/%d] %3d%%  %s', $processed, $total, $pct, $name);
            echo str_pad($line, 80) . ($processed >= $total ? "\n" : "\r");
            break;

        case 'complete':
            echo sprintf(
                "Done: %d discovered, %d queued, %d skipped.\n",
                (int) $data['total'],
                (int) $data['queued'],
                (int) $data['skipped']
            );
            break;

        case 'failed':
            echo "\nScan failed: " . (string) $data['message'] . "\n";
            break;
    }
};

$service = new ScanService(onProgress: $verbose ? $progress : null);

try {
    if ($jobId !== null && $jobId > 0) {
        $service->runJob($jobId);
        if (!$verbose) {
            echo "Scan job {$jobId} completed.\n";
        }
        exit(0);
    }

    $ranJobId = $service->runNextPending();
    if ($ranJobId === null) {
        echo "No pending scan jobs.\n";
        exit(0);
    }

    if (!$verbose) {
        echo "Scan job {$ranJobId} completed.\n";
    }
    exit(0);
} catch (\Throwable $e) {
    $label = $jobId !== null && $jobId > 0 ? (string) $jobId : 'pending';
    fwrite(STDERR, "Scan job {$label} failed: " . $e->getMessage() . "\n");
    exit(1);
}
