<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Services\ThumbnailJobService;
use MediaManager\Services\ThumbnailService;

Auth::requireLogin();

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$thumbs = new ThumbnailService();
$jobs = new ThumbnailJobService();

// GET /queue/thumbnail/{id}/status — JSON readiness for client poll
if (preg_match('#^/queue/thumbnail/(\d+)/status$#', $uri, $m) === 1) {
    $fileId = (int) $m[1];
    $large = ($_GET['size'] ?? '') === 'large';
    $path = $thumbs->resolve($fileId, $large);
    $ready = $path !== null;
    if (!$ready) {
        $jobs->enqueueIfNeeded($fileId, $large, Auth::id());
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'file_id' => $fileId,
        'size'    => $large ? 'large' : 'default',
        'ready'   => $ready,
        'url'     => $ready
            ? '/queue/thumbnail/' . $fileId . ($large ? '?size=large&t=' : '?t=') . time()
            : null,
    ], JSON_THROW_ON_ERROR);
    exit;
}

if (preg_match('#^/queue/thumbnail/(\d+)$#', $uri, $m) !== 1) {
    http_response_code(404);
    exit;
}

$fileId = (int) $m[1];
$large  = ($_GET['size'] ?? '') === 'large';

$path = $thumbs->resolve($fileId, $large);
if ($path !== null) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=86400');
    header('X-Thumbnail-Status: ready');
    readfile($path);
    exit;
}

// Cache miss — enqueue background FFmpeg; never block Apache on NAS decode.
$jobs->enqueueIfNeeded($fileId, $large, Auth::id());

$placeholder = dirname(__DIR__, 2) . '/public/img/thumb-pending.svg';
if (!is_readable($placeholder)) {
    http_response_code(202);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Thumbnail-Status: pending');
    echo 'Thumbnail pending';
    exit;
}

http_response_code(202);
header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: no-store');
header('X-Thumbnail-Status: pending');
readfile($placeholder);
