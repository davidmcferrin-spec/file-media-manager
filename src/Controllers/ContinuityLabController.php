<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Repositories\ContinuityCheckLogRepository;
use MediaManager\Services\ContinuityCheckService;

Auth::requireAdmin();

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'POST' && $uri === '/continuity-lab/test') {
    $csrf = $_POST['_csrf'] ?? '';
    if (!Session::validateCsrf($csrf)) {
        Session::flash('error', 'Invalid request.');
        header('Location: /continuity-lab');
        exit;
    }

    $result = ContinuityCheckService::create()->selfTest();
    if ($result['ok']) {
        Session::flash(
            'success',
            sprintf(
                'Engine self-test OK in %d ms. Pack loaded: %s.',
                $result['duration_ms'],
                $result['pack_loaded'] ? 'yes' : 'NO — pull the configured pack'
            )
        );
    } else {
        $detail = $result['transport_error'] !== ''
            ? $result['transport_error']
            : 'No usable response';
        if (!$result['pack_loaded']) {
            $detail .= ' | Configured pack not in loaded list: '
                . implode(', ', $result['packs'] !== [] ? $result['packs'] : ['(none)']);
        }
        Session::flash('error', 'Engine self-test failed (' . $result['duration_ms'] . ' ms): ' . $detail);
    }

    header('Location: /continuity-lab');
    exit;
}

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
