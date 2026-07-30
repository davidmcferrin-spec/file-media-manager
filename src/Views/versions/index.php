<?php

declare(strict_types=1);

use MediaManager\Support\AppVersion;
use MediaManager\Support\View;

/** @var string $version */
/** @var list<array{version: string, date: string, body: string}> $entries */
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Versions</h1>
    <p class="mb-0" style="color:var(--text-soft);font-size:0.8rem;">
      Running <strong style="color:var(--text-main);">v<?php echo View::e($version); ?></strong>
      — release notes from <code>CHANGELOG.md</code>.
    </p>
  </div>
</div>

<?php if ($entries === []): ?>
<div class="card">
  <div class="card-body text-soft" style="font-size:0.85rem;">
    No changelog entries found.
  </div>
</div>
<?php else: ?>
<div class="d-flex flex-column gap-3">
  <?php foreach ($entries as $entry): ?>
  <div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-baseline gap-2">
      <span style="font-variant-numeric:tabular-nums;">
        v<?php echo View::e($entry['version']); ?>
        <?php if ($entry['version'] === $version): ?>
        <span class="badge text-bg-primary ms-1" style="font-size:0.65rem;">current</span>
        <?php endif; ?>
      </span>
      <span class="text-soft" style="font-size:0.75rem;"><?php echo View::e($entry['date']); ?></span>
    </div>
    <div class="card-body version-notes" style="font-size:0.85rem;">
      <?php echo AppVersion::formatBodyHtml($entry['body']); ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
