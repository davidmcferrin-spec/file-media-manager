<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Services\ThumbnailService;

Auth::requireLogin();

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

if (preg_match('#^/queue/thumbnail/(\d+)$#', $uri, $m) !== 1) {
    http_response_code(404);
    exit;
}

$fileId = (int) $m[1];
$size   = $_GET['size'] ?? '';
$width  = $size === 'large' ? (int) env('PREVIEW_WIDTH', 420) : null;

try {
    $path = (new ThumbnailService())->ensureThumbnail($fileId, $width);
} catch (\Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Thumbnail unavailable';
    exit;
}

header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=86400');
readfile($path);
