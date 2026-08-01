<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\FileRepository;
use MediaManager\Repositories\ShowRepository;
use MediaManager\Repositories\SplitQueueRepository;
use MediaManager\Repositories\SystemRepository;
use MediaManager\Services\AudioLevelMapService;
use MediaManager\Services\AudioSilenceDetector;
use MediaManager\Services\AudioSplitSuggester;
use MediaManager\Services\CaptionSplitSuggester;
use MediaManager\Services\DateNormalizer;
use MediaManager\Services\MediaCacheService;
use MediaManager\Services\ScheduleSplitSuggester;
use MediaManager\Services\SplitMediaService;
use MediaManager\Services\SrtCaptionParser;
use PDOException;
use Throwable;

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
        if ($file === null) {
            Session::flash('error', 'Source file not found.');
            header('Location: /split/' . $id . $qs);
            exit;
        }

        $mediaPath = str_replace('\\', '/', (string) ($file['original_path'] ?? ''));
        if ($mediaPath === '' || !is_readable($mediaPath)) {
            Session::flash('error', 'Source media is not readable on disk.');
            header('Location: /split/' . $id . $qs);
            exit;
        }

        $codecAudio = trim((string) ($file['codec_audio'] ?? ''));
        if ($codecAudio === '') {
            Session::flash('error', 'No audio stream on file — cannot suggest from audio.');
            header('Location: /split/' . $id . $qs);
            exit;
        }

        @set_time_limit(0);
        $systemRepo = new SystemRepository();
        $settings = split_audio_settings($systemRepo);

        $mediaCache = new MediaCacheService();
        try {
            $gaps = split_detect_silence_cached(
                $mediaPath,
                (int) $file['id'],
                $settings['noise_db'],
                $mediaCache
            );
            $durationSec = isset($file['duration_seconds']) ? (float) $file['duration_seconds'] : 0.0;
            try {
                (new AudioLevelMapService($mediaCache))->buildFromSilenceAndCache(
                    (int) $file['id'],
                    $mediaPath,
                    $durationSec,
                    $settings['noise_db'],
                    $gaps
                );
            } catch (Throwable) {
                // Lane cache is best-effort; suggest still proceeds.
            }
            $suggestion = (new AudioSplitSuggester(
                new AudioSilenceDetector(noiseDb: $settings['noise_db']),
                $settings['flag_seconds'],
                $settings['content_gap'],
                $settings['min_program'],
                $settings['ad_ignore'],
            ))->suggestFromGaps(
                $gaps,
                $durationSec > 0 ? $durationSec : null,
                isset($file['file_date']) ? (string) $file['file_date'] : null,
                isset($file['file_time']) ? (string) $file['file_time'] : null,
            );
        } catch (Throwable $e) {
            Session::flash('error', 'Audio suggest failed: ' . $e->getMessage());
            header('Location: /split/' . $id . $qs);
            exit;
        }

        if ($suggestion['segments'] === []) {
            Session::flash('error', $suggestion['notes'] !== '' ? $suggestion['notes'] : 'No audio-based segments found.');
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
        split_audit($audit, 'SPLIT_AUDIO_SUGGEST', $id, [
            'segment_count'     => count($segments),
            'gap_count'         => $suggestion['gap_count'],
            'content_gap_count' => $suggestion['content_gap_count'],
        ]);
        Session::flash(
            'success',
            'Filled ' . count($segments) . ' segment(s) from audio (≥'
            . (int) round($settings['content_gap'] / 60)
            . ' min quiet gaps). Review before saving — first run may take a few minutes on long files.'
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

        @set_time_limit(0);
        $systemRepo = new SystemRepository();
        $settings = split_audio_settings($systemRepo);
        $mediaCache = new MediaCacheService();
        $levelSvc = new AudioLevelMapService($mediaCache);

        try {
            $gaps = null;
            try {
                $gaps = split_detect_silence_cached(
                    $mediaPath,
                    (int) $file['id'],
                    $settings['noise_db'],
                    $mediaCache
                );
            } catch (Throwable) {
                $gaps = null;
            }
            $map = $levelSvc->buildAndCache(
                (int) $file['id'],
                $mediaPath,
                isset($file['duration_seconds']) ? (float) $file['duration_seconds'] : 0.0,
                $settings['noise_db'],
                $gaps
            );
            split_audit($audit, 'SPLIT_AUDIO_MAP', $id, [
                'source'         => $map['source'],
                'bucket_seconds' => $map['bucket_seconds'],
                'block_count'    => count($map['blocks']),
            ]);
        } catch (Throwable $e) {
            if ($wantJson) {
                header('Content-Type: application/json');
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);
                exit;
            }
            Session::flash('error', 'Audio level map failed: ' . $e->getMessage());
            header('Location: /split/' . $id . $qs);
            exit;
        }

        if ($wantJson) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'map' => $map], JSON_THROW_ON_ERROR);
            exit;
        }

        Session::flash(
            'success',
            'Audio level lane ready (' . $map['source'] . ', '
            . count($map['blocks']) . ' blocks).'
        );
        header('Location: /split/' . $id . $qs);
        exit;
    }

    http_response_code(404);
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
 * @return list<array{start: float, end: float, duration: float}>
 */
function split_detect_silence_cached(
    string $mediaPath,
    int $fileId,
    float $noiseDb,
    MediaCacheService $cache,
): array {
    $mtime = @filemtime($mediaPath) ?: 0;
    $size = @filesize($mediaPath) ?: 0;
    $cacheDir = $cache->assetDir($fileId);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $cachePath = $cacheDir . '/audio_silence.json';
    if (is_readable($cachePath)) {
        $raw = file_get_contents($cachePath);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($json)
            && (int) ($json['mtime'] ?? 0) === $mtime
            && (int) ($json['size'] ?? 0) === $size
            && abs((float) ($json['noise_db'] ?? 0) - $noiseDb) < 0.01
            && isset($json['gaps']) && is_array($json['gaps'])
        ) {
            /** @var list<array{start: float, end: float, duration: float}> */
            return $json['gaps'];
        }
    }

    $detector = new AudioSilenceDetector(noiseDb: $noiseDb);
    $gaps = $detector->detect($mediaPath);
    @file_put_contents($cachePath, json_encode([
        'mtime'    => $mtime,
        'size'     => $size,
        'noise_db' => $noiseDb,
        'gaps'     => $gaps,
    ], JSON_THROW_ON_ERROR));

    return $gaps;
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
