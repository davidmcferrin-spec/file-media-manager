<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\FileRepository;
use MediaManager\Repositories\ScanJobRepository;
use MediaManager\Repositories\SourceRepository;
use MediaManager\Services\FFprobeService;

Auth::requireAdmin();

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$scanJobs  = new ScanJobRepository();
$sources   = new SourceRepository();
$files     = new FileRepository();
$audit     = new AuditRepository();
$ffprobe   = new FFprobeService();

/** @var string $projectRoot */
$projectRoot = dirname(__DIR__, 2);

function spawn_scan_worker(int $jobId, string $projectRoot): void
{
    $phpBin  = PHP_BINARY;
    $script  = $projectRoot . '/scripts/scan.php';
    $logFile = $projectRoot . '/storage/logs/scan-' . $jobId . '.log';

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        pclose(popen('start /B "" ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($script)
            . ' --job-id=' . $jobId . ' >> ' . escapeshellarg($logFile) . ' 2>&1', 'r'));
    } else {
        $cmd = sprintf(
            '%s %s --job-id=%d >> %s 2>&1 &',
            escapeshellarg($phpBin),
            escapeshellarg($script),
            $jobId,
            escapeshellarg($logFile)
        );
        exec($cmd);
    }
}

// ── POST: start scan ──────────────────────────────────────────
if ($method === 'POST' && $uri === '/scan/start') {
    $csrf = $_POST['_csrf'] ?? '';
    if (!Session::validateCsrf($csrf)) {
        Session::flash('error', 'Invalid request.');
        header('Location: /scan');
        exit;
    }

    $sourceId        = (int) ($_POST['source_id'] ?? 0);
    $subpath         = trim((string) ($_POST['subpath'] ?? ''), '/');
    $extractMetadata = isset($_POST['extract_metadata']);
    $useDevList      = isset($_POST['use_dev_list']);

    $source = $sourceId > 0 ? $sources->findById($sourceId) : null;
    if ($source === null) {
        Session::flash('error', 'Select a valid NAS source.');
        header('Location: /scan');
        exit;
    }

    $devFileList = null;
    if ($useDevList) {
        $devFileList = $projectRoot . '/example_file_trees/SNSEVO-NY_Legacy_files.txt';
        if (!is_readable($devFileList)) {
            Session::flash('error', 'Dev file list not found.');
            header('Location: /scan');
            exit;
        }
    } elseif (!is_dir(rtrim((string) $source['mount_path'], '/') . ($subpath !== '' ? '/' . $subpath : ''))) {
        Session::flash('error', 'Scan path is not accessible. Mount the NAS or use dev file list mode.');
        header('Location: /scan');
        exit;
    }

    $jobId = $scanJobs->create(
        $sourceId,
        (int) Auth::id(),
        $subpath,
        $extractMetadata,
        $devFileList
    );

    $user = Auth::user();
    $audit->record(
        Auth::id(),
        $user['email'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
        'SCAN_START',
        'scan_job',
        $jobId,
        null,
        null,
        ['source_id' => $sourceId, 'subpath' => $subpath, 'dev_list' => $devFileList !== null]
    );

    spawn_scan_worker($jobId, $projectRoot);

    Session::flash('success', 'Scan job #' . $jobId . ' started.');
    header('Location: /scan/' . $jobId);
    exit;
}

// ── GET: job detail ───────────────────────────────────────────
if (preg_match('#^/scan/(\d+)$#', $uri, $m) === 1) {
    $jobId = (int) $m[1];
    $job   = $scanJobs->findById($jobId);
    if ($job === null) {
        http_response_code(404);
        Session::flash('error', 'Scan job not found.');
        header('Location: /scan');
        exit;
    }

    $jobFiles    = $files->byScanJob($jobId, 50);
    $totalQueued = $files->countByScanJob($jobId);
    $confidence  = $files->confidenceSummary($jobId);

    $title = 'Scan Job #' . $jobId . ' — Media Manager';
    require dirname(__DIR__) . '/Views/layouts/header.php';
    require dirname(__DIR__) . '/Views/scan/show.php';
    require dirname(__DIR__) . '/Views/layouts/footer.php';
    exit;
}

// ── GET: scan index ───────────────────────────────────────────
$activeSources = array_filter($sources->all(), fn ($s) => !empty($s['active']));
$recentJobs    = $scanJobs->recent(15);
$ffprobeOk     = $ffprobe->isAvailable();

$title = 'Scanner — Media Manager';
require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/scan/index.php';
require dirname(__DIR__) . '/Views/layouts/footer.php';
