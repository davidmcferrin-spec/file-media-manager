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

<?php if (isset($extraScripts)) echo $extraScripts; ?>
</body>
</html>
