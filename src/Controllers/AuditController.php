<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;

Auth::requireAdmin();

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

$filters = [
    'action'      => trim($_GET['action'] ?? ''),
    'entity_type' => trim($_GET['entity_type'] ?? ''),
    'user_email'  => trim($_GET['user_email'] ?? ''),
    'search'      => trim($_GET['q'] ?? ''),
];

$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 50;
$offset   = ($page - 1) * $perPage;

$auditRepo = new \MediaManager\Repositories\AuditRepository();
$total     = $auditRepo->count($filters);
$entries   = $auditRepo->list($filters, $perPage, $offset);
$actions   = $auditRepo->distinctActions();
$totalPages = max(1, (int) ceil($total / $perPage));

$title = 'Audit Log — Media Manager';

require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/audit/index.php';
require dirname(__DIR__) . '/Views/layouts/footer.php';
