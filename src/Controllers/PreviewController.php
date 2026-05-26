<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Services\PreviewService;

Auth::requireLogin();

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

if (preg_match('#^/queue/preview/(\d+)$#', $uri, $m) !== 1) {
    http_response_code(404);
    exit;
}

$fileId = (int) $m[1];

try {
    $path = (new PreviewService())->ensurePreview($fileId);
} catch (\Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Preview unavailable';
    exit;
}

header('Content-Type: video/webm');
header('Cache-Control: private, max-age=86400');
readfile($path);
