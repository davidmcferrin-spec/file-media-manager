#!/usr/bin/env php
<?php

declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../src/bootstrap.php';

use MediaManager\Services\ScanService;
use MediaManager\Services\ScanCancelledException;

$jobId = null;
$verbose = null;
$rescan = false;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--job-id=')) {
        $jobId = (int) substr($arg, 9);
    } elseif ($arg === '--rescan') {
        $rescan = true;
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
$flushOutput = static function (): void {
    if (function_exists('ob_get_level') && ob_get_level() > 0) {
        ob_flush();
    }
    flush();
};

/** @param array<string, mixed> $data */
$progress = static function (string $event, array $data) use ($verbose, $flushOutput): void {
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
            echo 'Discovering media files (opening scan root)...' . "\n";
            break;

        case 'collecting_progress':
            $dir = basename((string) $data['current_dir']);
            if (strlen($dir) > 36) {
                $dir = substr($dir, 0, 33) . '...';
            }
            $line = sprintf(
                '  Scanning... %s dirs, %s entries, %s media — %s',
                number_format((int) $data['dirs']),
                number_format((int) $data['entries']),
                number_format((int) $data['media']),
                $dir
            );
            echo str_pad($line, 100) . "\r";
            break;

        case 'discovered':
            echo sprintf("\nFound %d media file(s). Processing...\n", (int) $data['total']);
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
                "Done: %d discovered, %d queued, %d reclassified, %d duplicate, %d skipped.\n",
                (int) $data['total'],
                (int) $data['queued'],
                (int) ($data['reclassified'] ?? 0),
                (int) ($data['duplicates'] ?? 0),
                (int) $data['skipped']
            );
            break;

        case 'failed':
            echo "\nScan failed: " . (string) $data['message'] . "\n";
            break;

        case 'cancelled':
            echo "\nScan cancelled.\n";
            break;

        case 'paused':
            echo "\nScan paused — run scan.php again (no flags) to resume.\n";
            break;
    }

    $flushOutput();
};

$service = new ScanService(onProgress: $verbose ? $progress : null);

try {
    if ($jobId !== null && $jobId > 0) {
        $service->runJob($jobId, $rescan);
        if (!$verbose) {
            echo $rescan
                ? "Rescan job {$jobId} completed.\n"
                : "Scan job {$jobId} completed.\n";
        }
        exit(0);
    }

    $ranJobId = $service->runNextPending();
    if ($ranJobId === null) {
        echo "No pending, paused, or failed scan jobs.\n";
        exit(0);
    }

    if (!$verbose) {
        echo "Scan job {$ranJobId} completed.\n";
    }
    exit(0);
} catch (ScanCancelledException) {
    exit(0);
} catch (\Throwable $e) {
    $label = $jobId !== null && $jobId > 0 ? (string) $jobId : 'pending';
    fwrite(STDERR, "Scan job {$label} failed: " . $e->getMessage() . "\n");
    exit(1);
}
