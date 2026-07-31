<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Repositories\ContinuityCheckLogRepository;
use MediaManager\Services\ContinuityCheckService;

Auth::requireAdmin();

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if ($uri !== '/continuity-lab') {
    http_response_code(404);
    exit;
}

$filters = [
    'outcome' => trim((string) ($_GET['outcome'] ?? '')),
    'q'       => trim((string) ($_GET['q'] ?? '')),
];
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;
$live    = isset($_GET['live']);

$continuity = ContinuityCheckService::create();
$status     = $continuity->status();
$logRepo    = new ContinuityCheckLogRepository();
$summary    = $logRepo->summary();
$avgMs      = $logRepo->avgDurationMs();
$total      = $logRepo->count($filters);
$entries    = $logRepo->list($filters, $perPage, $offset);
$totalPages = max(1, (int) ceil($total / $perPage));

$title = 'Continuity Lab — Media Manager';

require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/continuity_lab/index.php';
require dirname(__DIR__) . '/Views/layouts/footer.php';
