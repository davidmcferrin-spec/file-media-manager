<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var bool $wipeEnabled */
?>

<div class="card border-danger mb-4">
  <div class="card-header text-danger">Danger Zone</div>
  <div class="card-body">
    <h2 class="h6">Wipe scan / catalog / shows data</h2>
    <p class="mb-3" style="color:var(--text-soft);font-size:0.8rem">
      Permanently clears workflow data so you can start Setup again. This does <strong>not</strong> delete files on the NAS.
    </p>

    <div class="row g-3 mb-3" style="font-size:0.78rem">
      <div class="col-md-6">
        <div class="p-2 rounded" style="background:var(--danger-soft)">
          <strong class="d-block mb-1">Deleted</strong>
          Scan jobs, catalog files, split queue, shows, timeline, expected gaps,
          legacy rename map, conversion rules, scan ignore paths, audit log,
          sharded media cache (thumbs / previews / split proxies), and legacy
          thumbnail/preview/split-proxy folders.
        </div>
      </div>
      <div class="col-md-6">
        <div class="p-2 rounded" style="background:var(--success-soft)">
          <strong class="d-block mb-1">Kept</strong>
          Users, system settings, LDAP config, NAS sources, media types, and schema migrations.
        </div>
      </div>
    </div>

    <?php if (!$wipeEnabled): ?>
    <div class="alert alert-warning mb-0" style="font-size:0.84rem">
      Wipe is disabled. Set <code>ALLOW_DB_WIPE=true</code> in the server <code>.env</code> and reload PHP to enable this control.
    </div>
    <?php else: ?>
    <form method="post" action="/settings/danger/wipe"
          onsubmit="return confirm('This permanently wipes catalog, shows, timeline, scans, and caches. Continue?');">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <div class="mb-3">
        <label class="form-label">Type <code>WIPE</code> to confirm</label>
        <input type="text" name="confirm" class="form-control form-control-sm" autocomplete="off" required
               placeholder="WIPE" style="max-width:16rem">
      </div>
      <button type="submit" class="btn btn-danger btn-sm">Wipe workflow data</button>
    </form>
    <?php endif; ?>
  </div>
</div>
