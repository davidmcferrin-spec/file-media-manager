<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\FileRepository;
use MediaManager\Repositories\ShowRepository;
use MediaManager\Repositories\SplitQueueRepository;
use PDOException;

Auth::requireAdmin();

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$splitRepo = new SplitQueueRepository();
$showRepo  = new ShowRepository();
$fileRepo  = new FileRepository();
$audit     = new AuditRepository();

/** @param array<string, mixed> $details */
function split_audit(
    AuditRepository $audit,
    string $action,
    int $entityId,
    array $details = []
): void {
    $user = Auth::user();
    $audit->record(
        Auth::id(),
        $user['email'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
        $action,
        'split_queue',
        $entityId,
        null,
        null,
        $details
    );
}

if ($method === 'POST') {
    $csrf = $_POST['_csrf'] ?? '';
    if (!Session::validateCsrf($csrf)) {
        Session::flash('error', 'Invalid request.');
        header('Location: /split');
        exit;
    }

    if ($uri === '/split/create') {
        $fileId = (int) ($_POST['file_id'] ?? 0);
        $notes  = trim($_POST['notes'] ?? '');
        $file   = $fileId > 0 ? $fileRepo->findById($fileId) : null;

        if ($file === null || empty($file['needs_split'])) {
            Session::flash('error', 'File is not eligible for split queue.');
            header('Location: /split');
            exit;
        }

        try {
            $id = $splitRepo->create($fileId, (int) Auth::id(), $notes);
            split_audit($audit, 'SPLIT_QUEUED', $id, ['file_id' => $fileId]);
            Session::flash('success', 'File added to split queue.');
            header('Location: /split/' . $id);
            exit;
        } catch (PDOException $e) {
            $msg = $splitRepo->isUniqueViolation($e)
                ? 'This file already has an active split job.'
                : 'Could not add file to split queue.';
            Session::flash('error', $msg);
            header('Location: /split');
            exit;
        }
    }

    if ($uri === '/split/update') {
        $id     = (int) ($_POST['id'] ?? 0);
        $notes  = trim($_POST['notes'] ?? '');
        $status = $_POST['status'] ?? 'PENDING';
        $item   = $id > 0 ? $splitRepo->findById($id) : null;

        if ($item === null) {
            Session::flash('error', 'Split job not found.');
            header('Location: /split');
            exit;
        }

        if (!in_array($status, ['PENDING', 'IN_PROGRESS', 'DONE', 'FAILED'], true)) {
            $status = 'PENDING';
        }

        $segments = parse_split_segments($_POST);
        $splitRepo->update($id, $segments, $notes, $status);
        split_audit($audit, 'SPLIT_UPDATED', $id, [
            'status'         => $status,
            'segment_count'  => count($segments),
        ]);
        Session::flash('success', 'Split job saved.');
        header('Location: /split/' . $id);
        exit;
    }

    if ($uri === '/split/delete') {
        $id   = (int) ($_POST['id'] ?? 0);
        $item = $id > 0 ? $splitRepo->findById($id) : null;
        if ($item !== null && $splitRepo->delete($id)) {
            split_audit($audit, 'SPLIT_DELETED', $id, ['file_id' => $item['file_id']]);
            Session::flash('success', 'Split job removed.');
        } else {
            Session::flash('error', 'Could not remove split job.');
        }
        header('Location: /split');
        exit;
    }

    http_response_code(404);
    exit;
}

// GET /split/{id}
if (preg_match('#^/split/(\d+)$#', $uri, $m)) {
    $id   = (int) $m[1];
    $item = $splitRepo->findById($id);
    if ($item === null) {
        http_response_code(404);
        $title = '404 — Not Found';
        require dirname(__DIR__) . '/Views/layouts/header.php';
        echo '<p class="text-soft">Split job not found.</p>';
        echo '<a href="/split" class="btn btn-outline-secondary btn-sm">Back to Split Queue</a>';
        require dirname(__DIR__) . '/Views/layouts/footer.php';
        exit;
    }

    $segments = json_decode((string) ($item['segments'] ?? '[]'), true);
    if (!is_array($segments)) {
        $segments = [];
    }
    $shows = $showRepo->all(true);
    $title = 'Split Job #' . $id . ' — Media Manager';

    require dirname(__DIR__) . '/Views/layouts/header.php';
    require dirname(__DIR__) . '/Views/split/detail.php';
    require dirname(__DIR__) . '/Views/layouts/footer.php';
    exit;
}

// GET /split
$statusFilter = trim($_GET['status'] ?? '');
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = 50;
$offset       = ($page - 1) * $perPage;

$items        = $splitRepo->all($statusFilter !== '' ? $statusFilter : null, $perPage, $offset);
$total        = $splitRepo->count($statusFilter !== '' ? $statusFilter : null);
$statusCounts = $splitRepo->statusCounts();
$splittable   = $splitRepo->splittableFiles(30);
$totalPages   = max(1, (int) ceil($total / $perPage));

$title = 'Split Queue — Media Manager';

require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/split/index.php';
require dirname(__DIR__) . '/Views/layouts/footer.php';

/**
 * @return list<array{start: float, end: float, show_id: int|null, label: string}>
 */
function parse_split_segments(array $post): array
{
    $starts   = $post['segment_start'] ?? [];
    $ends     = $post['segment_end'] ?? [];
    $showIds  = $post['segment_show_id'] ?? [];
    $labels   = $post['segment_label'] ?? [];
    $segments = [];

    if (!is_array($starts)) {
        return [];
    }

    foreach ($starts as $i => $startRaw) {
        $start = is_numeric($startRaw) ? (float) $startRaw : null;
        $end   = is_numeric($ends[$i] ?? null) ? (float) $ends[$i] : null;
        if ($start === null || $end === null || $end <= $start) {
            continue;
        }
        $showId = ($showIds[$i] ?? '') !== '' ? (int) $showIds[$i] : null;
        $label  = trim((string) ($labels[$i] ?? ''));

        $segments[] = [
            'start'   => $start,
            'end'     => $end,
            'show_id' => $showId,
            'label'   => $label,
        ];
    }

    usort($segments, fn ($a, $b) => $a['start'] <=> $b['start']);

    return $segments;
}
