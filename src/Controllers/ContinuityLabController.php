<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\ContinuityCheckLogRepository;
use MediaManager\Repositories\ScanJobRepository;
use MediaManager\Services\ContinuityCheckService;
use MediaManager\Services\ContinuityEtaEstimator;
use MediaManager\Services\ContinuityLabExportService;

Auth::requireAdmin();

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET' && $uri === '/continuity-lab/export') {
    $filters = [
        'outcome' => trim((string) ($_GET['outcome'] ?? '')),
        'q'       => trim((string) ($_GET['q'] ?? '')),
    ];
    try {
        $export = (new ContinuityLabExportService())->export($filters);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
        header('Content-Length: ' . (string) strlen($export['bytes']));
        header('Cache-Control: no-store');
        echo $export['bytes'];
        exit;
    } catch (\Throwable $e) {
        Session::flash('error', 'Export failed: ' . $e->getMessage());
        header('Location: /continuity-lab');
        exit;
    }
}

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

if ($method === 'POST' && $uri === '/continuity-lab/clear') {
    $csrf = $_POST['_csrf'] ?? '';
    if (!Session::validateCsrf($csrf)) {
        Session::flash('error', 'Invalid request.');
        header('Location: /continuity-lab');
        exit;
    }

    $confirm = trim((string) ($_POST['confirm'] ?? ''));
    if ($confirm !== 'CLEAR') {
        Session::flash('error', 'Type CLEAR to confirm wiping the continuity log.');
        header('Location: /continuity-lab');
        exit;
    }

    try {
        $logRepo = new ContinuityCheckLogRepository();
        $cleared = $logRepo->clearAll();
        $user = Auth::user();
        (new AuditRepository())->record(
            Auth::id(),
            $user['email'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
            'CONTINUITY_LOG_CLEARED',
            'continuity_check_log',
            null,
            null,
            null,
            ['rows_cleared' => $cleared]
        );
        Session::flash(
            'success',
            $cleared === 0
                ? 'Continuity log was already empty.'
                : 'Cleared ' . number_format($cleared) . ' continuity log row(s).'
        );
    } catch (\Throwable $e) {
        Session::flash('error', 'Could not clear continuity log: ' . $e->getMessage());
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
$runningJob = (new ScanJobRepository())->findRunning();
$eta        = ContinuityEtaEstimator::estimate(
    $runningJob,
    $avgMs,
    $logRepo->countSinceMinutes(5)
);
$total      = $logRepo->count($filters);
$entries    = $logRepo->list($filters, $perPage, $offset);
$totalPages = max(1, (int) ceil($total / $perPage));

$title = 'Continuity Lab — Media Manager';

require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/continuity_lab/index.php';
require dirname(__DIR__) . '/Views/layouts/footer.php';
