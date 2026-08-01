<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Repositories\FileRepository;
use MediaManager\Services\FFprobeService;
use MediaManager\Support\View;

Auth::requireLogin();

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

if (preg_match('#^/queue/ffprobe/(\d+)$#', $uri, $m) !== 1) {
    http_response_code(404);
    exit;
}

$fileId = (int) $m[1];
$file   = (new FileRepository())->findById($fileId);

if ($file === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'File not found'], JSON_THROW_ON_ERROR);
    exit;
}

$storedSummary = [
    'duration'       => $file['duration_seconds'] ?? null,
    'duration_label' => View::duration(
        isset($file['duration_seconds']) ? (float) $file['duration_seconds'] : null
    ),
    'resolution'     => $file['resolution'] ?? null,
    'codec_video'    => $file['codec_video'] ?? null,
    'codec_audio'    => $file['codec_audio'] ?? null,
    'framerate'      => $file['framerate'] ?? null,
    'container'      => $file['container'] ?? null,
    'filesize_bytes' => $file['filesize_bytes'] ?? null,
    'filesize_label' => View::filesize(
        isset($file['filesize_bytes']) ? (int) $file['filesize_bytes'] : null
    ),
    'metadata_extracted' => !empty($file['metadata_extracted']),
    'has_captions'   => !empty($file['has_captions']),
    'caption_stream_index' => $file['caption_stream_index'] ?? null,
    'srt_path'       => $file['srt_path'] ?? null,
    'source'         => 'scan',
];

$sourcePath = FileRepository::mediaSourcePath($file);
$ffprobe    = new FFprobeService();
$live       = null;
$error      = null;

if (!$ffprobe->isAvailable()) {
    $error = 'FFprobe is not available on this server.';
} elseif (!is_readable($sourcePath)) {
    $error = 'Source file is not readable: ' . $sourcePath;
} else {
    $live = $ffprobe->probeRaw($sourcePath);
    if ($live === null) {
        $error = 'FFprobe could not read this file.';
    }
}

header('Content-Type: application/json');
echo json_encode([
    'file_id'         => $fileId,
    'source_path'     => $sourcePath,
    'stored_summary'  => $storedSummary,
    'live_summary'    => $live['summary'] ?? null,
    'raw'             => $live['raw'] ?? null,
    'error'           => $error,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
