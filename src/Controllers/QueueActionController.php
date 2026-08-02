<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\FileRepository;
use MediaManager\Repositories\MediaTypeRepository;
use MediaManager\Repositories\ShowRepository;
use MediaManager\Repositories\SplitQueueRepository;
use MediaManager\Repositories\CaptionExtractJobRepository;
use MediaManager\Services\CaptionExtractJobService;
use MediaManager\Services\GlueGroupService;
use MediaManager\Services\ProposalPathBuilder;
use MediaManager\Services\SplitPrepService;
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
    if (!is_string($return)
        || (!str_starts_with($return, '/queue') && !str_starts_with($return, '/glue'))
    ) {
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
        $prep = (new SplitPrepService())->onMarkedForSplit($id, $userId, true);
        $audit->record(
            $userId,
            $user['email'] ?? '',
            $ip,
            'FILE_SPLIT_FLAGGED',
            'file',
            $id,
            $file['original_path'],
            null,
            [
                'split_notes'    => $splitNotes,
                'source'         => 'edit_modal',
                'split_queue_id' => $prep['split_queue_id'],
                'caption_job_id' => $prep['caption_job_id'],
                'audio_job_id'   => $prep['audio_job_id'],
            ]
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
    if (!$wasSplit && $needsSplit) {
        $message .= ' Split prep queued (caption extract if needed + audio levels).';
    }
    if ($removedJobs !== []) {
        $message .= ' ' . count($removedJobs) . ' active split job(s) removed.';
    }
    Session::flash('success', $message);
    redirect_queue();
}

// ── Bulk edit show / media type / date ────────────────────────
if ($uri === '/queue/bulk-edit') {
    $ids = parse_ids();
    if ($ids === []) {
        Session::flash('error', 'Select at least one file.');
        redirect_queue();
    }

    $postedShowId = trim((string) ($_POST['show_id'] ?? ''));
    $postedTypeId = trim((string) ($_POST['media_type_id'] ?? ''));
    $postedDate   = trim((string) ($_POST['file_date'] ?? ''));

    $newShowId = $postedShowId !== '' ? (int) $postedShowId : null;
    $newTypeId = $postedTypeId !== '' ? (int) $postedTypeId : null;
    $dateProvided = $postedDate !== '';
    $newDate = null;
    if ($dateProvided) {
        $newDate = ProposalPathBuilder::normalizeDateInput($postedDate);
        if ($newDate === null) {
            Session::flash('error', 'Invalid date. Use YYYY-MM-DD or YYYYMMDD.');
            redirect_queue();
        }
    }

    if ($newShowId === null && $newTypeId === null && !$dateProvided) {
        Session::flash('error', 'Set at least one of show, type, or date.');
        redirect_queue();
    }

    $showRepo = new ShowRepository();
    $typeRepo = new MediaTypeRepository();
    $showCache = [];
    $typeCache = [];

    $updated = 0;
    $skipped = 0;

    foreach ($ids as $id) {
        $file = $files->findById($id);
        if ($file === null || !in_array($file['status'] ?? '', ['PENDING', 'FLAGGED'], true)) {
            $skipped++;
            continue;
        }

        $showId = $newShowId ?? (isset($file['show_id']) && $file['show_id'] !== null && $file['show_id'] !== ''
            ? (int) $file['show_id'] : null);
        $typeId = $newTypeId ?? (isset($file['media_type_id']) && $file['media_type_id'] !== null && $file['media_type_id'] !== ''
            ? (int) $file['media_type_id'] : null);
        $fileDate = $dateProvided ? $newDate : (trim((string) ($file['file_date'] ?? '')) ?: null);
        $fileTime = trim((string) ($file['file_time'] ?? '')) ?: null;

        if ($showId === null || $typeId === null || $fileDate === null) {
            $skipped++;
            continue;
        }

        if (!isset($showCache[$showId])) {
            $showCache[$showId] = $showRepo->findById($showId);
        }
        if (!isset($typeCache[$typeId])) {
            $typeCache[$typeId] = $typeRepo->findById($typeId);
        }
        $show = $showCache[$showId];
        $type = $typeCache[$typeId];
        if ($show === null || $type === null) {
            $skipped++;
            continue;
        }

        $guest = ProposalPathBuilder::guestFromProposed(
            (string) ($file['proposed_filename'] ?? '')
        );
        $built = ProposalPathBuilder::build(
            (string) $show['abbreviation'],
            $fileDate,
            $fileTime,
            (string) $type['abbreviation'],
            (string) ($type['folder_name'] ?? $type['name']),
            (string) ($file['original_filename'] ?? $file['original_path'] ?? ''),
            $guest
        );
        if ($built === null) {
            $skipped++;
            continue;
        }

        $files->updateProposed(
            $id,
            $built['proposed_dir'],
            $built['proposed_filename'],
            $showId,
            $typeId,
            $fileDate,
            $fileTime
        );

        $audit->record(
            $userId,
            $user['email'] ?? '',
            $ip,
            'FILE_BULK_EDITED',
            'file',
            $id,
            $file['original_path'],
            $built['proposed_dir'] . '/' . $built['proposed_filename'],
            [
                'show_id'           => $showId,
                'media_type_id'     => $typeId,
                'file_date'         => $fileDate,
                'proposed_dir'      => $built['proposed_dir'],
                'proposed_filename' => $built['proposed_filename'],
                'fields'            => array_values(array_filter([
                    $newShowId !== null ? 'show_id' : null,
                    $newTypeId !== null ? 'media_type_id' : null,
                    $dateProvided ? 'file_date' : null,
                ])),
            ]
        );
        $updated++;
    }

    $message = $updated . ' file(s) updated.';
    if ($skipped > 0) {
        $message .= ' ' . $skipped . ' skipped (not pending/flagged or missing show/type/date).';
    }
    Session::flash($updated > 0 ? 'success' : 'error', $message);
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

    $ids = parse_ids();
    $eligible = [];
    foreach ($ids as $id) {
        $file = $files->findById($id);
        if ($file === null || empty($file['needs_split'])) {
            continue;
        }
        $eligible[] = $id;
    }

    $prep = (new SplitPrepService())->onMarkedForSplitMany($eligible, $userId, true);
    foreach ($eligible as $id) {
        $file = $files->findById($id);
        if ($file === null) {
            continue;
        }
        $active = (new SplitQueueRepository())->findActiveForFile($id);
        if ($active !== null) {
            $audit->record(
                $userId,
                $user['email'] ?? '',
                $ip,
                'SPLIT_QUEUED',
                'split_queue',
                (int) $active['id'],
                $file['original_path'],
                null,
                [
                    'file_id'        => $id,
                    'caption_files'  => $prep['caption_files'],
                    'audio_jobs'     => $prep['audio_jobs'],
                    'source'         => 'queue_add_split',
                ]
            );
        }
    }

    Session::flash(
        'success',
        count($eligible) . ' file(s) prepared for split'
        . ($prep['caption_files'] > 0 ? '; caption extract for ' . $prep['caption_files'] : '')
        . ($prep['audio_jobs'] > 0 ? '; audio levels for ' . $prep['audio_jobs'] : '')
        . '.'
    );
    redirect_queue();
}

// ── Mark selected files as a glue group ───────────────────────
if ($uri === '/queue/mark-glue') {
    $ids = parse_ids();
    $result = (new GlueGroupService($files))->markManualGroup($ids);
    if (!$result['ok']) {
        Session::flash('error', $result['message']);
        redirect_queue();
    }

    $audit->record($userId, $user['email'] ?? '', $ip, 'FILE_GLUE_MARKED', 'file', $ids[0] ?? 0, null, null, [
        'file_ids' => $ids,
        'count'    => $result['count'],
    ]);
    Session::flash('success', $result['message']);
    redirect_queue();
}

// ── Clear glue flag(s) ────────────────────────────────────────
if ($uri === '/queue/clear-glue') {
    $ids = parse_ids();
    $count = (new GlueGroupService($files))->clearGlue($ids);
    if ($count === 0) {
        Session::flash('error', 'No eligible glue flags to clear.');
        redirect_queue();
    }

    $audit->record($userId, $user['email'] ?? '', $ip, 'FILE_GLUE_CLEARED', 'file', $ids[0] ?? 0, null, null, [
        'file_ids' => $ids,
        'count'    => $count,
    ]);
    Session::flash('success', $count . ' file(s) cleared from glue.');
    redirect_queue();
}

// ── Extract captions — enqueue background job ─────────────────
if ($uri === '/queue/extract-captions') {
    if (!Auth::isAdmin()) {
        Session::flash('error', 'Admin access required to queue caption extract.');
        redirect_queue();
    }

    $ids = parse_ids();
    if ($ids === []) {
        $single = (int) ($_POST['id'] ?? 0);
        if ($single > 0) {
            $ids = [$single];
        }
    }
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $ids),
        static fn (int $id): bool => $id > 0
    )));
    if ($ids === []) {
        Session::flash('error', 'Select at least one file.');
        redirect_queue();
    }

    $jobRepo = new CaptionExtractJobRepository();
    $running = $jobRepo->findActive();
    if ($running !== null) {
        $jobId = (int) $running['id'];
        $merged = $jobRepo->prependPriority($jobId, $ids);
        $audit->record($userId, $user['email'] ?? '', $ip, 'CAPTION_EXTRACT_PRIORITIZE', 'caption_extract_job', $jobId, null, null, [
            'scope' => 'catalog',
            'moved' => array_slice($ids, 0, 50),
            'count' => count($ids),
            'priority_len' => count($merged),
        ]);
        Session::flash(
            'success',
            count($ids) . ' clip(s) moved to top of running caption extract #' . $jobId . '.'
        );
        header('Location: /captions/' . $jobId);
        exit;
    }

    $jobId = $jobRepo->create($userId, 'selected', $ids);
    $audit->record($userId, $user['email'] ?? '', $ip, 'CAPTION_EXTRACT_QUEUED', 'caption_extract_job', $jobId, null, null, [
        'scope' => 'selected',
        'count' => count($ids),
        'ids'   => array_slice($ids, 0, 50),
    ]);

    $projectRoot = dirname(__DIR__, 2);
    $logFile = CaptionExtractJobService::logPathForJob($jobId, $projectRoot);
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $modeNote = \MediaManager\Support\WorkerMode::isDaemon()
        ? 'Queued from Catalog for caption-extract worker'
        : 'Queued from Catalog — spawning worker';
    @file_put_contents(
        $logFile,
        '[' . gmdate('Y-m-d\TH:i:s\Z') . "] INFO {$modeNote} count=" . count($ids) . "\n",
        FILE_APPEND | LOCK_EX
    );

    if (\MediaManager\Support\WorkerMode::shouldSpawn()) {
        $phpBin = PHP_BINARY;
        $script = $projectRoot . '/scripts/caption_extract.php';
        $flags = '--job-id=' . $jobId;
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen(
                'start /B "" ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($script)
                . ' ' . $flags . ' >> ' . escapeshellarg($logFile) . ' 2>&1',
                'r'
            ));
        } else {
            exec(sprintf(
                '%s %s %s >> %s 2>&1 &',
                escapeshellarg($phpBin),
                escapeshellarg($script),
                $flags,
                escapeshellarg($logFile)
            ));
        }
    }

    $verb = \MediaManager\Support\WorkerMode::isDaemon() ? 'queued' : 'started';
    Session::flash('success', 'Caption extract job #' . $jobId . ' ' . $verb . ' for ' . count($ids) . ' file(s).');
    header('Location: /captions/' . $jobId);
    exit;
}

http_response_code(404);
exit;
