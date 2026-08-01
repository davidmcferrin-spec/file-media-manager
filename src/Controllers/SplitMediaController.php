<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Repositories\SplitQueueRepository;
use MediaManager\Services\SplitMediaService;

Auth::requireAdmin();

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$splitRepo = new SplitQueueRepository();
$media = new SplitMediaService();

if (preg_match('#^/split/media/(\d+)/frame$#', $uri, $m) === 1) {
    $jobId = (int) $m[1];
    $item = $splitRepo->findById($jobId);
    if ($item === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Split job not found.';
        exit;
    }

    $t = isset($_GET['t']) && is_numeric($_GET['t']) ? (float) $_GET['t'] : 0.0;
    try {
        $path = $media->ensureFrame((int) $item['file_id'], $t);
    } catch (\Throwable $e) {
        error_log('[split-media] frame job #' . $jobId . ': ' . $e->getMessage());
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Frame unavailable';
        exit;
    }

    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=3600');
    header('X-Split-Time: ' . sprintf('%.3f', $t));
    readfile($path);
    exit;
}

if (preg_match('#^/split/media/(\d+)/play$#', $uri, $m) === 1) {
    $jobId = (int) $m[1];
    $item = $splitRepo->findById($jobId);
    if ($item === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Split job not found.';
        exit;
    }

    $t = isset($_GET['t']) && is_numeric($_GET['t']) ? (float) $_GET['t'] : 0.0;
    try {
        $seg = $media->ensurePlaySegment((int) $item['file_id'], $t);
    } catch (\Throwable $e) {
        error_log('[split-media] play job #' . $jobId . ': ' . $e->getMessage());
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Split-Play-Error: ' . substr(preg_replace('/[\r\n]+/', ' ', $e->getMessage()) ?? '', 0, 400));
        echo 'Play segment unavailable';
        exit;
    }

    header('X-Split-Play-Mode: ' . $seg['mode']);
    header('X-Split-Play-Start: ' . (string) $seg['start']);
    header('X-Split-Play-Duration: ' . (string) $seg['duration']);
    send_split_media_file($seg['path'], 'video/mp4');
    exit;
}

http_response_code(404);
exit;

function send_split_media_file(string $path, string $contentType): void
{
    $size = filesize($path);
    if ($size === false) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: ' . $contentType);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=3600');

    $start = 0;
    $end = $size - 1;

    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m) === 1) {
        if ($m[1] !== '') {
            $start = (int) $m[1];
        }
        if ($m[2] !== '') {
            $end = (int) $m[2];
        }
        if ($end >= $size) {
            $end = $size - 1;
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */{$size}");
            exit;
        }

        http_response_code(206);
        header("Content-Range: bytes {$start}-{$end}/{$size}");
    }

    $length = $end - $start + 1;
    header('Content-Length: ' . $length);

    $fp = fopen($path, 'rb');
    if ($fp === false) {
        http_response_code(404);
        exit;
    }
    if ($start > 0) {
        fseek($fp, $start);
    }

    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = fread($fp, min(8192, $remaining));
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($fp);
}
