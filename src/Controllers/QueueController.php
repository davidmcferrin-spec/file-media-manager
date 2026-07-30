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

$files       = new FileRepository();
$shows       = new ShowRepository();
$scanJobs    = new ScanJobRepository();
$mediaTypes  = new MediaTypeRepository();
$suggester   = new FileEditSuggester();

$statusParam = $_GET['status'] ?? 'PENDING';
$filters = [
    'status'      => $statusParam,
    'confidence'  => $_GET['confidence'] ?? '',
    'scan_job_id' => isset($_GET['scan_job_id']) ? (int) $_GET['scan_job_id'] : 0,
    'show_id'     => isset($_GET['show_id']) ? (int) $_GET['show_id'] : 0,
    'needs_split' => isset($_GET['needs_split']),
    'search'      => trim($_GET['q'] ?? ''),
];

if ($filters['scan_job_id'] <= 0) {
    unset($filters['scan_job_id']);
}
if ($filters['show_id'] <= 0) {
    unset($filters['show_id']);
}
if ($filters['confidence'] === '') {
    unset($filters['confidence']);
}
if ($filters['search'] === '') {
    unset($filters['search']);
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
