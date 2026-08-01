<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\CaptionExtractJobRepository;
use MediaManager\Repositories\FileRepository;
use MediaManager\Services\CaptionExtractEtaEstimator;
use MediaManager\Services\CaptionExtractJobService;

Auth::requireAdmin();

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$projectRoot = dirname(__DIR__, 2);

$jobs  = new CaptionExtractJobRepository();
$files = new FileRepository();
$audit = new AuditRepository();

function spawn_caption_extract_worker(int $jobId, string $projectRoot): void
{
    $logFile = CaptionExtractJobService::logPathForJob($jobId, $projectRoot);
    $logDir  = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    // Seed log so the UI can open it immediately.
    $modeNote = \MediaManager\Support\WorkerMode::isDaemon()
        ? 'Queued for media-manager-caption-extract.service'
        : 'Spawned one-shot worker';
    @file_put_contents(
        $logFile,
        '[' . gmdate('Y-m-d\TH:i:s\Z') . "] INFO {$modeNote} job_id={$jobId}\n",
        FILE_APPEND | LOCK_EX
    );

    if (!\MediaManager\Support\WorkerMode::shouldSpawn()) {
        return;
    }

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

if ($method === 'POST') {
    $csrf = $_POST['_csrf'] ?? '';
    if (!Session::validateCsrf($csrf)) {
        Session::flash('error', 'Invalid request.');
        header('Location: /captions');
        exit;
    }

    if ($uri === '/captions/start') {
        $scope = (string) ($_POST['scope'] ?? 'missing_srt');
        if (!in_array($scope, ['missing_srt', 'has_captions', 'selected', 'probe_only'], true)) {
            $scope = 'missing_srt';
        }
        $selected = null;
        if ($scope === 'selected') {
            $raw = $_POST['ids'] ?? [];
            if (!is_array($raw)) {
                $raw = [];
            }
            $selected = array_values(array_unique(array_filter(
                array_map('intval', $raw),
                static fn (int $id): bool => $id > 0
            )));
            if ($selected === []) {
                Session::flash('error', 'Select at least one file for a selected-scope job.');
                header('Location: /queue');
                exit;
            }
        }

        $running = $jobs->findActive();
        if ($running !== null) {
            Session::flash('error', 'A caption extract job is already active (#' . (int) $running['id'] . '). Move clips to top on that job instead.');
            header('Location: /captions/' . (int) $running['id']);
            exit;
        }

        $summary = $files->summarizeCaptionExtractCandidates($scope, $selected);
        if ($summary['count'] === 0) {
            Session::flash('error', 'No files need SRT extract for that scope.');
            header('Location: /captions');
            exit;
        }

        $jobId = $jobs->create((int) Auth::id(), $scope, $selected);
        $audit->record(
            Auth::id(),
            Auth::email(),
            $_SERVER['REMOTE_ADDR'] ?? '',
            'CAPTION_EXTRACT_QUEUED',
            'caption_extract_job',
            $jobId,
            null,
            null,
            [
                'scope'    => $scope,
                'count'    => $summary['count'],
                'duration' => $summary['duration_seconds'],
            ]
        );
        spawn_caption_extract_worker($jobId, $projectRoot);
        $verb = \MediaManager\Support\WorkerMode::isDaemon() ? 'queued' : 'started';
        Session::flash(
            'success',
            'Caption extract job #' . $jobId . ' ' . $verb . ' — '
            . number_format($summary['count']) . ' file(s), ~'
            . number_format($summary['duration_seconds'] / 3600, 1) . 'h media.'
        );
        header('Location: /captions/' . $jobId);
        exit;
    }

    if ($uri === '/captions/cancel') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && $jobs->requestCancel($id)) {
            $audit->record(
                Auth::id(),
                Auth::email(),
                $_SERVER['REMOTE_ADDR'] ?? '',
                'CAPTION_EXTRACT_CANCEL',
                'caption_extract_job',
                $id,
                null,
                null,
                []
            );
            Session::flash('success', 'Cancel requested — worker will stop after the current file.');
        } else {
            Session::flash('error', 'Could not cancel job.');
        }
        header('Location: /captions/' . max(1, $id));
        exit;
    }

    if ($uri === '/captions/prioritize') {
        $id = (int) ($_POST['id'] ?? 0);
        $raw = $_POST['ids'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }
        $selected = array_values(array_unique(array_filter(
            array_map('intval', $raw),
            static fn (int $n): bool => $n > 0
        )));
        $job = $id > 0 ? $jobs->findById($id) : null;
        $status = (string) ($job['status'] ?? '');
        if ($job === null || !in_array($status, ['PENDING', 'RUNNING'], true)) {
            Session::flash('error', 'Job is not active — cannot move clips to top.');
            header('Location: /captions/' . max(1, $id));
            exit;
        }
        if ($selected === []) {
            Session::flash('error', 'Select at least one clip.');
            header('Location: /captions/' . $id);
            exit;
        }

        $merged = $jobs->prependPriority($id, $selected);
        $audit->record(
            Auth::id(),
            Auth::email(),
            $_SERVER['REMOTE_ADDR'] ?? '',
            'CAPTION_EXTRACT_PRIORITIZE',
            'caption_extract_job',
            $id,
            null,
            null,
            [
                'moved' => array_slice($selected, 0, 50),
                'count' => count($selected),
                'priority_len' => count($merged),
            ]
        );
        Session::flash(
            'success',
            count($selected) . ' clip(s) moved to top of caption extract queue (#' . $id . ').'
        );
        header('Location: /captions/' . $id);
        exit;
    }

    http_response_code(404);
    exit;
}

// GET /captions/{id}
if (preg_match('#^/captions/(\d+)$#', $uri, $m)) {
    $id = (int) $m[1];
    $job = $jobs->findById($id);
    if ($job === null) {
        http_response_code(404);
        $title = '404 — Not Found';
        require dirname(__DIR__) . '/Views/layouts/header.php';
        echo '<p class="text-soft">Caption extract job not found.</p>';
        require dirname(__DIR__) . '/Views/layouts/footer.php';
        exit;
    }

    $eta = CaptionExtractEtaEstimator::estimate($job);
    $logPath = CaptionExtractJobService::logPathForJob($id, $projectRoot);
    $logTail = CaptionExtractJobService::tailLog($logPath, 150);
    $refresh = in_array(($job['status'] ?? ''), ['PENDING', 'RUNNING'], true);

    $scope = (string) ($job['scope'] ?? 'missing_srt');
    $selectedIds = null;
    if ($scope === 'selected') {
        $rawIds = $job['file_ids'] ?? null;
        if (is_string($rawIds)) {
            $decoded = json_decode($rawIds, true);
            $selectedIds = is_array($decoded) ? array_map('intval', $decoded) : [];
        } elseif (is_array($rawIds)) {
            $selectedIds = array_map('intval', $rawIds);
        } else {
            $selectedIds = [];
        }
    }

    $priorityIds = $jobs->getPriorityIds($id);
    $priorityFiles = $files->findByIdsOrdered($priorityIds);
    $upcoming = [];
    $canReorder = $refresh;
    if ($canReorder) {
        $prioritySet = array_fill_keys($priorityIds, true);
        $currentId = isset($job['current_file_id']) ? (int) $job['current_file_id'] : 0;
        $cursor = 0;
        // Page through candidates until we have ~80 non-priority rows to pick from.
        while (count($upcoming) < 80) {
            $batch = $files->listCaptionExtractCandidates($scope, $selectedIds, $cursor, 120);
            if ($batch === []) {
                break;
            }
            foreach ($batch as $row) {
                $cursor = (int) $row['id'];
                $fid = (int) $row['id'];
                if ($fid === $currentId || isset($prioritySet[$fid])) {
                    continue;
                }
                $upcoming[] = $row;
                if (count($upcoming) >= 80) {
                    break;
                }
            }
        }
    }

    $title = 'Caption Extract #' . $id . ' — Media Manager';

    require dirname(__DIR__) . '/Views/layouts/header.php';
    require dirname(__DIR__) . '/Views/captions/show.php';
    require dirname(__DIR__) . '/Views/layouts/footer.php';
    exit;
}

// GET /captions
if ($uri === '/captions') {
    $recent = $jobs->recent(30);
    $running = $jobs->findRunning();
    $missing = $files->summarizeCaptionExtractCandidates('missing_srt', null);
    $knownCc = $files->summarizeCaptionExtractCandidates('has_captions', null);
    $unprobed = $files->summarizeCaptionExtractCandidates('probe_only', null);
    $title = 'Caption Extract — Media Manager';

    require dirname(__DIR__) . '/Views/layouts/header.php';
    require dirname(__DIR__) . '/Views/captions/index.php';
    require dirname(__DIR__) . '/Views/layouts/footer.php';
    exit;
}

http_response_code(404);
exit;
