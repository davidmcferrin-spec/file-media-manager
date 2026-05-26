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

function delete_scan_thumbnails(array $fileIds, string $projectRoot): void
{
    $cache = new \MediaManager\Services\MediaCacheService(projectRoot: $projectRoot);
    foreach ($fileIds as $fileId) {
        $cache->invalidate((int) $fileId);
    }
}

function kill_scan_worker(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        exec('taskkill /PID ' . $pid . ' /F 2>NUL', $output, $code);

        return $code === 0;
    }

    if (function_exists('posix_kill')) {
        if (!@posix_kill($pid, 0)) {
            return false;
        }
        @posix_kill($pid, SIGTERM);
        usleep(300000);
        if (@posix_kill($pid, 0)) {
            @posix_kill($pid, SIGKILL);
        }

        return true;
    }

    exec('kill -TERM ' . $pid . ' 2>/dev/null');
    usleep(300000);
    exec('kill -0 ' . $pid . ' 2>/dev/null', $out, $alive);
    if ($alive === 0) {
        exec('kill -KILL ' . $pid . ' 2>/dev/null');
    }

    return true;
}

// ── POST: cancel scan ───────────────────────────────────────────
if ($method === 'POST' && $uri === '/scan/cancel') {
    $csrf = $_POST['_csrf'] ?? '';
    if (!Session::validateCsrf($csrf)) {
        Session::flash('error', 'Invalid request.');
        header('Location: /scan');
        exit;
    }

    $jobId = (int) ($_POST['id'] ?? 0);
    $job   = $jobId > 0 ? $scanJobs->findById($jobId) : null;
    if ($job === null) {
        Session::flash('error', 'Scan job not found.');
        header('Location: /scan');
        exit;
    }

    $status = (string) ($job['status'] ?? '');
    if (!in_array($status, ['PENDING', 'RUNNING'], true)) {
        Session::flash('error', 'Only pending or running scans can be stopped.');
        header('Location: /scan/' . $jobId);
        exit;
    }

    if (!$scanJobs->requestCancel($jobId)) {
        Session::flash('error', 'Could not stop scan job.');
        header('Location: /scan/' . $jobId);
        exit;
    }

    $killed = false;
    $pid    = null;
    if ($status === 'RUNNING') {
        $pid = $scanJobs->getWorkerPid($jobId);
        if ($pid !== null && $pid > 0) {
            $killed = kill_scan_worker($pid);
            if ($killed) {
                $scanJobs->markCancelled($jobId);
            }
        }
    }

    $user = Auth::user();
    $audit->record(
        Auth::id(),
        $user['email'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
        'SCAN_CANCEL_REQUESTED',
        'scan_job',
        $jobId,
        null,
        null,
        ['previous_status' => $status, 'worker_pid' => $pid ?? null, 'killed' => $killed]
    );

    $message = $status === 'PENDING'
        ? 'Scan job #' . $jobId . ' cancelled.'
        : ($killed
            ? 'Scan job #' . $jobId . ' stopped.'
            : 'Stop requested for scan job #' . $jobId . '. It will halt after the current file.');
    Session::flash('success', $message);
    header('Location: /scan/' . $jobId);
    exit;
}

// ── POST: delete scan ───────────────────────────────────────────
if ($method === 'POST' && $uri === '/scan/delete') {
    $csrf = $_POST['_csrf'] ?? '';
    if (!Session::validateCsrf($csrf)) {
        Session::flash('error', 'Invalid request.');
        header('Location: /scan');
        exit;
    }

    $jobId = (int) ($_POST['id'] ?? 0);
    $job   = $jobId > 0 ? $scanJobs->findById($jobId) : null;
    if ($job === null) {
        Session::flash('error', 'Scan job not found.');
        header('Location: /scan');
        exit;
    }

    $status = (string) ($job['status'] ?? '');
    if ($status === 'RUNNING') {
        Session::flash('error', 'Stop the scan and wait for it to finish before deleting.');
        header('Location: /scan/' . $jobId);
        exit;
    }

    $protected = $files->countProtectedByScanJob($jobId);
    if ($protected > 0) {
        Session::flash(
            'error',
            'Cannot delete scan job #' . $jobId . ': '
            . $protected . ' file(s) are approved, executed, or rolled back.'
        );
        header('Location: /scan/' . $jobId);
        exit;
    }

    $fileIds      = $files->idsByScanJob($jobId);
    $deletedFiles = $files->deleteByScanJob($jobId);
    delete_scan_thumbnails($fileIds, $projectRoot);

    if (!$scanJobs->delete($jobId)) {
        Session::flash('error', 'Could not delete scan job.');
        header('Location: /scan/' . $jobId);
        exit;
    }

    $user = Auth::user();
    $audit->record(
        Auth::id(),
        $user['email'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
        'SCAN_DELETED',
        'scan_job',
        $jobId,
        null,
        null,
        [
            'files_removed' => $deletedFiles,
            'previous_status' => $status,
            'source_id' => (int) ($job['source_id'] ?? 0),
        ]
    );

    Session::flash('success', 'Scan job #' . $jobId . ' deleted (' . $deletedFiles . ' queued file(s) removed).');
    header('Location: /scan');
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

    $jobFiles      = $files->byScanJob($jobId, 50);
    $totalQueued   = $files->countByScanJob($jobId);
    $confidence    = $files->confidenceSummary($jobId);
    $protectedCount = $files->countProtectedByScanJob($jobId);
    $canStop       = in_array((string) $job['status'], ['PENDING', 'RUNNING'], true);
    $canDelete     = (string) $job['status'] !== 'RUNNING' && $protectedCount === 0;

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
