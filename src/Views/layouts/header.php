<?php

declare(strict_types=1);

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Support\View;

$currentUser  = Auth::user();
$currentPath  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$csrfToken    = Session::csrfToken();
$flashSuccess = Session::getFlash('success');
$flashError   = Session::getFlash('error');

function navActive(string $prefix, string $current): string {
    return str_starts_with($current, $prefix) ? ' active' : '';
}
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo View::e($title ?? 'Media Manager'); ?></title>

<!-- Theme init: prevent flash before CSS loads -->
<script>
(function () {
    var saved = localStorage.getItem('mm-theme');
    var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    var theme = saved === 'light' ? 'light' : (saved === 'dark' ? 'dark' : (prefersDark ? 'dark' : 'light'));
    document.documentElement.setAttribute('data-bs-theme', theme);
})();
</script>

<link href="/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">

<style>
/* ── Design tokens: dark (default) ─────────────────────────── */
:root,
[data-bs-theme="dark"] {
    --bg-body:           #0b1118;
    --bg-body-alt:       #111827;
    --panel:             #151d29;
    --panel-strong:      #1b2433;
    --border-color:      rgba(148, 163, 184, 0.28);
    --border-strong:     rgba(148, 163, 184, 0.42);
    --text-main:         #f1f5f9;
    --text-soft:         #b6c2d1;
    --accent:            #5ec8f5;
    --accent-hover:      #8adbff;
    --navbar-bg:         rgba(11, 17, 24, 0.96);
    --card-header-bg:    #1a2332;
    --form-bg:           #0f1621;
    --form-border:       rgba(148, 163, 184, 0.36);
    --success-soft:      rgba(34,  197, 94,  0.18);
    --warning-soft:      rgba(250, 204, 21,  0.16);
    --danger-soft:       rgba(248, 113, 113, 0.18);
    --info-soft:         rgba(94,  200, 245,  0.14);
    --hover-bg:          rgba(148, 163, 184, 0.10);
    --surface-shadow:    0 10px 28px rgba(0, 0, 0, 0.32);
    --badge-high:        #4ade80;
    --badge-med:         #fde047;
    --badge-low:         #fb7185;
    --focus-ring:        rgba(94, 200, 245, 0.22);
    --placeholder:       #8896a8;
    --code-bg:           rgba(148, 163, 184, 0.12);
}

/* ── Design tokens: light ───────────────────────────────────── */
[data-bs-theme="light"] {
    --bg-body:           #f0f6ff;
    --bg-body-alt:       #e4eefb;
    --panel:             rgba(255, 255, 255, 0.97);
    --panel-strong:      #ffffff;
    --border-color:      rgba(15, 23, 42, 0.10);
    --border-strong:     rgba(15, 23, 42, 0.16);
    --text-main:         #0f172a;
    --text-soft:         #475569;
    --accent:            #0284c7;
    --accent-hover:      #0369a1;
    --navbar-bg:         rgba(255, 255, 255, 0.96);
    --card-header-bg:    #f1f5f9;
    --form-bg:           #ffffff;
    --form-border:       rgba(15, 23, 42, 0.14);
    --success-soft:      rgba(22,  163, 74,  0.12);
    --warning-soft:      rgba(202, 138,  4,  0.12);
    --danger-soft:       rgba(220,  38, 38,  0.10);
    --info-soft:         rgba(2,   132, 199, 0.10);
    --hover-bg:          rgba(15, 23, 42, 0.04);
    --surface-shadow:    0 12px 32px rgba(15, 23, 42, 0.08);
    --badge-high:        #15803d;
    --badge-med:         #a16207;
    --badge-low:         #b91c1c;
    --focus-ring:        rgba(2, 132, 199, 0.18);
    --placeholder:       #64748b;
    --code-bg:           rgba(15, 23, 42, 0.06);
}

/* ── Base ───────────────────────────────────────────────────── */
body {
    min-height: 100vh;
    color: var(--text-main);
    background: linear-gradient(170deg, var(--bg-body) 0%, var(--bg-body-alt) 100%);
    font-family: 'JetBrains Mono', 'Fira Code', 'Consolas', monospace;
    font-size: 0.9rem;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

.text-soft,
.text-muted,
.text-secondary,
.form-text {
    color: var(--text-soft) !important;
}

a:not(.btn):not(.nav-link):not(.dropdown-item):not(.navbar-brand) {
    color: var(--accent);
}
a:not(.btn):not(.nav-link):not(.dropdown-item):not(.navbar-brand):hover {
    color: var(--accent-hover);
}

code, .path-text kbd {
    background: var(--code-bg);
    color: var(--text-main);
    border-radius: 0.25rem;
    padding: 0.1em 0.35em;
}

/* ── Navbar ─────────────────────────────────────────────────── */
.navbar {
    background: var(--navbar-bg) !important;
    border-bottom: 1px solid var(--border-color);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
}
.navbar-brand {
    font-weight: 700;
    letter-spacing: 0.04em;
    color: var(--accent) !important;
    font-size: 1rem;
    text-transform: uppercase;
}
.navbar-brand span { color: var(--text-soft); font-weight: 400; }
.nav-link {
    color: var(--text-soft) !important;
    font-size: 0.82rem;
    letter-spacing: 0.03em;
    padding: 0.4rem 0.75rem !important;
    border-radius: 0.375rem;
    transition: color 0.15s, background 0.15s;
}
.nav-link:hover {
    color: var(--text-main) !important;
    background: var(--hover-bg);
}
.nav-link.active {
    color: var(--accent) !important;
    background: var(--info-soft);
}
.navbar .dropdown-menu {
    background: var(--panel-strong);
    border: 1px solid var(--border-strong);
    box-shadow: var(--surface-shadow);
    --bs-dropdown-link-color: var(--text-main);
    --bs-dropdown-link-hover-bg: var(--hover-bg);
    --bs-dropdown-link-hover-color: var(--accent);
    --bs-dropdown-link-active-bg: var(--info-soft);
    --bs-dropdown-link-active-color: var(--accent);
}
.navbar .dropdown-item { color: var(--text-main); }
.navbar .dropdown-item:hover,
.navbar .dropdown-item:focus {
    background: var(--hover-bg);
    color: var(--accent);
}
.navbar .dropdown-divider { border-color: var(--border-color); }
.navbar-toggler-icon {
    filter: none;
}

/* ── Cards ──────────────────────────────────────────────────── */
.card {
    background: var(--panel);
    border: 1px solid var(--border-color);
    border-radius: 0.75rem;
    box-shadow: var(--surface-shadow);
    color: var(--text-main);
}
.card-header {
    background: var(--card-header-bg);
    border-bottom: 1px solid var(--border-color);
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-main);
    padding: 0.65rem 1rem;
}
.card-body { color: var(--text-main); }
.card-footer {
    background: transparent;
    border-top: 1px solid var(--border-color);
    color: var(--text-soft);
}

/* ── Tables ─────────────────────────────────────────────────── */
.table {
    --bs-table-color: var(--text-main);
    --bs-table-bg: transparent;
    --bs-table-border-color: var(--border-color);
    --bs-table-hover-bg: var(--hover-bg);
    --bs-table-hover-color: var(--text-main);
    --bs-table-striped-bg: rgba(148, 163, 184, 0.05);
    --bs-table-striped-color: var(--text-main);
    color: var(--text-main);
    font-size: 0.82rem;
}
.table > thead > tr > th {
    background: var(--card-header-bg);
    border-bottom: 1px solid var(--border-strong);
    color: var(--text-soft);
    font-size: 0.74rem;
    font-weight: 600;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    white-space: nowrap;
    padding: 0.55rem 0.75rem;
}
.table > tbody > tr > td {
    border-color: var(--border-color);
    padding: 0.5rem 0.75rem;
    vertical-align: middle;
    color: var(--text-main);
}
.table-hover > tbody > tr:hover > td {
    background: var(--hover-bg);
    color: var(--text-main);
}

/* ── Path display ───────────────────────────────────────────── */
.path-text {
    font-family: 'JetBrains Mono', 'Fira Code', monospace;
    font-size: 0.76rem;
    color: var(--text-soft);
    word-break: break-all;
}
.path-text.proposed { color: var(--accent); }
.path-filename {
    font-weight: 600;
    color: var(--text-main);
}

/* ── Confidence badges ──────────────────────────────────────── */
.badge-confidence-HIGH {
    background: var(--success-soft);
    color: var(--badge-high);
    border: 1px solid rgba(74, 222, 128, 0.35);
}
.badge-confidence-MEDIUM {
    background: var(--warning-soft);
    color: var(--badge-med);
    border: 1px solid rgba(253, 224, 71, 0.35);
}
.badge-confidence-LOW {
    background: var(--danger-soft);
    color: var(--badge-low);
    border: 1px solid rgba(251, 113, 133, 0.35);
}
.badge-confidence-UNEVALUATED {
    background: rgba(148, 163, 184, 0.14);
    color: var(--text-soft);
    border: 1px solid rgba(148, 163, 184, 0.32);
}

/* ── Forms ──────────────────────────────────────────────────── */
.form-label {
    color: var(--text-soft);
    font-weight: 500;
}
.form-control, .form-select {
    background: var(--form-bg);
    border: 1px solid var(--form-border);
    color: var(--text-main);
    font-size: 0.85rem;
}
.form-control::placeholder {
    color: var(--placeholder);
    opacity: 1;
}
.form-control:focus, .form-select:focus {
    background: var(--form-bg);
    border-color: var(--accent);
    color: var(--text-main);
    box-shadow: 0 0 0 3px var(--focus-ring);
}
.form-control:disabled, .form-select:disabled,
.form-control[readonly] {
    background: var(--hover-bg);
    color: var(--text-soft);
    opacity: 1;
}
.form-check-input {
    background-color: var(--form-bg);
    border-color: var(--form-border);
}
.form-check-input:checked {
    background-color: var(--accent);
    border-color: var(--accent);
}
.form-check-label { color: var(--text-main); }
.input-group-text {
    background: var(--card-header-bg);
    border-color: var(--form-border);
    color: var(--text-soft);
}

/* ── Buttons ────────────────────────────────────────────────── */
.btn-xs {
    padding: 0.18rem 0.5rem;
    font-size: 0.74rem;
    border-radius: 0.3rem;
}
.btn-outline-secondary {
    color: var(--text-soft);
    border-color: var(--border-strong);
}
.btn-outline-secondary:hover,
.btn-outline-secondary:focus {
    color: var(--text-main);
    background: var(--hover-bg);
    border-color: var(--text-soft);
}
.btn-outline-primary {
    color: var(--accent);
    border-color: var(--accent);
}
.btn-outline-primary:hover,
.btn-outline-primary:focus {
    color: var(--bg-body);
    background: var(--accent);
    border-color: var(--accent);
}
.btn-primary {
    --bs-btn-bg: var(--accent);
    --bs-btn-border-color: var(--accent);
    --bs-btn-hover-bg: var(--accent-hover);
    --bs-btn-hover-border-color: var(--accent-hover);
    --bs-btn-color: #0b1118;
    --bs-btn-hover-color: #0b1118;
    --bs-btn-active-color: #0b1118;
    font-weight: 600;
}

/* ── Stat cards ─────────────────────────────────────────────── */
.stat-card .stat-label {
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-soft);
    margin-bottom: 0.25rem;
}
.stat-card .stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-main);
    line-height: 1;
}
.stat-card .stat-sub {
    font-size: 0.72rem;
    color: var(--text-soft);
    margin-top: 0.25rem;
}

/* ── Theme toggle ───────────────────────────────────────────── */
.theme-toggle {
    background: none;
    border: 1px solid var(--border-color);
    color: var(--text-soft);
    border-radius: 999px;
    padding: 0.28rem 0.65rem;
    font-size: 0.78rem;
    cursor: pointer;
    transition: border-color 0.15s, color 0.15s, background 0.15s;
}
.theme-toggle:hover {
    border-color: var(--accent);
    color: var(--accent);
    background: var(--info-soft);
}

/* ── Alert flash ────────────────────────────────────────────── */
.flash-bar {
    border-radius: 0;
    border-left: none;
    border-right: none;
    border-top: none;
    font-size: 0.84rem;
    padding: 0.5rem 1.25rem;
    margin-bottom: 0;
}
[data-bs-theme="dark"] .alert-success {
    --bs-alert-color: #bbf7d0;
    --bs-alert-bg: rgba(34, 197, 94, 0.16);
    --bs-alert-border-color: rgba(74, 222, 128, 0.28);
}
[data-bs-theme="dark"] .alert-danger {
    --bs-alert-color: #fecdd3;
    --bs-alert-bg: rgba(244, 63, 94, 0.16);
    --bs-alert-border-color: rgba(251, 113, 133, 0.28);
}
[data-bs-theme="dark"] .alert-warning {
    --bs-alert-color: #fef08a;
    --bs-alert-bg: rgba(250, 204, 21, 0.14);
    --bs-alert-border-color: rgba(253, 224, 71, 0.28);
}
[data-bs-theme="dark"] .alert-info {
    --bs-alert-color: #bae6fd;
    --bs-alert-bg: rgba(94, 200, 245, 0.14);
    --bs-alert-border-color: rgba(94, 200, 245, 0.28);
}

/* ── Page wrapper ───────────────────────────────────────────── */
.page-wrap {
    padding: 1.5rem;
    max-width: 1600px;
    margin: 0 auto;
}

/* ── Role badge ─────────────────────────────────────────────── */
.role-badge {
    font-size: 0.68rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    padding: 0.2rem 0.5rem;
    border-radius: 999px;
    background: var(--info-soft);
    color: var(--accent);
    border: 1px solid rgba(94, 200, 245, 0.35);
}

/* ── Modals / list groups ───────────────────────────────────── */
.modal-content {
    background: var(--panel);
    border: 1px solid var(--border-strong);
    color: var(--text-main);
}
.modal-header, .modal-footer {
    border-color: var(--border-color);
}
.modal-title { color: var(--text-main); }
.list-group-item {
    background: var(--panel);
    border-color: var(--border-color);
    color: var(--text-main);
}
.pagination .page-link {
    background: var(--panel);
    border-color: var(--border-color);
    color: var(--text-soft);
}
.pagination .page-link:hover {
    background: var(--hover-bg);
    color: var(--accent);
    border-color: var(--border-strong);
}
.pagination .page-item.active .page-link {
    background: var(--accent);
    border-color: var(--accent);
    color: #0b1118;
}
.pagination .page-item.disabled .page-link {
    background: var(--panel);
    color: var(--placeholder);
}
</style>

<?php if (isset($extraHead)) echo $extraHead; ?>
</head>

<body>

<!-- ── Navbar ──────────────────────────────────────────────── -->
<nav class="navbar navbar-expand-lg sticky-top">
  <div class="container-fluid px-3">

    <a class="navbar-brand me-3" href="/dashboard">
      MM <span>/ Media Manager</span>
    </a>

    <button class="navbar-toggler border-0" type="button"
            data-bs-toggle="collapse" data-bs-target="#mainNav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav me-auto gap-1">
        <?php if (Auth::check()): ?>
        <li class="nav-item">
          <a class="nav-link<?php echo navActive('/dashboard', $currentPath); ?>" href="/dashboard">
            Home
          </a>
        </li>
        <?php if (Auth::isAdmin()): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?php echo navActive('/dictionary', $currentPath) . navActive('/schedule', $currentPath) . navActive('/legacy-map', $currentPath); ?>"
             href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Setup
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="/dictionary">1. Shows</a></li>
            <li><a class="dropdown-item" href="/schedule">2. Timeline</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="/legacy-map">Legacy Map</a></li>
          </ul>
        </li>
        <?php endif; ?>
        <?php if (Auth::isAdmin()): ?>
        <li class="nav-item">
          <a class="nav-link<?php echo navActive('/scan', $currentPath); ?>" href="/scan">Scan</a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
          <a class="nav-link<?php echo navActive('/queue', $currentPath); ?>" href="/queue">Catalog</a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?php echo navActive('/glue', $currentPath); ?>" href="/glue">Glue</a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?php echo navActive('/show-audit', $currentPath); ?>" href="/show-audit">Gaps</a>
        </li>
        <?php if (Auth::isAdmin()): ?>
        <li class="nav-item">
          <a class="nav-link<?php echo navActive('/execute', $currentPath); ?>" href="/execute">Execute</a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?php echo navActive('/split', $currentPath); ?>" href="/split">Split</a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?php echo navActive('/settings', $currentPath); ?>" href="/settings">Settings</a>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?php echo navActive('/audit', $currentPath) . navActive('/rollback', $currentPath); ?>"
             href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Admin
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="/audit">Audit log</a></li>
            <li><a class="dropdown-item" href="/rollback">Rollback</a></li>
          </ul>
        </li>
        <?php endif; ?>
        <?php endif; ?>
      </ul>

      <div class="d-flex align-items-center gap-2">
        <?php if ($currentUser): ?>
          <span class="role-badge"><?php echo View::e($currentUser['role']); ?></span>
          <span class="text-soft" style="font-size:0.78rem">
            <?php echo View::e($currentUser['email']); ?>
          </span>
          <a href="/logout" class="btn btn-outline-secondary btn-sm">Sign out</a>
        <?php endif; ?>
        <button class="theme-toggle ms-1" id="themeToggle" title="Toggle theme">☀</button>
      </div>
    </div>
  </div>
</nav>

<?php if ($flashSuccess): ?>
<div class="alert alert-success flash-bar mb-0">
  <?php echo View::e($flashSuccess); ?>
</div>
<?php endif; ?>
<?php if ($flashError): ?>
<div class="alert alert-danger flash-bar mb-0">
  <?php echo View::e($flashError); ?>
</div>
<?php endif; ?>

<div class="page-wrap">

<script>
// Theme toggle
(function () {
    var btn   = document.getElementById('themeToggle');
    var html  = document.documentElement;
    function apply(t) {
        html.setAttribute('data-bs-theme', t);
        localStorage.setItem('mm-theme', t);
        btn.textContent = t === 'dark' ? '☀' : '☾';
    }
    apply(localStorage.getItem('mm-theme') || 'dark');
    btn.addEventListener('click', function () {
        apply(html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark');
    });
})();
</script>
