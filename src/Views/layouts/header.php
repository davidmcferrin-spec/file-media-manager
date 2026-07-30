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
    --bg-body:           #060e1a;
    --bg-body-alt:       #0b1524;
    --panel:             #131f34;
    --panel-strong:      #1a2944;
    --border-color:      rgba(148, 163, 184, 0.22);
    --border-strong:     rgba(148, 163, 184, 0.38);
    --text-main:         #eef2f8;
    --text-soft:         #9cadc4;
    --accent:            #56c4f5;
    --accent-hover:      #7dd8fc;
    --navbar-bg:         rgba(6, 14, 26, 0.94);
    --card-header-bg:    #182742;
    --form-bg:           #0a1322;
    --form-border:       rgba(148, 163, 184, 0.28);
    --success-soft:      rgba(34,  197, 94,  0.20);
    --warning-soft:      rgba(250, 204, 21,  0.18);
    --danger-soft:       rgba(248, 113, 113, 0.20);
    --info-soft:         rgba(86,  196, 245, 0.14);
    --hover-bg:          rgba(148, 163, 184, 0.07);
    --surface-shadow:    0 12px 36px rgba(0, 0, 0, 0.36);
    --badge-high:        #22c55e;
    --badge-med:         #facc15;
    --badge-low:         #f87171;
}

/* ── Design tokens: light ───────────────────────────────────── */
[data-bs-theme="light"] {
    --bg-body:           #f0f6ff;
    --bg-body-alt:       #e4eefb;
    --panel:             rgba(255, 255, 255, 0.97);
    --panel-strong:      #ffffff;
    --border-color:      rgba(0, 0, 0, 0.09);
    --border-strong:     rgba(0, 0, 0, 0.14);
    --text-main:         #1e293b;
    --text-soft:         #64748b;
    --accent:            #0ea5e9;
    --accent-hover:      #0284c7;
    --navbar-bg:         rgba(255, 255, 255, 0.94);
    --card-header-bg:    rgba(241, 245, 249, 0.9);
    --form-bg:           #ffffff;
    --form-border:       rgba(0, 0, 0, 0.12);
    --success-soft:      rgba(34,  197, 94,  0.12);
    --warning-soft:      rgba(245, 158, 11,  0.12);
    --danger-soft:       rgba(239, 68,  68,  0.12);
    --info-soft:         rgba(14,  165, 233, 0.10);
    --hover-bg:          rgba(0, 0, 0, 0.04);
    --surface-shadow:    0 14px 40px rgba(2, 6, 23, 0.10);
    --badge-high:        #16a34a;
    --badge-med:         #ca8a04;
    --badge-low:         #dc2626;
}

/* ── Base ───────────────────────────────────────────────────── */
body {
    min-height: 100vh;
    color: var(--text-main);
    background: linear-gradient(170deg, var(--bg-body) 0%, var(--bg-body-alt) 100%);
    font-family: 'JetBrains Mono', 'Fira Code', 'Consolas', monospace;
    font-size: 0.9rem;
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
.nav-link:hover, .nav-link.active {
    color: var(--text-main) !important;
    background: var(--hover-bg);
}
.nav-link.active { color: var(--accent) !important; }

/* ── Cards ──────────────────────────────────────────────────── */
.card {
    background: var(--panel);
    border: 1px solid var(--border-color);
    border-radius: 0.75rem;
    box-shadow: var(--surface-shadow);
}
.card-header {
    background: var(--card-header-bg);
    border-bottom: 1px solid var(--border-color);
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-soft);
    padding: 0.65rem 1rem;
}

/* ── Tables ─────────────────────────────────────────────────── */
.table {
    color: var(--text-main);
    font-size: 0.82rem;
}
.table > thead > tr > th {
    background: var(--card-header-bg);
    border-bottom: 1px solid var(--border-strong);
    color: var(--text-soft);
    font-size: 0.74rem;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    white-space: nowrap;
    padding: 0.55rem 0.75rem;
}
.table > tbody > tr > td {
    border-color: var(--border-color);
    padding: 0.5rem 0.75rem;
    vertical-align: middle;
}
.table-hover > tbody > tr:hover > td {
    background: var(--hover-bg);
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
.badge-confidence-HIGH   { background: var(--success-soft); color: var(--badge-high); border: 1px solid rgba(34,197,94,0.3); }
.badge-confidence-MEDIUM { background: var(--warning-soft); color: var(--badge-med);  border: 1px solid rgba(250,204,21,0.3); }
.badge-confidence-LOW    { background: var(--danger-soft);  color: var(--badge-low);  border: 1px solid rgba(248,113,113,0.3); }

/* ── Forms ──────────────────────────────────────────────────── */
.form-control, .form-select {
    background: var(--form-bg);
    border: 1px solid var(--form-border);
    color: var(--text-main);
    font-size: 0.85rem;
}
.form-control:focus, .form-select:focus {
    background: var(--form-bg);
    border-color: var(--accent);
    color: var(--text-main);
    box-shadow: 0 0 0 3px rgba(86, 196, 245, 0.15);
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
    transition: border-color 0.15s, color 0.15s;
}
.theme-toggle:hover {
    border-color: var(--accent);
    color: var(--accent);
}

/* ── Buttons ────────────────────────────────────────────────── */
.btn-xs {
    padding: 0.18rem 0.5rem;
    font-size: 0.74rem;
    border-radius: 0.3rem;
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
    border: 1px solid rgba(86, 196, 245, 0.25);
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
            Dashboard
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?php echo navActive('/queue', $currentPath); ?>" href="/queue">
            Queue
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?php echo navActive('/show-audit', $currentPath) . navActive('/dictionary', $currentPath) . navActive('/schedule', $currentPath) . navActive('/legacy-map', $currentPath); ?>"
             href="<?php echo Auth::isAdmin() ? '/dictionary' : '/show-audit'; ?>">
            Shows
          </a>
        </li>
        <?php if (Auth::isAdmin()): ?>
        <li class="nav-item">
          <a class="nav-link<?php echo navActive('/scan', $currentPath); ?>" href="/scan">
            Scanner
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?php echo navActive('/execute', $currentPath); ?>" href="/execute">
            Execute
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?php echo navActive('/split', $currentPath); ?>" href="/split">
            Split Queue
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?php echo navActive('/audit', $currentPath); ?>" href="/audit">
            Audit
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?php echo navActive('/settings', $currentPath); ?>" href="/settings">
            Settings
          </a>
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
