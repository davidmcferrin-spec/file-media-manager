<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\FileRepository;
use MediaManager\Repositories\GlueQueueRepository;
use MediaManager\Services\GlueConcatService;

Auth::requireLogin();

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$files   = new FileRepository();
$jobs    = new GlueQueueRepository();
$concat  = new GlueConcatService($jobs, $files);
$audit   = new AuditRepository();

/** @param array<string, mixed> $details */
function glue_audit(
    AuditRepository $audit,
    string $action,
    int $entityId,
    ?string $originalPath = null,
    ?string $newPath = null,
    array $details = []
): void {
    $user = Auth::user();
    $audit->record(
        Auth::id(),
        $user['email'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
        $action,
        'glue_queue',
        $entityId,
        $originalPath,
        $newPath,
        $details
    );
}

if ($method === 'POST') {
    Auth::requireAdmin();

    $csrf = $_POST['_csrf'] ?? '';
    if (!Session::validateCsrf($csrf)) {
        Session::flash('error', 'Invalid request.');
        header('Location: /glue');
        exit;
    }

    $user = Auth::user();
    $userId = (int) Auth::id();
    $email = (string) ($user['email'] ?? '');
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    if ($uri === '/glue/queue') {
        $groupKey = trim((string) ($_POST['glue_group_key'] ?? ''));
        $result = $concat->queueGroup($groupKey, $userId);
        if ($result['ok'] && $result['job_id'] !== null) {
            glue_audit($audit, 'GLUE_QUEUED', $result['job_id'], null, null, [
                'glue_group_key' => $groupKey,
            ]);
            Session::flash('success', $result['message']);
            header('Location: /glue/' . $result['job_id']);
            exit;
        }
        Session::flash('error', $result['message']);
        header('Location: /glue');
        exit;
    }

    if ($uri === '/glue/run') {
        $jobId = (int) ($_POST['id'] ?? 0);
        $result = $concat->run($jobId, $userId, $email, $ip);
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: /glue/' . max(1, $jobId));
        exit;
    }

    if ($uri === '/glue/qc-approve') {
        $jobId = (int) ($_POST['id'] ?? 0);
        $result = $concat->approveQc($jobId, $userId, $email, $ip);
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: /glue/' . max(1, $jobId));
        exit;
    }

    if ($uri === '/glue/qc-reject') {
        $jobId = (int) ($_POST['id'] ?? 0);
        $result = $concat->rejectQc($jobId, $userId, $email, $ip);
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: /glue/' . max(1, $jobId));
        exit;
    }

    if ($uri === '/glue/delete-sources') {
        $jobId = (int) ($_POST['id'] ?? 0);
        $result = $concat->deleteSources($jobId, $userId, $email, $ip);
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: /glue/' . max(1, $jobId));
        exit;
    }

    if ($uri === '/glue/cancel') {
        $jobId = (int) ($_POST['id'] ?? 0);
        $job = $jobId > 0 ? $jobs->findById($jobId) : null;
        if ($job === null) {
            Session::flash('error', 'Glue job not found.');
            header('Location: /glue');
            exit;
        }

        // Reject path for QC states (drops output); cancel for pending/failed.
        if (in_array((string) $job['status'], ['READY_FOR_QC', 'APPROVED'], true)) {
            $result = $concat->rejectQc($jobId, $userId, $email, $ip);
            Session::flash($result['ok'] ? 'success' : 'error', $result['message']);
            header('Location: /glue/' . $jobId);
            exit;
        }

        if ($jobs->markCancelled($jobId)) {
            glue_audit($audit, 'GLUE_CANCELLED', $jobId, null, null, [
                'prior_status' => $job['status'] ?? null,
            ]);
            Session::flash('success', 'Glue job cancelled.');
        } else {
            Session::flash('error', 'Could not cancel this job.');
        }
        header('Location: /glue');
        exit;
    }

    if ($uri === '/glue/retry') {
        $jobId = (int) ($_POST['id'] ?? 0);
        $job = $jobId > 0 ? $jobs->findById($jobId) : null;
        if ($job === null) {
            Session::flash('error', 'Glue job not found.');
            header('Location: /glue');
            exit;
        }
        if ($jobs->resetToPending($jobId)) {
            glue_audit($audit, 'GLUE_RESET', $jobId);
            Session::flash('success', 'Job reset to PENDING.');
        } else {
            Session::flash('error', 'Could not reset this job.');
        }
        header('Location: /glue/' . $jobId);
        exit;
    }

    http_response_code(404);
    exit;
}

// GET /glue/{id}
if (preg_match('#^/glue/(\d+)$#', $uri, $m)) {
    $id = (int) $m[1];
    $item = $jobs->findById($id);
    if ($item === null) {
        http_response_code(404);
        $title = '404 — Not Found';
        require dirname(__DIR__) . '/Views/layouts/header.php';
        echo '<p class="text-soft">Glue job not found.</p>';
        echo '<a href="/glue" class="btn btn-outline-secondary btn-sm">Back to Glue</a>';
        require dirname(__DIR__) . '/Views/layouts/footer.php';
        exit;
    }

    $sourceIds = $jobs->parseSourceIds($item['source_file_ids'] ?? '[]');
    $sourceFiles = [];
    foreach ($sourceIds as $fid) {
        $row = $files->findById($fid);
        if ($row !== null) {
            $sourceFiles[] = $row;
        }
    }
    if ($sourceFiles === []) {
        $sourceFiles = $files->listByGlueGroupKey((string) $item['glue_group_key']);
    }

    $outputFile = null;
    if (!empty($item['output_file_id'])) {
        $outputFile = $files->findById((int) $item['output_file_id']);
    }

    $expected = isset($item['expected_duration_seconds']) && $item['expected_duration_seconds'] !== null
        ? (float) $item['expected_duration_seconds']
        : null;
    $actual = isset($item['output_duration_seconds']) && $item['output_duration_seconds'] !== null
        ? (float) $item['output_duration_seconds']
        : null;
    $durationOk = GlueConcatService::durationLooksOk($expected, $actual);

    $title = 'Glue Job #' . $id . ' — Media Manager';
    require dirname(__DIR__) . '/Views/layouts/header.php';
    require dirname(__DIR__) . '/Views/glue/detail.php';
    require dirname(__DIR__) . '/Views/layouts/footer.php';
    exit;
}

// GET /glue/list-status — JSON for glue index live poll
if ($method === 'GET' && $uri === '/glue/list-status') {
    $statusFilter = trim((string) ($_GET['status'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 50;
    $offset = ($page - 1) * $perPage;
    $jobItems = $jobs->all($statusFilter !== '' ? $statusFilter : null, $perPage, $offset);
    $jobTotal = $jobs->count($statusFilter !== '' ? $statusFilter : null);
    $statusCounts = $jobs->statusCounts();
    $activeByGroup = $jobs->activeJobsByGroupKey();
    $jobsOut = [];
    $poll = false;
    foreach ($jobItems as $job) {
        $st = (string) ($job['status'] ?? '');
        if (in_array($st, ['PENDING', 'RUNNING'], true)) {
            $poll = true;
        }
        $badge = match ($st) {
            'READY_FOR_QC' => 'bg-warning text-dark',
            'APPROVED'     => 'bg-info text-dark',
            'DONE'         => 'bg-success',
            'FAILED'       => 'bg-danger',
            'RUNNING'      => 'bg-primary',
            'CANCELLED'    => 'bg-secondary',
            default        => 'bg-secondary',
        };
        $jobsOut[] = [
            'id'             => (int) ($job['id'] ?? 0),
            'status'         => $st,
            'status_badge'   => $badge,
            'error_message'  => $job['error_message'] ?? null,
            'output_filename'=> $job['output_filename'] ?? null,
            'glue_group_key' => (string) ($job['glue_group_key'] ?? ''),
        ];
    }
    $activeOut = [];
    foreach ($activeByGroup as $key => $aj) {
        $activeOut[(string) $key] = [
            'id'     => (int) ($aj['id'] ?? 0),
            'status' => (string) ($aj['status'] ?? ''),
        ];
        if (in_array((string) ($aj['status'] ?? ''), ['PENDING', 'RUNNING'], true)) {
            $poll = true;
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'poll'            => $poll,
        'status_counts'   => $statusCounts,
        'job_total'       => $jobTotal,
        'ids'             => array_column($jobsOut, 'id'),
        'jobs'            => $jobsOut,
        'active_by_group' => $activeOut,
    ], JSON_THROW_ON_ERROR);
    exit;
}

// GET /glue
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$jobItems = $jobs->all($statusFilter !== '' ? $statusFilter : null, $perPage, $offset);
$jobTotal = $jobs->count($statusFilter !== '' ? $statusFilter : null);
$statusCounts = $jobs->statusCounts();
$activeByGroup = $jobs->activeJobsByGroupKey();
$groups = $files->listGlueGroups(500);
$totalParts = $files->countNeedsGlue();
$totalPages = max(1, (int) ceil($jobTotal / $perPage));

$title = 'Glue — Media Manager';
require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/glue/index.php';
require dirname(__DIR__) . '/Views/layouts/footer.php';
