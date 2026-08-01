<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Repositories\FileRepository;
use MediaManager\Services\SrtCaptionParser;

Auth::requireLogin();

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

if (preg_match('#^/queue/captions/(\d+)$#', $uri, $m) !== 1) {
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

$srtPath = str_replace('\\', '/', (string) ($file['srt_path'] ?? ''));
if ($srtPath === '' || !is_readable($srtPath)) {
    // Fall back to adjacent sidecar next to current media path.
    $media = FileRepository::mediaSourcePath($file);
    $dir = dirname($media);
    $stem = pathinfo($media, PATHINFO_FILENAME);
    foreach (['srt', 'vtt'] as $ext) {
        $candidate = $dir . '/' . $stem . '.' . $ext;
        if (is_readable($candidate)) {
            $srtPath = str_replace('\\', '/', $candidate);
            break;
        }
    }
}

header('Content-Type: application/json');

if ($srtPath === '' || !is_readable($srtPath)) {
    echo json_encode([
        'file_id'      => $fileId,
        'has_captions' => !empty($file['has_captions']),
        'srt_path'     => null,
        'cues'         => [],
        'error'        => 'No SRT file available. Extract captions first.',
    ], JSON_THROW_ON_ERROR);
    exit;
}

$cues = SrtCaptionParser::parseFile($srtPath);
$formatted = [];
foreach ($cues as $cue) {
    $formatted[] = [
        'index'       => $cue['index'],
        'start'       => $cue['start'],
        'end'         => $cue['end'],
        'start_label' => SrtCaptionParser::secondsToTimecode($cue['start']),
        'end_label'   => SrtCaptionParser::secondsToTimecode($cue['end']),
        'text'        => $cue['text'],
    ];
}

echo json_encode([
    'file_id'      => $fileId,
    'has_captions' => true,
    'srt_path'     => $srtPath,
    'cue_count'    => count($formatted),
    'cues'         => $formatted,
    'error'        => null,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
