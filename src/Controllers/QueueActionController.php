<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\FileRepository;
use MediaManager\Repositories\SplitQueueRepository;
use PDOException;

Auth::requireLogin();

if (!Auth::isEditor()) {
    header('Location: /dashboard?error=unauthorized');
    exit;
}

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method !== 'POST') {
    http_response_code(405);
    exit;
}

$csrf = $_POST['_csrf'] ?? '';
if (!Session::validateCsrf($csrf)) {
    Session::flash('error', 'Invalid request.');
    header('Location: /queue');
    exit;
}

$files = new FileRepository();
$audit = new AuditRepository();
$user  = Auth::user();
$userId = (int) Auth::id();
$ip     = $_SERVER['REMOTE_ADDR'] ?? '';

/** @return list<int> */
function parse_ids(): array
{
    $raw = $_POST['ids'] ?? $_POST['id'] ?? [];
    if (!is_array($raw)) {
        $raw = [$raw];
    }
    $ids = [];
    foreach ($raw as $v) {
        $id = (int) $v;
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

function redirect_queue(): void
{
    $return = $_POST['return'] ?? '/queue';
    if (!is_string($return) || !str_starts_with($return, '/queue')) {
        $return = '/queue';
    }
    header('Location: ' . $return);
    exit;
}

/** @return list<int> */
function remove_active_split_jobs(
    SplitQueueRepository $splitRepo,
    AuditRepository $audit,
    int $fileId,
    int $userId,
    string $email,
    string $ip,
    string $filePath
): array {
    $removed = $splitRepo->deleteActiveForFile($fileId);
    foreach ($removed as $jobId) {
        $audit->record($userId, $email, $ip, 'SPLIT_QUEUE_REMOVED', 'split_queue', $jobId, $filePath, null, [
            'file_id' => $fileId,
            'reason'  => 'split_flag_cleared',
        ]);
    }

    return $removed;
}

// ── Single approve ────────────────────────────────────────────
if ($uri === '/queue/approve') {
    $ids = parse_ids();
    $count = 0;
    foreach ($ids as $id) {
        $file = $files->findById($id);
        if ($file === null || ($file['status'] ?? '') !== 'PENDING') {
            continue;
        }
        if ($files->updateStatus($id, 'APPROVED', $userId)) {
            $audit->record($userId, $user['email'] ?? '', $ip, 'FILE_APPROVED', 'file', $id, $file['original_path'], null, []);
            $count++;
        }
    }
    Session::flash('success', $count . ' file(s) approved.');
    redirect_queue();
}

// ── Reject ────────────────────────────────────────────────────
if ($uri === '/queue/reject') {
    $ids = parse_ids();
    $count = 0;
    foreach ($ids as $id) {
        $file = $files->findById($id);
        if ($file === null || !in_array($file['status'] ?? '', ['PENDING', 'FLAGGED'], true)) {
            continue;
        }
        if ($files->updateStatus($id, 'REJECTED', $userId)) {
            $audit->record($userId, $user['email'] ?? '', $ip, 'FILE_REJECTED', 'file', $id, $file['original_path'], null, []);
            $count++;
        }
    }
    Session::flash('success', $count . ' file(s) rejected.');
    redirect_queue();
}

// ── Unapprove (APPROVED → PENDING) ────────────────────────────
if ($uri === '/queue/unapprove') {
    $ids = parse_ids();
    $count = 0;
    foreach ($ids as $id) {
        $file = $files->findById($id);
        if ($file === null || ($file['status'] ?? '') !== 'APPROVED') {
            continue;
        }
        if ($files->unapprove($id)) {
            $audit->record($userId, $user['email'] ?? '', $ip, 'FILE_UNAPPROVED', 'file', $id, $file['original_path'], null, []);
            $count++;
        }
    }
    Session::flash('success', $count . ' file(s) returned to pending.');
    redirect_queue();
}

// ── Remove from queue (not executed) ──────────────────────────
if ($uri === '/queue/remove') {
    $ids = parse_ids();
    $count = 0;
    $cache = new \MediaManager\Services\MediaCacheService(
        projectRoot: dirname(__DIR__, 2)
    );
    foreach ($ids as $id) {
        $file = $files->findById($id);
        if ($file === null) {
            continue;
        }
        if (!in_array($file['status'] ?? '', ['PENDING', 'FLAGGED', 'REJECTED', 'APPROVED'], true)) {
            continue;
        }
        $path = (string) ($file['original_path'] ?? '');
        if ($files->deleteRemovable($id)) {
            $cache->invalidate($id);
            $audit->record($userId, $user['email'] ?? '', $ip, 'FILE_REMOVED', 'file', $id, $path, null, [
                'prior_status' => $file['status'] ?? null,
            ]);
            $count++;
        }
    }
    Session::flash('success', $count . ' file(s) removed from queue.');
    redirect_queue();
}

// ── Batch: approve | reject | flag ───────────────────────────
if ($uri === '/queue/batch') {
    $action = $_POST['action'] ?? '';
    $ids    = parse_ids();
    $count  = 0;

    $statusMap = [
        'approve'   => 'APPROVED',
        'reject'    => 'REJECTED',
        'flag'      => 'FLAGGED',
        'unapprove' => 'PENDING',
    ];
    if (!isset($statusMap[$action])) {
        Session::flash('error', 'Unknown batch action.');
        redirect_queue();
    }

    $newStatus = $statusMap[$action];
    $auditAction = match ($action) {
        'approve'   => 'FILE_APPROVED',
        'reject'    => 'FILE_REJECTED',
        'flag'      => 'FILE_FLAGGED',
        'unapprove' => 'FILE_UNAPPROVED',
        default     => 'FILE_UPDATED',
    };

    foreach ($ids as $id) {
        $file = $files->findById($id);
        if ($file === null) {
            continue;
        }
        if ($action === 'approve' && ($file['status'] ?? '') !== 'PENDING') {
            continue;
        }
        if ($action === 'unapprove') {
            if (($file['status'] ?? '') !== 'APPROVED') {
                continue;
            }
            if ($files->unapprove($id)) {
                $audit->record($userId, $user['email'] ?? '', $ip, $auditAction, 'file', $id, $file['original_path'], null, []);
                $count++;
            }
            continue;
        }
        if ($action === 'reject' && !in_array($file['status'] ?? '', ['PENDING', 'FLAGGED'], true)) {
            continue;
        }
        if ($files->updateStatus($id, $newStatus, $userId)) {
            $audit->record($userId, $user['email'] ?? '', $ip, $auditAction, 'file', $id, $file['original_path'], null, []);
            $count++;
        }
    }

    Session::flash('success', $count . ' file(s) updated.');
    redirect_queue();
}

// ── Edit proposed names ───────────────────────────────────────
if ($uri === '/queue/edit') {
    $id = (int) ($_POST['id'] ?? 0);
    $file = $id > 0 ? $files->findById($id) : null;

    if ($file === null) {
        Session::flash('error', 'File not found.');
        redirect_queue();
    }

    $proposedDir      = trim($_POST['proposed_dir'] ?? '');
    $proposedFilename = trim($_POST['proposed_filename'] ?? '');
    $showId           = ($_POST['show_id'] ?? '') !== '' ? (int) $_POST['show_id'] : null;
    $mediaTypeId      = ($_POST['media_type_id'] ?? '') !== '' ? (int) $_POST['media_type_id'] : null;
    $fileDate         = trim($_POST['file_date'] ?? '') ?: null;
    $fileTime         = trim($_POST['file_time'] ?? '') ?: null;

    if ($proposedDir === '' || $proposedFilename === '') {
        $suggester = new \MediaManager\Services\FileEditSuggester();
        $suggest   = $suggester->suggest($file);
        if ($proposedDir === '') {
            $proposedDir = trim((string) ($suggest['proposed_dir'] ?? ''));
        }
        if ($proposedFilename === '') {
            $proposedFilename = trim((string) ($suggest['proposed_filename'] ?? ''));
        }
        if ($showId === null && !empty($suggest['show_id'])) {
            $showId = (int) $suggest['show_id'];
        }
        if ($mediaTypeId === null && !empty($suggest['media_type_id'])) {
            $mediaTypeId = (int) $suggest['media_type_id'];
        }
        if ($fileDate === null && !empty($suggest['file_date'])) {
            $fileDate = (string) $suggest['file_date'];
        }
        if ($fileTime === null && !empty($suggest['file_time'])) {
            $fileTime = (string) $suggest['file_time'];
        }
    }

    if ($proposedDir === '' || $proposedFilename === '') {
        Session::flash('error', 'Proposed directory and filename are required.');
        redirect_queue();
    }

    $needsSplit  = isset($_POST['needs_split']);
    $splitNotes  = trim($_POST['split_notes'] ?? '');
    $wasSplit    = !empty($file['needs_split']);
    $removedJobs = [];

    if ($wasSplit && !$needsSplit) {
        $splitRepo   = new SplitQueueRepository();
        $removedJobs = remove_active_split_jobs(
            $splitRepo,
            $audit,
            $id,
            $userId,
            $user['email'] ?? '',
            $ip,
            (string) $file['original_path']
        );
    }

    $files->updateProposed(
        $id,
        $proposedDir,
        $proposedFilename,
        $showId,
        $mediaTypeId,
        $fileDate,
        $fileTime,
        $needsSplit,
        $splitNotes
    );

    $audit->record(
        $userId,
        $user['email'] ?? '',
        $ip,
        'FILE_EDITED',
        'file',
        $id,
        $file['original_path'],
        $proposedDir . '/' . $proposedFilename,
        [
            'proposed_dir'      => $proposedDir,
            'proposed_filename' => $proposedFilename,
            'needs_split'       => $needsSplit,
            'split_notes'       => $splitNotes,
        ]
    );

    if (!$wasSplit && $needsSplit) {
        $audit->record(
            $userId,
            $user['email'] ?? '',
            $ip,
            'FILE_SPLIT_FLAGGED',
            'file',
            $id,
            $file['original_path'],
            null,
            ['split_notes' => $splitNotes, 'source' => 'edit_modal']
        );
    } elseif ($wasSplit && !$needsSplit) {
        $audit->record(
            $userId,
            $user['email'] ?? '',
            $ip,
            'FILE_SPLIT_CLEARED',
            'file',
            $id,
            $file['original_path'],
            null,
            ['removed_split_jobs' => count($removedJobs)]
        );
    }

    $message = 'Proposed path updated.';
    if ($removedJobs !== []) {
        $message .= ' ' . count($removedJobs) . ' active split job(s) removed.';
    }
    Session::flash('success', $message);
    redirect_queue();
}

// ── Adopt classifier or legacy map proposal ───────────────────
if ($uri === '/queue/adopt-proposal') {
    $id     = (int) ($_POST['id'] ?? 0);
    $source = trim($_POST['source'] ?? '');
    $file   = $id > 0 ? $files->findById($id) : null;

    if ($file === null || !in_array($file['status'] ?? '', ['PENDING', 'FLAGGED'], true)) {
        Session::flash('error', 'File not eligible for proposal switch.');
        redirect_queue();
    }

    if (!in_array($source, ['classifier', 'legacy_map'], true)) {
        Session::flash('error', 'Invalid proposal source.');
        redirect_queue();
    }

    if (!$files->adoptProposalSource($id, $source)) {
        Session::flash('error', 'Could not switch proposal — alternate not available.');
        redirect_queue();
    }

    $audit->record($userId, $user['email'] ?? '', $ip, 'FILE_PROPOSAL_ADOPTED', 'file', $id, null, null, [
        'source' => $source,
    ]);
    Session::flash('success', 'Active proposal updated.');
    redirect_queue();
}

// ── Clear split flag (editor) ─────────────────────────────────
if ($uri === '/queue/clear-split') {
    $id   = (int) ($_POST['id'] ?? 0);
    $file = $id > 0 ? $files->findById($id) : null;

    if ($file === null || empty($file['needs_split'])) {
        Session::flash('error', 'File is not flagged for split.');
        redirect_queue();
    }

    if (!in_array($file['status'] ?? '', ['PENDING', 'FLAGGED', 'APPROVED'], true)) {
        Session::flash('error', 'File is not eligible for split flag changes.');
        redirect_queue();
    }

    $splitRepo   = new SplitQueueRepository();
    $removedJobs = remove_active_split_jobs(
        $splitRepo,
        $audit,
        $id,
        $userId,
        $user['email'] ?? '',
        $ip,
        (string) $file['original_path']
    );

    $files->updateSplitFlag($id, false, '');

    $audit->record(
        $userId,
        $user['email'] ?? '',
        $ip,
        'FILE_SPLIT_CLEARED',
        'file',
        $id,
        $file['original_path'],
        null,
        ['removed_split_jobs' => count($removedJobs), 'source' => 'queue_row']
    );

    $message = 'Split flag cleared.';
    if ($removedJobs !== []) {
        $message .= ' ' . count($removedJobs) . ' active split job(s) removed.';
    }
    Session::flash('success', $message);
    redirect_queue();
}

// ── Add to split queue (admin) ────────────────────────────────
if ($uri === '/queue/add-split') {
    if (!Auth::isAdmin()) {
        Session::flash('error', 'Admin access required.');
        redirect_queue();
    }

    $splitRepo = new SplitQueueRepository();
    $ids       = parse_ids();
    $count     = 0;

    foreach ($ids as $id) {
        $file = $files->findById($id);
        if ($file === null || empty($file['needs_split'])) {
            continue;
        }
        if ($splitRepo->hasActiveForFile($id)) {
            continue;
        }
        try {
            $jobId = $splitRepo->create($id, $userId);
            $audit->record($userId, $user['email'] ?? '', $ip, 'SPLIT_QUEUED', 'split_queue', $jobId, $file['original_path'], null, [
                'file_id' => $id,
            ]);
            $count++;
        } catch (PDOException) {
            // unique constraint — already queued
        }
    }

    Session::flash('success', $count . ' file(s) added to split queue.');
    redirect_queue();
}

http_response_code(404);
exit;
