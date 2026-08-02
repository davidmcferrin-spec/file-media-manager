<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Repositories\FileRepository;
use MediaManager\Repositories\MediaTypeRepository;
use MediaManager\Repositories\ScanJobRepository;
use MediaManager\Repositories\ShowRepository;
use MediaManager\Services\FileEditSuggester;

Auth::requireLogin();

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$files       = new FileRepository();
$shows       = new ShowRepository();
$scanJobs    = new ScanJobRepository();
$mediaTypes  = new MediaTypeRepository();
$suggester   = new FileEditSuggester();

// GET /queue/list-status — counts only (never replace checkbox rows)
if ($method === 'GET' && $uri === '/queue/list-status') {
    $statusCounts = $files->statusCounts();
    $approved = (int) ($statusCounts['APPROVED'] ?? 0);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        // Keep a light poll so pills stay fresh while reviewing.
        'poll'          => true,
        'status_counts' => $statusCounts,
        'approved_count'=> $approved,
    ], JSON_THROW_ON_ERROR);
    exit;
}

$statusParam = $_GET['status'] ?? 'PENDING';
$filters = [
    'status'      => $statusParam,
    'confidence'  => $_GET['confidence'] ?? '',
    'scan_job_id' => isset($_GET['scan_job_id']) ? (int) $_GET['scan_job_id'] : 0,
    'show_id'     => isset($_GET['show_id']) ? (int) $_GET['show_id'] : 0,
    'file_id'     => isset($_GET['file_id']) ? (int) $_GET['file_id'] : 0,
    'needs_split'    => isset($_GET['needs_split']),
    'needs_glue'     => isset($_GET['needs_glue']),
    'glue_group_key' => trim((string) ($_GET['glue_group'] ?? '')),
    'search'         => trim($_GET['q'] ?? ''),
];

if ($filters['scan_job_id'] <= 0) {
    unset($filters['scan_job_id']);
}
if ($filters['show_id'] <= 0) {
    unset($filters['show_id']);
}
if ($filters['file_id'] <= 0) {
    unset($filters['file_id']);
} else {
    // Deep-link from Continuity Lab — show the file regardless of status tab.
    $filters['status'] = 'ALL';
    $statusParam = 'ALL';
}
if ($filters['confidence'] === '') {
    unset($filters['confidence']);
}
if ($filters['search'] === '') {
    unset($filters['search']);
}
if ($filters['glue_group_key'] === '') {
    unset($filters['glue_group_key']);
} else {
    // Group deep-link — show members regardless of status tab.
    $filters['status'] = 'ALL';
    $statusParam = 'ALL';
    $filters['needs_glue'] = true;
}
// Show Audit deep-links use status=ALL to find a file regardless of queue state.
$statusFilter = strtoupper((string) $filters['status']) === 'ALL'
    ? 'ALL'
    : (string) $filters['status'];
if ($statusFilter === 'ALL') {
    unset($filters['status']);
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 50);
if (!in_array($perPage, [50, 100, 200], true)) {
    $perPage = 50;
}
$offset  = ($page - 1) * $perPage;

$total      = $files->countQueue($filters);
$queueItems = $files->listQueue($filters, $perPage, $offset);
foreach ($queueItems as $i => $item) {
    $queueItems[$i]['edit_suggest'] = $suggester->suggest($item);
}
$statusCounts = $files->statusCounts();
$showList    = $shows->all(true);
$mediaTypeList = $mediaTypes->all(true);
$recentScans = $scanJobs->recent(10);

$totalPages = max(1, (int) ceil($total / $perPage));

$title = 'Catalog — Media Manager';
require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/queue/index.php';
require dirname(__DIR__) . '/Views/layouts/footer.php';
