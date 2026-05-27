<?php declare(strict_types=1); ?>
</div><!-- /.page-wrap -->

<footer class="mt-auto py-3 px-3" style="border-top:1px solid var(--border-color);margin-top:2rem!important;">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2"
       style="max-width:1600px;margin:0 auto;font-size:0.72rem;color:var(--text-soft);">
    <span>
      <?php echo htmlspecialchars(env('APP_NAME', 'Media Manager'), ENT_QUOTES, 'UTF-8'); ?>
      &mdash; NewsNation
    </span>
    <span><?php echo date('Y'); ?></span>
  </div>
</footer>

<?php
$bootstrapJsPath = dirname(__DIR__, 3) . '/public/vendor/bootstrap/js/bootstrap.bundle.min.js';
$bootstrapJsOk     = is_readable($bootstrapJsPath);
?>
<?php if ($bootstrapJsOk): ?>
<script src="/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<?php else: ?>
<script>
console.error('Bootstrap JS not found at public/vendor/bootstrap/js/bootstrap.bundle.min.js — run ./setup.sh');
document.addEventListener('DOMContentLoaded', function () {
    var bar = document.createElement('div');
    bar.className = 'alert alert-danger flash-bar mb-0';
    bar.textContent = 'Bootstrap assets are missing on this server — modals and previews will not work. Run ./setup.sh to install vendor files.';
    document.body.prepend(bar);
});
</script>
<?php endif; ?>
<?php if (isset($extraScripts)) echo $extraScripts; ?>
</body>
</html>
