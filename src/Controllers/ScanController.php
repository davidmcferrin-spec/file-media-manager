<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\FileRepository;
use MediaManager\Repositories\ProgramScheduleRepository;
use MediaManager\Repositories\ScanJobRepository;
use MediaManager\Repositories\SourceRepository;
use MediaManager\Repositories\SystemRepository;
use MediaManager\Services\FFprobeService;
use MediaManager\Services\ScanEtaEstimator;
use MediaManager\Support\View;

Auth::requireAdmin();

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$scanJobs     = new ScanJobRepository();
$sources      = new SourceRepository();
$files        = new FileRepository();
$audit        = new AuditRepository();
$systemRepo   = new SystemRepository();
$scheduleRepo = new ProgramScheduleRepository();
$ffprobe      = new FFprobeService();

/** @var string $projectRoot */
$projectRoot = dirname(__DIR__, 2);

function spawn_scan_worker(int $jobId, string $projectRoot, bool $rescan = false): void
{
    if (!\MediaManager\Support\WorkerMode::shouldSpawn()) {
        // Daemon mode: media-manager-scan.service polls PENDING jobs.
        return;
    }

    $phpBin  = PHP_BINARY;
    $script  = $projectRoot . '/scripts/scan.php';
    $logFile = $projectRoot . '/storage/logs/scan-' . $jobId . '.log';
    $flags   = '--job-id=' . $jobId . ($rescan ? ' --rescan' : '');

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        pclose(popen('start /B "" ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($script)
            . ' ' . $flags . ' >> ' . escapeshellarg($logFile) . ' 2>&1', 'r'));
    } else {
        $cmd = sprintf(
            '%s %s %s >> %s 2>&1 &',
            escapeshellarg($phpBin),
            escapeshellarg($script),
            $flags,
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
    $ackTimeline     = isset($_POST['ack_timeline_not_ready']);

    $timelineReady = in_array(
        strtolower(trim((string) ($systemRepo->get('timeline_ready_for_scan') ?? ''))),
        ['1', 'true', 'yes', 'on'],
        true
    );
    if (!$timelineReady && !$ackTimeline) {
        Session::flash(
            'error',
            'Timeline is not marked ready for Scan. Finish schedule hygiene on Timeline, or check “Start anyway” to acknowledge.'
        );
        header('Location: /scan');
        exit;
    }

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

    $queuedMsg = \MediaManager\Support\WorkerMode::isDaemon()
        ? 'Scan job #' . $jobId . ' queued — the scan worker will pick it up.'
        : 'Scan job #' . $jobId . ' started.';
    Session::flash('success', $queuedMsg);
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

    $killed      = false;
    $pid         = null;
    $stopOutcome = null;
    // In daemon mode never SIGKILL the long-running worker — cooperative cancel only.
    if ($status === 'RUNNING' && \MediaManager\Support\WorkerMode::shouldSpawn()) {
        $pid = $scanJobs->getWorkerPid($jobId);
        if ($pid !== null && $pid > 0) {
            $killed = kill_scan_worker($pid);
            if ($killed) {
                $stopOutcome = $scanJobs->markStopped($jobId);
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
        ['previous_status' => $status, 'worker_pid' => $pid ?? null, 'killed' => $killed, 'outcome' => $stopOutcome ?? null]
    );

    $message = match ($status) {
        'PENDING' => 'Scan job #' . $jobId . ' cancelled.',
        'RUNNING' => match ($stopOutcome ?? null) {
            'PAUSED'  => 'Scan job #' . $jobId . ' paused. Click Resume when ready.',
            'CANCELLED' => 'Scan job #' . $jobId . ' stopped.',
            default   => $killed
                ? 'Scan job #' . $jobId . ' stopped.'
                : 'Stop requested for scan job #' . $jobId . '. The worker will halt after the current file.',
        },
        default => 'Scan job #' . $jobId . ' updated.',
    };
    Session::flash('success', $message);
    header('Location: /scan/' . $jobId);
    exit;
}

// ── POST: resume paused scan ────────────────────────────────────
if ($method === 'POST' && $uri === '/scan/resume') {
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

    if ((string) ($job['status'] ?? '') !== 'PAUSED') {
        Session::flash('error', 'Only paused scans can be resumed.');
        header('Location: /scan/' . $jobId);
        exit;
    }

    // PAUSED jobs are already claimable by the daemon / scan.php --job-id.
    spawn_scan_worker($jobId, $projectRoot);

    $user = Auth::user();
    $audit->record(
        Auth::id(),
        $user['email'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
        'SCAN_RESUMED',
        'scan_job',
        $jobId,
        null,
        null,
        []
    );

    $msg = \MediaManager\Support\WorkerMode::isDaemon()
        ? 'Scan job #' . $jobId . ' queued for resume — the scan worker will pick it up.'
        : 'Scan job #' . $jobId . ' resumed.';
    Session::flash('success', $msg);
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
    $force = !empty($_POST['force']);
    $workerAlive = $scanJobs->isWorkerAlive($jobId);

    if ($status === 'RUNNING') {
        if ($workerAlive) {
            Session::flash('error', 'Scan worker is still running. Stop the scan first, or wait for it to finish.');
            header('Location: /scan/' . $jobId);
            exit;
        }
        // Orphaned RUNNING: clear so delete can proceed.
        $scanJobs->forceAbandon($jobId);
        $job = $scanJobs->findById($jobId) ?? $job;
        $status = (string) ($job['status'] ?? $status);
    } elseif ($status === 'PENDING') {
        // Not started yet — abandon then delete (does not require force).
        $scanJobs->forceAbandon($jobId);
        $job = $scanJobs->findById($jobId) ?? $job;
        $status = (string) ($job['status'] ?? $status);
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
            'force' => $force,
        ]
    );

    Session::flash('success', 'Scan job #' . $jobId . ' deleted (' . $deletedFiles . ' queued file(s) removed).');
    header('Location: /scan');
    exit;
}

// ── POST: full rescan (re-walk + reclassify eligible + queue new) ─
if ($method === 'POST' && $uri === '/scan/rescan') {
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
    if (!in_array($status, ['COMPLETED', 'CANCELLED', 'PAUSED', 'FAILED'], true)) {
        Session::flash('error', 'Finish or pause the scan before starting a full rescan.');
        header('Location: /scan/' . $jobId);
        exit;
    }

    if (!$scanJobs->prepareRescan($jobId)) {
        Session::flash('error', 'Could not prepare job for rescan.');
        header('Location: /scan/' . $jobId);
        exit;
    }

    $user = Auth::user();
    $audit->record(
        Auth::id(),
        $user['email'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
        'SCAN_RESCAN_START',
        'scan_job',
        $jobId,
        null,
        null,
        [
            'source_id' => (int) $job['source_id'],
            'subpath'   => (string) ($job['subpath'] ?? ''),
            'prior_status' => $status,
        ]
    );

    spawn_scan_worker($jobId, $projectRoot, true);

    $rescanMsg = \MediaManager\Support\WorkerMode::isDaemon()
        ? 'Full rescan queued for job #' . $jobId . ' — the scan worker will pick it up.'
        : 'Full rescan started for job #' . $jobId . '.';
    Session::flash(
        'success',
        $rescanMsg
        . ' Re-walks the path, reclassifies pending/flagged/rejected files, queues new ones. Approved/executed files are left unchanged.'
    );
    header('Location: /scan/' . $jobId);
    exit;
}

// ── POST: reclassify existing files in a scan job ─────────────
if ($method === 'POST' && $uri === '/scan/reclassify') {
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
    if (!in_array($status, ['COMPLETED', 'CANCELLED', 'PAUSED', 'FAILED'], true)) {
        Session::flash('error', 'Finish or pause the scan before reclassifying.');
        header('Location: /scan/' . $jobId);
        exit;
    }

    try {
        $stats = (new \MediaManager\Services\ReclassifyService())->reclassifyScanJob($jobId);
        $user = Auth::user();
        $audit->record(
            Auth::id(),
            $user['email'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
            'SCAN_RECLASSIFIED',
            'scan_job',
            $jobId,
            null,
            null,
            $stats
        );
        Session::flash(
            'success',
            sprintf(
                'Reclassified %d file(s) (%d skipped, %d failed, %d protected left unchanged).',
                $stats['reclassified'],
                $stats['skipped'],
                $stats['failed'],
                $stats['protected']
            )
        );
    } catch (\Throwable $e) {
        Session::flash('error', 'Reclassify failed: ' . $e->getMessage());
    }

    header('Location: /scan/' . $jobId);
    exit;
}

// ── GET: JSON status (live poll — no full page refresh) ───────
if ($method === 'GET' && preg_match('#^/scan/(\d+)/status$#', $uri, $m) === 1) {
    $jobId = (int) $m[1];
    $job   = $scanJobs->findById($jobId);
    if ($job === null) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Scan job not found'], JSON_THROW_ON_ERROR);
        exit;
    }

    $statusStr = (string) ($job['status'] ?? '');
    $totalQueued = $files->countByScanJob($jobId);
    $confidence = $files->confidenceSummary($jobId);
    $workerAlive = $statusStr === 'RUNNING' && $scanJobs->isWorkerAlive($jobId);
    $workerOrphan = $statusStr === 'RUNNING' && !$scanJobs->isWorkerAlive($jobId);
    $timing = ScanEtaEstimator::estimate($job);
    $total = (int) ($job['total_files'] ?? 0);
    $done = (int) ($job['processed_files'] ?? 0);
    $pct = $total > 0 ? (int) round(($done / $total) * 100) : 0;
    $poll = $statusStr === 'RUNNING'
        || ($statusStr === 'PENDING' && empty($job['cancel_requested']));

    $sample = [];
    foreach ($files->byScanJob($jobId, 50, 0, true) as $file) {
        $fc = (string) ($file['confidence'] ?? 'UNEVALUATED');
        $sample[] = [
            'original_filename'  => (string) ($file['original_filename'] ?? ''),
            'original_dir'       => (string) ($file['original_dir'] ?? ''),
            'proposed_filename'  => $file['proposed_filename'] ?? null,
            'proposed_dir'       => $file['proposed_dir'] ?? null,
            'confidence'         => $fc,
            'confidence_label'   => $fc === 'UNEVALUATED' ? 'Unevaluated' : $fc,
            'parsed_dt'          => View::formatParsedDateTime(
                $file['file_date'] ?? null,
                $file['file_time'] ?? null
            ),
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'id'               => $jobId,
        'status'           => $statusStr,
        'poll'             => $poll,
        'cancel_requested' => !empty($job['cancel_requested']),
        'worker_alive'     => $workerAlive,
        'worker_orphan'    => $workerOrphan,
        'processed_files'  => $done,
        'total_files'      => $total,
        'pct'              => $pct,
        'total_queued'     => $totalQueued,
        'confidence'       => [
            'HIGH'         => (int) ($confidence['HIGH'] ?? 0),
            'MEDIUM'       => (int) ($confidence['MEDIUM'] ?? 0),
            'LOW'          => (int) ($confidence['LOW'] ?? 0),
            'UNEVALUATED'  => (int) ($confidence['UNEVALUATED'] ?? 0),
        ],
        'timing'           => $timing,
        'error_message'    => $job['error_message'] ?? null,
        'sample'           => $sample,
    ], JSON_THROW_ON_ERROR);
    exit;
}

// ── GET: export scan classification spreadsheet ───────────────
if ($method === 'GET' && preg_match('#^/scan/(\d+)/export$#', $uri, $m) === 1) {
    $jobId = (int) $m[1];
    $job   = $scanJobs->findById($jobId);
    if ($job === null) {
        Session::flash('error', 'Scan job not found.');
        header('Location: /scan');
        exit;
    }

    try {
        $export = (new \MediaManager\Services\ScanExportService())->exportScanJob($jobId);
        $user = Auth::user();
        $audit->record(
            Auth::id(),
            $user['email'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
            'SCAN_EXPORTED',
            'scan_job',
            $jobId,
            null,
            null,
            ['rows' => $export['row_count'], 'filename' => $export['filename']]
        );

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
        header('Content-Length: ' . (string) strlen($export['bytes']));
        header('Cache-Control: no-store');
        echo $export['bytes'];
        exit;
    } catch (\Throwable $e) {
        Session::flash('error', 'Export failed: ' . $e->getMessage());
        header('Location: /scan/' . $jobId);
        exit;
    }
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

    $jobFiles      = $files->byScanJob($jobId, 50, 0, true);
    $totalQueued   = $files->countByScanJob($jobId);
    $confidence    = $files->confidenceSummary($jobId);
    $protectedCount = $files->countProtectedByScanJob($jobId);
    $reclassifiableCount = $files->countReclassifiableByScanJob($jobId);
    $statusStr     = (string) $job['status'];
    $workerAlive   = $statusStr === 'RUNNING' && $scanJobs->isWorkerAlive($jobId);
    // Hung = marked RUNNING but worker PID is dead/missing (not PENDING waiting for pickup).
    $workerOrphan  = $statusStr === 'RUNNING' && !$scanJobs->isWorkerAlive($jobId);
    $canStop       = in_array($statusStr, ['PENDING', 'RUNNING'], true);
    $canResume     = $statusStr === 'PAUSED';
    $canDelete     = $protectedCount === 0 && ($statusStr !== 'RUNNING' || $workerOrphan);
    $canForceDelete = $protectedCount === 0 && $workerOrphan;
    $canReclassify = in_array($statusStr, ['COMPLETED', 'CANCELLED', 'PAUSED', 'FAILED'], true)
        && $reclassifiableCount > 0;
    $canRescan = in_array($statusStr, ['COMPLETED', 'CANCELLED', 'PAUSED', 'FAILED'], true);
    $timing = ScanEtaEstimator::estimate($job);

    $title = 'Scan Job #' . $jobId . ' — Media Manager';
    require dirname(__DIR__) . '/Views/layouts/header.php';
    require dirname(__DIR__) . '/Views/scan/show.php';
    require dirname(__DIR__) . '/Views/layouts/footer.php';
    exit;
}

// ── GET: scan index ───────────────────────────────────────────
$activeSources = array_filter($sources->all(), fn ($s) => !empty($s['active']));
$recentJobs    = $scanJobs->recent(15);
$scanJobsRepo  = $scanJobs;
$ffprobeOk     = $ffprobe->isAvailable();
$timelineReady = in_array(
    strtolower(trim((string) ($systemRepo->get('timeline_ready_for_scan') ?? ''))),
    ['1', 'true', 'yes', 'on'],
    true
);
$timelineReadyAt = trim((string) ($systemRepo->get('timeline_ready_at') ?? ''));
$openEndedTotal  = $scheduleRepo->countOpenEnded();
$timelineActive  = $scheduleRepo->countActive();

$title = 'Scan — Media Manager';
require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/scan/index.php';
require dirname(__DIR__) . '/Views/layouts/footer.php';
