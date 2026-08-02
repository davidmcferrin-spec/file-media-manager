<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Repositories\AuditRepository;
use MediaManager\Services\SystemdServiceManager;

Auth::requireAdmin();

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$svc    = new SystemdServiceManager();
$audit  = new AuditRepository();
$projectRoot = dirname(__DIR__, 2);

function services_json(mixed $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

// GET /services/status
if ($method === 'GET' && $uri === '/services/status') {
    $ready = $svc->readiness();
    services_json([
        'ready'   => $ready,
        'system'  => $svc->systemInfo($projectRoot),
        'services'=> $ready['ok'] ? $svc->allStatuses() : [],
    ]);
}

// GET /services/logs?unit=&cursor=&lines=
if ($method === 'GET' && $uri === '/services/logs') {
    $unit = (string) ($_GET['unit'] ?? 'media-manager-scan');
    $cursor = isset($_GET['cursor']) ? trim((string) $_GET['cursor']) : null;
    if ($cursor === '') {
        $cursor = null;
    }
    $lines = (int) ($_GET['lines'] ?? ($cursor ? 80 : 120));
    $journal = $svc->journal($unit, $lines, $cursor);
    services_json($journal, $journal['ok'] ? 200 : 400);
}

// POST /services/action
if ($method === 'POST' && $uri === '/services/action') {
    $csrf = $_POST['_csrf'] ?? '';
    if (!Session::validateCsrf(is_string($csrf) ? $csrf : '')) {
        if (Auth::wantsJson()) {
            services_json(['ok' => false, 'message' => 'Invalid CSRF token.'], 403);
        }
        Session::flash('error', 'Invalid request.');
        header('Location: /services');
        exit;
    }

    $unit = (string) ($_POST['unit'] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    $result = $svc->action($unit, $action);

    $user = Auth::user();
    $audit->record(
        Auth::id(),
        $user['email'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
        'SERVICE_' . strtoupper($action !== '' ? $action : 'UNKNOWN'),
        'systemd_unit',
        null,
        $unit,
        null,
        [
            'ok'      => $result['ok'],
            'message' => $result['message'],
            'unit'    => $unit,
            'action'  => $action,
        ]
    );

    if (Auth::wantsJson() || isset($_POST['ajax'])) {
        services_json($result, $result['ok'] ? 200 : 400);
    }

    Session::flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: /services');
    exit;
}

// GET /services
if ($method === 'GET' && $uri === '/services') {
    $ready = $svc->readiness();
    $system = $svc->systemInfo($projectRoot);
    $services = $ready['ok'] ? $svc->allStatuses() : [];
    $csrf = Session::csrfToken();
    $title = 'Services — Media Manager';

    require dirname(__DIR__) . '/Views/layouts/header.php';
    require dirname(__DIR__) . '/Views/services/index.php';
    require dirname(__DIR__) . '/Views/layouts/footer.php';
    exit;
}

http_response_code(404);
if (Auth::wantsJson()) {
    services_json(['error' => 'Not found'], 404);
}
exit;
