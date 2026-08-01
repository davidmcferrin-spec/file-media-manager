#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Bulk FFprobe caption detection for Catalog CC badges (no SRT extract).
 *
 * Enqueues a caption_extract_jobs row with scope=probe_only for every file
 * that has not been probed yet (~48k scale). Prefer the systemd worker; use
 * --run only when you want this process to do the work itself.
 *
 * Usage:
 *   php scripts/probe_captions.php              # enqueue for media-manager-caption-extract
 *   php scripts/probe_captions.php --run        # enqueue + process in-foreground
 *   php scripts/probe_captions.php --dry-run    # print counts only
 *   php scripts/probe_captions.php --user-id=1  # attribute job to this admin
 */

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../src/bootstrap.php';

use MediaManager\Database;
use MediaManager\Repositories\CaptionExtractJobRepository;
use MediaManager\Repositories\FileRepository;
use MediaManager\Services\CaptionExtractJobService;

$dryRun = false;
$runNow = false;
$userId = null;

foreach ($argv as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--run') {
        $runNow = true;
    } elseif (str_starts_with($arg, '--user-id=')) {
        $userId = (int) substr($arg, 10);
    }
}

$files = new FileRepository();
$jobs = new CaptionExtractJobRepository();
$summary = $files->summarizeCaptionExtractCandidates('probe_only', null);

echo "Unprobed files: " . number_format($summary['count']) . "\n";
echo "Approx media duration: " . number_format($summary['duration_seconds'] / 3600, 1) . " h\n";

if ($summary['count'] === 0) {
    echo "Nothing to do — every catalog file already has captions_probed=true.\n";
    exit(0);
}

if ($dryRun) {
    echo "Dry run — no job created.\n";
    exit(0);
}

$active = $jobs->findActive();
if ($active !== null) {
    fwrite(STDERR, 'An active caption job already exists: #' . (int) $active['id']
        . ' (' . (string) ($active['status'] ?? '') . ', scope='
        . (string) ($active['scope'] ?? '') . ").\n"
        . "Cancel it in the UI or wait for it to finish, then re-run.\n"
        . "Or prioritize files onto that job from Catalog → Extract CC.\n");
    exit(2);
}

if ($userId === null || $userId <= 0) {
    $row = Database::connection()->query(
        "SELECT id FROM users WHERE role = 'admin' AND active IS TRUE ORDER BY id ASC LIMIT 1"
    )->fetch();
    $userId = is_array($row) ? (int) $row['id'] : 0;
}
if ($userId <= 0) {
    fwrite(STDERR, "No admin user found. Pass --user-id=N.\n");
    exit(1);
}

$jobId = $jobs->create($userId, 'probe_only', null);
$root = dirname(__DIR__);
$logFile = CaptionExtractJobService::logPathForJob($jobId, $root);
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
@file_put_contents(
    $logFile,
    '[' . gmdate('Y-m-d\TH:i:s\Z') . "] INFO Queued via scripts/probe_captions.php"
    . " count={$summary['count']} user_id={$userId}\n",
    FILE_APPEND | LOCK_EX
);

echo "Created caption extract job #{$jobId} (scope=probe_only).\n";
echo "Log: {$logFile}\n";
echo "Watch: /captions/{$jobId}\n";

if (!$runNow) {
    echo "Left PENDING for the caption-extract worker:\n";
    echo "  systemctl start media-manager-caption-extract\n";
    echo "  journalctl -u media-manager-caption-extract -f\n";
    echo "Or run once: php scripts/caption_extract.php --job-id={$jobId}\n";
    exit(0);
}

echo "Running job #{$jobId} in this process…\n";
try {
    (new CaptionExtractJobService(projectRoot: $root))->runJob($jobId);
    echo "Job #{$jobId} finished.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Job #' . $jobId . ' failed: ' . $e->getMessage() . "\n");
    exit(1);
}
