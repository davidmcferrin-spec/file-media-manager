<?php

declare(strict_types=1);

use MediaManager\Support\View;
use MediaManager\Auth\Session;

$title     = 'Sign In — Media Manager';
$csrfToken = Session::csrfToken();

// Minimal standalone page — does not use the full header/footer
// so the login page works before any session is established.
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo View::e($title); ?></title>
<script>
(function () {
    var saved = localStorage.getItem('mm-theme');
    var theme = saved === 'light' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-bs-theme', theme);
})();
</script>
<link href="/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<script defer src="/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<style>
:root, [data-bs-theme="dark"] {
    --bg-body:    #060e1a;
    --panel:      #131f34;
    --border:     rgba(148,163,184,0.22);
    --text-main:  #eef2f8;
    --text-soft:  #9cadc4;
    --accent:     #56c4f5;
    --form-bg:    #0a1322;
    --form-border:rgba(148,163,184,0.28);
}
[data-bs-theme="light"] {
    --bg-body:    #f0f6ff;
    --panel:      #ffffff;
    --border:     rgba(0,0,0,0.09);
    --text-main:  #1e293b;
    --text-soft:  #64748b;
    --accent:     #0ea5e9;
    --form-bg:    #ffffff;
    --form-border:rgba(0,0,0,0.12);
}
body {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg-body);
    color: var(--text-main);
    font-family: 'JetBrains Mono', 'Fira Code', monospace;
    font-size: 0.9rem;
}
.login-card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 0.875rem;
    box-shadow: 0 20px 60px rgba(0,0,0,0.4);
    padding: 2.25rem 2rem;
    width: 100%;
    max-width: 380px;
}
.login-brand {
    font-size: 1.1rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--accent);
    margin-bottom: 0.25rem;
}
.login-sub {
    font-size: 0.76rem;
    color: var(--text-soft);
    margin-bottom: 1.75rem;
    letter-spacing: 0.04em;
}
.form-label {
    font-size: 0.74rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-soft);
    margin-bottom: 0.3rem;
}
.form-control {
    background: var(--form-bg);
    border: 1px solid var(--form-border);
    color: var(--text-main);
    font-size: 0.85rem;
    border-radius: 0.5rem;
}
.form-control:focus {
    background: var(--form-bg);
    border-color: var(--accent);
    color: var(--text-main);
    box-shadow: 0 0 0 3px rgba(86,196,245,0.15);
}
.btn-login {
    background: var(--accent);
    border: none;
    color: #060e1a;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    font-size: 0.82rem;
    border-radius: 0.5rem;
    padding: 0.6rem;
    width: 100%;
    margin-top: 0.5rem;
    transition: opacity 0.15s;
}
.btn-login:hover { opacity: 0.88; }
.theme-toggle {
    position: fixed;
    top: 1rem;
    right: 1rem;
    background: none;
    border: 1px solid var(--border);
    color: var(--text-soft);
    border-radius: 999px;
    padding: 0.28rem 0.65rem;
    font-size: 0.78rem;
    cursor: pointer;
}
</style>
</head>
<body>

<button class="theme-toggle" id="themeToggle">☀</button>

<div class="login-card">
  <div class="login-brand">Media Manager</div>
  <div class="login-sub">NewsNation — Broadcast Archive System</div>

  <?php if (!empty($error)): ?>
  <div class="alert alert-danger py-2 mb-3" style="font-size:0.82rem;">
    <?php echo View::e((string) $error); ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($rateLimited)): ?>
  <div class="alert alert-warning py-2 mb-3" style="font-size:0.82rem;">
    Too many failed attempts. Please wait a few minutes and try again.
  </div>
  <?php endif; ?>

  <form method="post" action="/login" autocomplete="on">
    <input type="hidden" name="_csrf" value="<?php echo View::e($csrfToken); ?>">

    <div class="mb-3">
      <label class="form-label" for="email">Email</label>
      <input type="text" class="form-control" id="email" name="email"
             value="<?php echo View::e($_POST['email'] ?? ''); ?>"
             required autocomplete="username" autofocus>
    </div>

    <div class="mb-3">
      <label class="form-label" for="password">Password</label>
      <input type="password" class="form-control" id="password" name="password"
             required autocomplete="current-password">
    </div>

    <button type="submit" class="btn-login">Sign In</button>
  </form>
</div>

<script>
(function () {
    var btn  = document.getElementById('themeToggle');
    var html = document.documentElement;
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
</body>
</html>
