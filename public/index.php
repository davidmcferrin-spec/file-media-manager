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

    // Queue / Catalog
    $uri === '/queue'
    || $uri === '/queue/list-status'
        => require dirname(__DIR__) . '/src/Controllers/QueueController.php',

    // Thumbnail / preview (AJAX)
    str_starts_with($uri, '/queue/thumbnail')
        => require dirname(__DIR__) . '/src/Controllers/ThumbnailController.php',

    str_starts_with($uri, '/queue/preview')
        => require dirname(__DIR__) . '/src/Controllers/PreviewController.php',

    str_starts_with($uri, '/queue/ffprobe')
        => require dirname(__DIR__) . '/src/Controllers/FFprobeController.php',

    str_starts_with($uri, '/queue/captions')
        => require dirname(__DIR__) . '/src/Controllers/CaptionController.php',

    // Queue actions (POST)
    $uri === '/queue/approve'
    || $uri === '/queue/reject'
    || $uri === '/queue/unapprove'
    || $uri === '/queue/remove'
    || $uri === '/queue/edit'
    || $uri === '/queue/bulk-edit'
    || $uri === '/queue/batch'
    || $uri === '/queue/add-split'
    || $uri === '/queue/adopt-proposal'
    || $uri === '/queue/clear-split'
    || $uri === '/queue/mark-glue'
    || $uri === '/queue/clear-glue'
    || $uri === '/queue/extract-captions'
        => require dirname(__DIR__) . '/src/Controllers/QueueActionController.php',

    // Scan: apply legacy map / reclassify (before general scan routes)
    $uri === '/scan/apply-map'
        => require dirname(__DIR__) . '/src/Controllers/LegacyMapController.php',

    $uri === '/scan/reclassify'
    || $uri === '/scan/rescan'
        => require dirname(__DIR__) . '/src/Controllers/ScanController.php',

    // Scanner (admin only)
    $uri === '/scan' || str_starts_with($uri, '/scan/')
        => require dirname(__DIR__) . '/src/Controllers/ScanController.php',

    // Unified shows workspace (admin only)
    $uri === '/shows' || str_starts_with($uri, '/shows/')
        => require dirname(__DIR__) . '/src/Controllers/ShowWorkspaceController.php',

    // Broadcast eras — network on-air windows (admin only)
    $uri === '/eras' || str_starts_with($uri, '/eras/')
        => require dirname(__DIR__) . '/src/Controllers/BroadcastEraController.php',

    // Dictionary (legacy URL → redirect in controller; keep for old bookmarks)
    $uri === '/dictionary' || str_starts_with($uri, '/dictionary/')
        => require dirname(__DIR__) . '/src/Controllers/DictionaryController.php',

    // Program schedule / Timeline ops (admin only)
    $uri === '/schedule' || str_starts_with($uri, '/schedule/')
        => require dirname(__DIR__) . '/src/Controllers/ProgramScheduleController.php',

    // Legacy rename map (admin only)
    $uri === '/legacy-map'
    || $uri === '/legacy-map/import'
    || $uri === '/legacy-map/apply'
        => require dirname(__DIR__) . '/src/Controllers/LegacyMapController.php',

    // Show audit (all logged-in users)
    $uri === '/show-audit'
    || $uri === '/show-audit/gap'
    || $uri === '/show-audit/gap/delete'
    || $uri === '/show-audit/schedule/close'
        => require dirname(__DIR__) . '/src/Controllers/ShowAuditController.php',

    // Execute / Rollback (admin only)
    $uri === '/execute'
    || $uri === '/execute/list-status'
    || $uri === '/rollback'
        => require dirname(__DIR__) . '/src/Controllers/ExecuteController.php',

    // Split workbench media (frame scrub / play segments) — before general split routes
    str_starts_with($uri, '/split/media/')
        => require dirname(__DIR__) . '/src/Controllers/SplitMediaController.php',

    // Split queue (admin only)
    $uri === '/split' || str_starts_with($uri, '/split/')
        => require dirname(__DIR__) . '/src/Controllers/SplitController.php',

    // Glue queue (multipart ffmpeg concat + QC)
    $uri === '/glue' || str_starts_with($uri, '/glue/')
        => require dirname(__DIR__) . '/src/Controllers/GlueController.php',

    // Caption extract background jobs (admin)
    $uri === '/captions' || str_starts_with($uri, '/captions/')
        => require dirname(__DIR__) . '/src/Controllers/CaptionExtractController.php',

    // Audit log (admin only)
    $uri === '/audit'
        => require dirname(__DIR__) . '/src/Controllers/AuditController.php',

    // Services / systemd (admin only)
    $uri === '/services'
    || $uri === '/services/status'
    || $uri === '/services/logs'
    || $uri === '/services/action'
        => require dirname(__DIR__) . '/src/Controllers/ServicesController.php',

    // Continuity lab (admin only, unlinked)
    $uri === '/continuity-lab'
    || $uri === '/continuity-lab/status'
    || $uri === '/continuity-lab/test'
    || $uri === '/continuity-lab/clear'
    || $uri === '/continuity-lab/export'
        => require dirname(__DIR__) . '/src/Controllers/ContinuityLabController.php',

    // App versions / changelog (all logged-in users)
    $uri === '/versions'
        => require dirname(__DIR__) . '/src/Controllers/VersionsController.php',

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
