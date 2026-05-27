<?php

declare(strict_types=1);

// ── Bootstrap ────────────────────────────────────────────────
require dirname(__DIR__) . '/src/bootstrap.php';

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;

// ── Start session ────────────────────────────────────────────
Session::start();

// ── Router ───────────────────────────────────────────────────
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Strip trailing slash (except root)
if ($uri !== '/' && str_ends_with($uri, '/')) {
    $uri = rtrim($uri, '/');
}

// ── Public routes (no auth required) ─────────────────────────
if ($uri === '/login') {
    if ($method === 'POST') {
        require dirname(__DIR__) . '/src/Controllers/AuthController.php';
        (new \MediaManager\Controllers\AuthController())->handleLogin();
    } else {
        if (Auth::check()) {
            header('Location: /dashboard');
            exit;
        }
        $error       = Session::getFlash('login_error');
        $rateLimited = Session::getFlash('login_rate_limited');
        require dirname(__DIR__) . '/src/Views/auth/login.php';
    }
    exit;
}

if ($uri === '/logout') {
    Auth::logout();
    header('Location: /login');
    exit;
}

// ── All other routes require login ───────────────────────────
Auth::requireLogin();

// ── Authenticated routes ──────────────────────────────────────
match (true) {

    // Dashboard
    $uri === '/' || $uri === '/dashboard'
        => require dirname(__DIR__) . '/src/Controllers/DashboardController.php',

    $uri === '/dashboard/library'
        => require dirname(__DIR__) . '/src/Controllers/DashboardLibraryController.php',

    // Queue
    $uri === '/queue'
        => require dirname(__DIR__) . '/src/Controllers/QueueController.php',

    // Thumbnail / preview (AJAX)
    str_starts_with($uri, '/queue/thumbnail')
        => require dirname(__DIR__) . '/src/Controllers/ThumbnailController.php',

    str_starts_with($uri, '/queue/preview')
        => require dirname(__DIR__) . '/src/Controllers/PreviewController.php',

    str_starts_with($uri, '/queue/ffprobe')
        => require dirname(__DIR__) . '/src/Controllers/FFprobeController.php',

    // Queue actions (POST)
    $uri === '/queue/approve'
    || $uri === '/queue/reject'
    || $uri === '/queue/edit'
    || $uri === '/queue/batch'
    || $uri === '/queue/add-split'
    || $uri === '/queue/adopt-proposal'
    || $uri === '/queue/clear-split'
        => require dirname(__DIR__) . '/src/Controllers/QueueActionController.php',

    // Scan: apply legacy map (before general scan routes)
    $uri === '/scan/apply-map'
        => require dirname(__DIR__) . '/src/Controllers/LegacyMapController.php',

    // Scanner (admin only)
    $uri === '/scan' || str_starts_with($uri, '/scan/')
        => require dirname(__DIR__) . '/src/Controllers/ScanController.php',

    // Dictionary (admin only)
    $uri === '/dictionary' || str_starts_with($uri, '/dictionary/')
        => require dirname(__DIR__) . '/src/Controllers/DictionaryController.php',

    // Program schedule (admin only)
    $uri === '/schedule' || str_starts_with($uri, '/schedule/')
        => require dirname(__DIR__) . '/src/Controllers/ProgramScheduleController.php',

    // Legacy rename map (admin only)
    $uri === '/legacy-map'
    || $uri === '/legacy-map/import'
    || $uri === '/legacy-map/apply'
        => require dirname(__DIR__) . '/src/Controllers/LegacyMapController.php',

    // Execute / Rollback (admin only)
    $uri === '/execute' || $uri === '/rollback'
        => require dirname(__DIR__) . '/src/Controllers/ExecuteController.php',

    // Split queue (admin only)
    $uri === '/split' || str_starts_with($uri, '/split/')
        => require dirname(__DIR__) . '/src/Controllers/SplitController.php',

    // Audit log (admin only)
    $uri === '/audit'
        => require dirname(__DIR__) . '/src/Controllers/AuditController.php',

    // Settings / users (admin only)
    $uri === '/settings' || str_starts_with($uri, '/settings/')
        => require dirname(__DIR__) . '/src/Controllers/SettingsController.php',

    // 404 fallback
    default => (function () use ($uri): void {
        http_response_code(404);
        $title = '404 — Not Found';
        require dirname(__DIR__) . '/src/Views/layouts/header.php';
        echo '<div class="text-center mt-5">';
        echo '<h2 class="text-secondary">404</h2>';
        echo '<p class="text-soft">Page not found: ' . htmlspecialchars($uri, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<a href="/dashboard" class="btn btn-outline-secondary btn-sm">Back to Dashboard</a>';
        echo '</div>';
        require dirname(__DIR__) . '/src/Views/layouts/footer.php';
    })(),
};
