<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var list<array<string, mixed>> $sources */
?>

<div class="card">
  <div class="card-header">NAS Sources</div>
  <div class="card-body">
    <p class="mb-4" style="color:var(--text-soft);font-size:0.82rem;">
      Mount points scanned by the media manager. Paths must be accessible from the Linux host running scans.
    </p>

    <?php foreach ($sources as $source): ?>
    <form method="post" action="/settings/sources" class="border rounded p-3 mb-3"
          style="border-color:var(--border-color) !important;background:var(--form-bg);">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <input type="hidden" name="id" value="<?php echo (int) $source['id']; ?>">

      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Label</label>
          <input type="text" name="name" class="form-control" required
                 value="<?php echo View::e($source['name']); ?>">
        </div>
        <div class="col-md-8">
          <label class="form-label">Mount Path</label>
          <input type="text" name="mount_path" class="form-control" required
                 value="<?php echo View::e($source['mount_path']); ?>"
                 style="font-family:monospace;font-size:0.82rem;">
        </div>
        <div class="col-12">
          <label class="form-label">Description</label>
          <input type="text" name="description" class="form-control"
                 value="<?php echo View::e($source['description']); ?>">
        </div>
        <div class="col-12 d-flex justify-content-between align-items-center">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="active"
                   id="source-active-<?php echo (int) $source['id']; ?>"
                   <?php echo !empty($source['active']) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="source-active-<?php echo (int) $source['id']; ?>">
              Active — include in scan source list
            </label>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Save</button>
        </div>
      </div>
    </form>
    <?php endforeach; ?>
  </div>
</div>
