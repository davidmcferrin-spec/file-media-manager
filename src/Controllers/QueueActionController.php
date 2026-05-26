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

// ── Batch: approve | reject | flag ───────────────────────────
if ($uri === '/queue/batch') {
    $action = $_POST['action'] ?? '';
    $ids    = parse_ids();
    $count  = 0;

    $statusMap = [
        'approve' => 'APPROVED',
        'reject'  => 'REJECTED',
        'flag'    => 'FLAGGED',
    ];
    if (!isset($statusMap[$action])) {
        Session::flash('error', 'Unknown batch action.');
        redirect_queue();
    }

    $newStatus = $statusMap[$action];
    $auditAction = match ($action) {
        'approve' => 'FILE_APPROVED',
        'reject'  => 'FILE_REJECTED',
        'flag'    => 'FILE_FLAGGED',
        default   => 'FILE_UPDATED',
    };

    foreach ($ids as $id) {
        $file = $files->findById($id);
        if ($file === null) {
            continue;
        }
        if ($action === 'approve' && ($file['status'] ?? '') !== 'PENDING') {
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

    $files->updateProposed($id, $proposedDir, $proposedFilename, $showId, $mediaTypeId, $fileDate, $fileTime);

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
        ]
    );

    Session::flash('success', 'Proposed path updated.');
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
