<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\FileRepository;
use MediaManager\Repositories\ShowRepository;
use MediaManager\Repositories\SplitAudioJobRepository;
use MediaManager\Repositories\SplitQueueRepository;
use MediaManager\Repositories\SystemRepository;
use MediaManager\Services\AudioLevelMapService;
use MediaManager\Services\CaptionSplitSuggester;
use MediaManager\Services\DateNormalizer;
use MediaManager\Services\ScheduleSplitSuggester;
use MediaManager\Services\SplitAudioJobService;
use MediaManager\Services\SplitMediaService;
use MediaManager\Services\SplitPrepService;
use MediaManager\Services\SrtCaptionParser;
use MediaManager\Support\WorkerMode;
use PDOException;

Auth::requireAdmin();

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$splitRepo     = new SplitQueueRepository();
$showRepo      = new ShowRepository();
$fileRepo      = new FileRepository();
$audioJobRepo  = new SplitAudioJobRepository();
$audit         = new AuditRepository();
$projectRoot   = dirname(__DIR__, 2);

/**
 * Enqueue split audio work for the systemd / spawn worker (no FFmpeg in Apache).
 */
function spawn_split_audio_worker(int $jobId, string $projectRoot): void
{
    $logFile = SplitAudioJobService::logPathForJob($jobId, $projectRoot);
    $logDir  = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $modeNote = WorkerMode::isDaemon()
        ? 'Queued for media-manager-split-audio.service'
        : 'Spawned one-shot worker';
    @file_put_contents(
        $logFile,
        '[' . gmdate('Y-m-d\TH:i:s\Z') . "] INFO {$modeNote} job_id={$jobId}\n",
        FILE_APPEND | LOCK_EX
    );

    if (!WorkerMode::shouldSpawn()) {
        return;
    }

    $phpBin = PHP_BINARY;
    $script = $projectRoot . '/scripts/split_audio.php';
    $flags = '--job-id=' . $jobId;

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        pclose(popen(
            'start /B "" ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($script)
            . ' ' . $flags . ' >> ' . escapeshellarg($logFile) . ' 2>&1',
            'r'
        ));
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

function split_status_query(?string $status): string
{
    if ($status === null || $status === '') {
        return '';
    }

    return '?status=' . rawurlencode($status);
}

/**
 * Air date/time for a segment from file clock + mark-in offset.
 *
 * @return array{date: string, time: string}
 */
function split_derive_air(?string $fileDate, ?string $fileTime, float $offsetSeconds): array
{
    $dateDigits = preg_replace('/\D/', '', (string) $fileDate) ?? '';
    $startMin = DateNormalizer::timeToMinutes($fileTime);
    if (strlen($dateDigits) !== 8 || !DateNormalizer::isValidDate($dateDigits) || $startMin === null) {
        return ['date' => '', 'time' => ''];
    }

    $offsetMin = (int) floor(max(0.0, $offsetSeconds) / 60);
    $totalMin = $startMin + $offsetMin;
    $dayAdd = intdiv($totalMin, 24 * 60);
    $todMin = $totalMin % (24 * 60);

    $dt = \DateTimeImmutable::createFromFormat('Ymd', $dateDigits);
    if ($dt === false) {
        return ['date' => '', 'time' => ''];
    }
    if ($dayAdd > 0) {
        $dt = $dt->modify('+' . $dayAdd . ' days');
    }

    return [
        'date' => $dt->format('Ymd'),
        'time' => DateNormalizer::minutesToHhmm($todMin),
    ];
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

        if ($notes !== '' && trim((string) ($file['split_notes'] ?? '')) === '') {
            $fileRepo->updateSplitFlag($fileId, true, $notes);
        }

        $prep = (new SplitPrepService())->onMarkedForSplit($fileId, (int) Auth::id(), true);
        $id = $prep['split_queue_id'];
        if ($id === null) {
            Session::flash('error', 'Could not add file to split queue.');
            header('Location: /split');
            exit;
        }

        split_audit($audit, 'SPLIT_QUEUED', $id, [
            'file_id'        => $fileId,
            'caption_job_id' => $prep['caption_job_id'],
            'audio_job_id'   => $prep['audio_job_id'],
            'audio_kind'     => $prep['audio_kind'],
            'source'         => 'split_create',
        ]);
        $audioFlash = '';
        if (!empty($prep['audio_job_id'])) {
            $audioFlash = ($prep['audio_kind'] ?? '') === 'suggest'
                ? '; audio suggest queued'
                : '; audio levels queued';
        }
        Session::flash(
            'success',
            'File added to split queue'
            . ($prep['needs_caption'] ? '; caption extract queued' : '')
            . $audioFlash
            . '.'
        );
        header('Location: /split/' . $id);
        exit;
    }

    if ($uri === '/split/update') {
        $id           = (int) ($_POST['id'] ?? 0);
        $notes        = trim($_POST['notes'] ?? '');
        $status       = $_POST['status'] ?? 'PENDING';
        $statusFilter = trim((string) ($_POST['status_filter'] ?? ''));
        $redirect     = trim((string) ($_POST['redirect'] ?? ''));
        $nextId       = (int) ($_POST['next_id'] ?? 0);
        $item         = $id > 0 ? $splitRepo->findById($id) : null;

        if ($item === null) {
            Session::flash('error', 'Split job not found.');
            header('Location: /split');
            exit;
        }

        if (!in_array($status, ['PENDING', 'IN_PROGRESS', 'DONE', 'FAILED'], true)) {
            $status = 'PENDING';
        }
        if (!in_array($statusFilter, ['PENDING', 'IN_PROGRESS', 'DONE', 'FAILED'], true)) {
            $statusFilter = '';
        }

        $segments = parse_split_segments($_POST);
        $splitRepo->update($id, $segments, $notes, $status);
        split_audit($audit, 'SPLIT_UPDATED', $id, [
            'status'         => $status,
            'segment_count'  => count($segments),
        ]);
        Session::flash('success', 'Split job saved.');

        $qs = split_status_query($statusFilter !== '' ? $statusFilter : null);
        if ($redirect === 'next' && $nextId > 0 && $splitRepo->findById($nextId) !== null) {
            header('Location: /split/' . $nextId . $qs);
        } else {
            header('Location: /split/' . $id . $qs);
        }
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

    if ($uri === '/split/suggest-captions') {
        $id           = (int) ($_POST['id'] ?? 0);
        $statusFilter = trim((string) ($_POST['status_filter'] ?? ''));
        if (!in_array($statusFilter, ['PENDING', 'IN_PROGRESS', 'DONE', 'FAILED'], true)) {
            $statusFilter = '';
        }
        $qs   = split_status_query($statusFilter !== '' ? $statusFilter : null);
        $item = $id > 0 ? $splitRepo->findById($id) : null;
        if ($item === null) {
            Session::flash('error', 'Split job not found.');
            header('Location: /split');
            exit;
        }

        $file = $fileRepo->findById((int) $item['file_id']);
        if ($file === null) {
            Session::flash('error', 'Source file not found.');
            header('Location: /split/' . $id . $qs);
            exit;
        }

        $srtPath = str_replace('\\', '/', (string) ($file['srt_path'] ?? ''));
        if ($srtPath === '' || !is_readable($srtPath)) {
            Session::flash('error', 'No SRT available. Extract captions from the Catalog first.');
            header('Location: /split/' . $id . $qs);
            exit;
        }

        $cues = SrtCaptionParser::parseFile($srtPath);
        $flagSeconds = (int) ((new SystemRepository())->get('split_flag_threshold_seconds')
            ?? env('SPLIT_FLAG_THRESHOLD_SECONDS', ScheduleSplitSuggester::DEFAULT_FLAG_THRESHOLD_SECONDS));
        if ($flagSeconds < 1) {
            $flagSeconds = ScheduleSplitSuggester::DEFAULT_FLAG_THRESHOLD_SECONDS;
        }
        $suggestion = (new CaptionSplitSuggester($flagSeconds))->suggest(
            $cues,
            isset($file['duration_seconds']) ? (float) $file['duration_seconds'] : null,
            isset($file['file_date']) ? (string) $file['file_date'] : null,
            isset($file['file_time']) ? (string) $file['file_time'] : null,
        );

        if ($suggestion['segments'] === []) {
            Session::flash('error', $suggestion['notes'] !== '' ? $suggestion['notes'] : 'No caption-based segments found.');
            header('Location: /split/' . $id . $qs);
            exit;
        }

        $segments = [];
        foreach ($suggestion['segments'] as $seg) {
            $segments[] = [
                'start'   => $seg['start'],
                'end'     => $seg['end'],
                'show_id' => $seg['show_id'],
                'label'   => $seg['label'],
            ];
        }

        $notes = trim((string) ($item['notes'] ?? ''));
        $notes = trim($notes . "\n\n" . $suggestion['notes']);
        $splitRepo->update($id, $segments, $notes, (string) ($item['status'] ?? 'PENDING'));
        split_audit($audit, 'SPLIT_CAPTION_SUGGEST', $id, [
            'segment_count' => count($segments),
            'gap_count'     => $suggestion['gap_count'],
        ]);
        Session::flash(
            'success',
            'Filled ' . count($segments) . ' segment(s) from captions (≥'
            . (int) (CaptionSplitSuggester::MIN_GAP_SECONDS / 60)
            . ' min silence gaps). Review before saving.'
        );
        header('Location: /split/' . $id . $qs);
        exit;
    }

    if ($uri === '/split/suggest-audio') {
        $id           = (int) ($_POST['id'] ?? 0);
        $statusFilter = trim((string) ($_POST['status_filter'] ?? ''));
        if (!in_array($statusFilter, ['PENDING', 'IN_PROGRESS', 'DONE', 'FAILED'], true)) {
            $statusFilter = '';
        }
        $qs   = split_status_query($statusFilter !== '' ? $statusFilter : null);
        $item = $id > 0 ? $splitRepo->findById($id) : null;
        if ($item === null) {
            Session::flash('error', 'Split job not found.');
            header('Location: /split');
            exit;
        }

        $file = $fileRepo->findById((int) $item['file_id']);
        $mediaPath = str_replace('\\', '/', (string) ($file['original_path'] ?? ''));
        if ($file === null || $mediaPath === '' || !is_readable($mediaPath)) {
            Session::flash('error', 'Source media is not readable on disk.');
            header('Location: /split/' . $id . $qs);
            exit;
        }
        if (trim((string) ($file['codec_audio'] ?? '')) === '') {
            Session::flash('error', 'No audio stream on file — cannot suggest from audio.');
            header('Location: /split/' . $id . $qs);
            exit;
        }

        $active = $audioJobRepo->findActiveForFile((int) $file['id']);
        if ($active !== null) {
            Session::flash(
                'error',
                'Audio analysis already '
                . strtolower((string) ($active['status'] ?? 'queued'))
                . ' for this file (job #' . (int) $active['id'] . ', '
                . (string) ($active['kind'] ?? '') . '). Wait or cancel it first.'
            );
            header('Location: /split/' . $id . $qs);
            exit;
        }

        try {
            $jobId = $audioJobRepo->create(
                $id,
                (int) $file['id'],
                SplitAudioJobRepository::KIND_SUGGEST,
                (int) Auth::id()
            );
        } catch (PDOException $e) {
            $msg = $audioJobRepo->isUniqueViolation($e)
                ? 'An audio analysis job is already active for this file.'
                : 'Could not queue audio suggest: ' . $e->getMessage();
            Session::flash('error', $msg);
            header('Location: /split/' . $id . $qs);
            exit;
        }

        spawn_split_audio_worker($jobId, $projectRoot);
        split_audit($audit, 'SPLIT_AUDIO_SUGGEST_QUEUED', $id, [
            'audio_job_id' => $jobId,
            'kind'         => SplitAudioJobRepository::KIND_SUGGEST,
        ]);
        $verb = WorkerMode::isDaemon() ? 'queued' : 'started';
        Session::flash(
            'success',
            'Audio suggest job #' . $jobId . ' ' . $verb
            . ' — background worker will fill segments (no Apache wait).'
        );
        header('Location: /split/' . $id . $qs);
        exit;
    }

    if ($uri === '/split/build-audio-map') {
        $id           = (int) ($_POST['id'] ?? 0);
        $statusFilter = trim((string) ($_POST['status_filter'] ?? ''));
        $wantJson     = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || (($_POST['format'] ?? '') === 'json');
        if (!in_array($statusFilter, ['PENDING', 'IN_PROGRESS', 'DONE', 'FAILED'], true)) {
            $statusFilter = '';
        }
        $qs   = split_status_query($statusFilter !== '' ? $statusFilter : null);
        $item = $id > 0 ? $splitRepo->findById($id) : null;
        if ($item === null) {
            if ($wantJson) {
                header('Content-Type: application/json');
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Split job not found.'], JSON_THROW_ON_ERROR);
                exit;
            }
            Session::flash('error', 'Split job not found.');
            header('Location: /split');
            exit;
        }

        $file = $fileRepo->findById((int) $item['file_id']);
        $mediaPath = str_replace('\\', '/', (string) ($file['original_path'] ?? ''));
        if ($file === null || $mediaPath === '' || !is_readable($mediaPath)) {
            if ($wantJson) {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Source media is not readable.'], JSON_THROW_ON_ERROR);
                exit;
            }
            Session::flash('error', 'Source media is not readable on disk.');
            header('Location: /split/' . $id . $qs);
            exit;
        }
        if (trim((string) ($file['codec_audio'] ?? '')) === '') {
            if ($wantJson) {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'No audio stream on file.'], JSON_THROW_ON_ERROR);
                exit;
            }
            Session::flash('error', 'No audio stream on file — cannot build audio levels.');
            header('Location: /split/' . $id . $qs);
            exit;
        }

        $active = $audioJobRepo->findActiveForFile((int) $file['id']);
        if ($active !== null) {
            $msg = 'Audio analysis already active (job #' . (int) $active['id']
                . ', ' . (string) ($active['kind'] ?? '') . ').';
            if ($wantJson) {
                header('Content-Type: application/json');
                http_response_code(409);
                echo json_encode([
                    'ok'           => false,
                    'error'        => $msg,
                    'audio_job_id' => (int) $active['id'],
                    'status'       => (string) ($active['status'] ?? ''),
                    'kind'         => (string) ($active['kind'] ?? ''),
                ], JSON_THROW_ON_ERROR);
                exit;
            }
            Session::flash('error', $msg);
            header('Location: /split/' . $id . $qs);
            exit;
        }

        try {
            $jobId = $audioJobRepo->create(
                $id,
                (int) $file['id'],
                SplitAudioJobRepository::KIND_LEVELS,
                (int) Auth::id()
            );
        } catch (PDOException $e) {
            $msg = $audioJobRepo->isUniqueViolation($e)
                ? 'An audio analysis job is already active for this file.'
                : 'Could not queue audio levels: ' . $e->getMessage();
            if ($wantJson) {
                header('Content-Type: application/json');
                http_response_code(409);
                echo json_encode(['ok' => false, 'error' => $msg], JSON_THROW_ON_ERROR);
                exit;
            }
            Session::flash('error', $msg);
            header('Location: /split/' . $id . $qs);
            exit;
        }

        spawn_split_audio_worker($jobId, $projectRoot);
        split_audit($audit, 'SPLIT_AUDIO_MAP_QUEUED', $id, [
            'audio_job_id' => $jobId,
            'kind'         => SplitAudioJobRepository::KIND_LEVELS,
        ]);

        if ($wantJson) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok'           => true,
                'queued'       => true,
                'audio_job_id' => $jobId,
                'status'       => 'PENDING',
                'kind'         => SplitAudioJobRepository::KIND_LEVELS,
                'daemon'       => WorkerMode::isDaemon(),
            ], JSON_THROW_ON_ERROR);
            exit;
        }

        $verb = WorkerMode::isDaemon() ? 'queued' : 'started';
        Session::flash(
            'success',
            'Audio levels job #' . $jobId . ' ' . $verb
            . ' — background worker will build the lane.'
        );
        header('Location: /split/' . $id . $qs);
        exit;
    }

    if ($uri === '/split/audio-job/cancel') {
        $splitId      = (int) ($_POST['id'] ?? 0);
        $audioJobId   = (int) ($_POST['audio_job_id'] ?? 0);
        $statusFilter = trim((string) ($_POST['status_filter'] ?? ''));
        if (!in_array($statusFilter, ['PENDING', 'IN_PROGRESS', 'DONE', 'FAILED'], true)) {
            $statusFilter = '';
        }
        $qs = split_status_query($statusFilter !== '' ? $statusFilter : null);
        $job = $audioJobId > 0 ? $audioJobRepo->findById($audioJobId) : null;
        if ($job === null || (int) ($job['split_queue_id'] ?? 0) !== $splitId) {
            Session::flash('error', 'Audio job not found.');
            header('Location: /split/' . max(0, $splitId) . $qs);
            exit;
        }
        if ($audioJobRepo->requestCancel($audioJobId)) {
            split_audit($audit, 'SPLIT_AUDIO_CANCEL', $splitId, ['audio_job_id' => $audioJobId]);
            Session::flash('success', 'Cancel requested — worker will stop after the current FFmpeg step.');
        } else {
            Session::flash('error', 'Job is not cancellable.');
        }
        header('Location: /split/' . $splitId . $qs);
        exit;
    }

    http_response_code(404);
    exit;
}

// GET /split/{id}/audio-job — JSON status for workbench polling
if (preg_match('#^/split/(\d+)/audio-job$#', $uri, $m) && $method === 'GET') {
    $id   = (int) $m[1];
    $item = $splitRepo->findById($id);
    header('Content-Type: application/json');
    if ($item === null) {
        http_response_code(404);
        echo json_encode(['available' => false, 'error' => 'not_found'], JSON_THROW_ON_ERROR);
        exit;
    }
    $job = $audioJobRepo->latestForSplitQueue($id);
    if ($job === null) {
        echo json_encode(['available' => false], JSON_THROW_ON_ERROR);
        exit;
    }
    $status = (string) ($job['status'] ?? '');
    $alive = $status === 'RUNNING' ? $audioJobRepo->isWorkerAlive((int) $job['id']) : false;
    $map = null;
    if ($status === 'COMPLETED') {
        $mediaPath = str_replace('\\', '/', (string) ($item['original_path'] ?? ''));
        if ($mediaPath !== '') {
            $noiseDb = split_audio_settings(new SystemRepository())['noise_db'];
            $map = (new AudioLevelMapService())->loadCached(
                (int) $item['file_id'],
                $mediaPath,
                $noiseDb
            );
        }
    }
    echo json_encode([
        'available'        => true,
        'audio_job_id'     => (int) $job['id'],
        'kind'             => (string) ($job['kind'] ?? ''),
        'status'           => $status,
        'worker_alive'     => $alive,
        'orphan'           => $status === 'RUNNING' && !$alive,
        'error_message'    => (string) ($job['error_message'] ?? ''),
        'result_summary'   => (string) ($job['result_summary'] ?? ''),
        'cancel_requested' => !empty($job['cancel_requested']),
        'map'              => $map,
    ], JSON_THROW_ON_ERROR);
    exit;
}

// GET /split/{id}/audio-map
if (preg_match('#^/split/(\d+)/audio-map$#', $uri, $m) && $method === 'GET') {
    $id   = (int) $m[1];
    $item = $splitRepo->findById($id);
    header('Content-Type: application/json');
    if ($item === null) {
        http_response_code(404);
        echo json_encode(['available' => false, 'error' => 'not_found'], JSON_THROW_ON_ERROR);
        exit;
    }
    $file = $fileRepo->findById((int) $item['file_id']);
    $mediaPath = str_replace('\\', '/', (string) ($file['original_path'] ?? ''));
    if ($file === null || $mediaPath === '') {
        echo json_encode(['available' => false], JSON_THROW_ON_ERROR);
        exit;
    }
    $settings = split_audio_settings(new SystemRepository());
    $map = (new AudioLevelMapService())->loadCached((int) $file['id'], $mediaPath, $settings['noise_db']);
    if ($map === null) {
        echo json_encode(['available' => false], JSON_THROW_ON_ERROR);
        exit;
    }
    echo json_encode($map, JSON_THROW_ON_ERROR);
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

    $statusFilter = trim($_GET['status'] ?? '');
    if (!in_array($statusFilter, ['PENDING', 'IN_PROGRESS', 'DONE', 'FAILED'], true)) {
        $statusFilter = '';
    }

    $segments = json_decode((string) ($item['segments'] ?? '[]'), true);
    if (!is_array($segments)) {
        $segments = [];
    }

    $neighbors = $splitRepo->neighbors(
        $id,
        $statusFilter !== '' ? $statusFilter : null
    );
    $shows = $showRepo->all(true);
    $statusQuery = split_status_query($statusFilter !== '' ? $statusFilter : null);
    $fileDate = isset($item['file_date']) ? (string) $item['file_date'] : null;
    $fileTime = isset($item['file_time']) ? (string) $item['file_time'] : null;

    foreach ($segments as $i => $seg) {
        if (!is_array($seg)) {
            continue;
        }
        $air = split_derive_air($fileDate, $fileTime, (float) ($seg['start'] ?? 0));
        $segments[$i]['air_date'] = $air['date'];
        $segments[$i]['air_time'] = $air['time'];
    }

    $mediaInfo = (new SplitMediaService())->describe($item);
    $audioMap = null;
    $audioJob = $audioJobRepo->latestForSplitQueue($id);
    $mediaPath = str_replace('\\', '/', (string) ($item['original_path'] ?? ''));
    if ($mediaPath !== '' && trim((string) ($item['codec_audio'] ?? '')) !== '') {
        $noiseDb = split_audio_settings(new SystemRepository())['noise_db'];
        $audioMap = (new AudioLevelMapService())->loadCached(
            (int) $item['file_id'],
            $mediaPath,
            $noiseDb
        );
    }
    $title = 'Split Workbench #' . $id . ' — Media Manager';

    require dirname(__DIR__) . '/Views/layouts/header.php';
    require dirname(__DIR__) . '/Views/split/detail.php';
    require dirname(__DIR__) . '/Views/layouts/footer.php';
    exit;
}

// GET /split/list-status — JSON for split index live poll
if ($method === 'GET' && $uri === '/split/list-status') {
    $statusFilter = trim((string) ($_GET['status'] ?? ''));
    if (!in_array($statusFilter, ['PENDING', 'IN_PROGRESS', 'DONE', 'FAILED'], true)) {
        $statusFilter = '';
    }
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 50;
    $offset = ($page - 1) * $perPage;
    $items = $splitRepo->all($statusFilter !== '' ? $statusFilter : null, $perPage, $offset);
    $total = $splitRepo->count($statusFilter !== '' ? $statusFilter : null);
    $statusCounts = $splitRepo->statusCounts();
    $jobsOut = [];
    $poll = false;
    foreach ($items as $item) {
        $id = (int) ($item['id'] ?? 0);
        $st = (string) ($item['status'] ?? '');
        if (in_array($st, ['PENDING', 'IN_PROGRESS'], true)) {
            $poll = true;
        }
        $segs = json_decode((string) ($item['segments'] ?? '[]'), true);
        $segCount = is_array($segs) ? count($segs) : 0;
        $audioJob = $audioJobRepo->latestForSplitQueue($id);
        $audioActive = null;
        if ($audioJob !== null) {
            $aStatus = (string) ($audioJob['status'] ?? '');
            if (in_array($aStatus, ['PENDING', 'RUNNING'], true)) {
                $poll = true;
                $alive = $aStatus === 'RUNNING' && $audioJobRepo->isWorkerAlive((int) $audioJob['id']);
                $audioActive = [
                    'id'     => (int) $audioJob['id'],
                    'kind'   => (string) ($audioJob['kind'] ?? ''),
                    'status' => $aStatus,
                    'orphan' => $aStatus === 'RUNNING' && !$alive,
                ];
            }
        }
        $jobsOut[] = [
            'id'               => $id,
            'status'           => $st,
            'status_badge_html'=> \MediaManager\Support\View::statusBadge($st),
            'segment_count'    => $segCount,
            'active_audio_job' => $audioActive,
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'poll'          => $poll,
        'status_counts' => $statusCounts,
        'total'         => $total,
        'ids'           => array_column($jobsOut, 'id'),
        'jobs'          => $jobsOut,
    ], JSON_THROW_ON_ERROR);
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
 * @return array{
 *   flag_seconds: int,
 *   content_gap: float,
 *   min_program: float,
 *   ad_ignore: float,
 *   noise_db: float
 * }
 */
function split_audio_settings(SystemRepository $systemRepo): array
{
    $flagSeconds = (int) ($systemRepo->get('split_flag_threshold_seconds')
        ?? env('SPLIT_FLAG_THRESHOLD_SECONDS', ScheduleSplitSuggester::DEFAULT_FLAG_THRESHOLD_SECONDS));
    if ($flagSeconds < 1) {
        $flagSeconds = ScheduleSplitSuggester::DEFAULT_FLAG_THRESHOLD_SECONDS;
    }

    $contentGap = (float) ($systemRepo->get('split_audio_content_gap_seconds')
        ?? env('SPLIT_AUDIO_CONTENT_GAP_SECONDS', AudioSplitSuggester::DEFAULT_CONTENT_GAP_SECONDS));
    $minProgram = (float) ($systemRepo->get('split_audio_min_program_seconds')
        ?? env('SPLIT_AUDIO_MIN_PROGRAM_SECONDS', AudioSplitSuggester::DEFAULT_MIN_PROGRAM_SECONDS));
    $adIgnore = (float) ($systemRepo->get('split_audio_ad_ignore_seconds')
        ?? env('SPLIT_AUDIO_AD_IGNORE_SECONDS', AudioSplitSuggester::DEFAULT_AD_IGNORE_SECONDS));
    $noiseDb = (float) ($systemRepo->get('split_audio_silence_noise_db')
        ?? env('SPLIT_AUDIO_SILENCE_NOISE_DB', AudioSplitSuggester::DEFAULT_SILENCE_NOISE_DB));

    return [
        'flag_seconds' => $flagSeconds,
        'content_gap'  => max(60.0, $contentGap),
        'min_program'  => max(30.0, $minProgram),
        'ad_ignore'    => max(1.0, $adIgnore),
        'noise_db'     => min(-5.0, max(-80.0, $noiseDb)),
    ];
}

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
