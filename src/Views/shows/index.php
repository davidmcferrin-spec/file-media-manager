<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var list<array<string, mixed>> $shows */
/** @var array<int, int> $slotCounts */
$showsTab = 'shows';
?>
<?php require __DIR__ . '/_nav.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h1 class="h3 mb-1">Shows</h1>
    <p class="path-text mb-0">Build each show’s identity, aliases, and schedule slots in one place.</p>
  </div>
  <a href="/eras" class="btn btn-outline-secondary btn-sm">Broadcast eras</a>
</div>

<?php if ($msg = Session::getFlash('success')): ?>
<div class="alert alert-success"><?php echo View::e((string) $msg); ?></div>
<?php endif; ?>
<?php if ($msg = Session::getFlash('error')): ?>
<div class="alert alert-danger"><?php echo View::e((string) $msg); ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">New show</h2>
        <form method="post" action="/shows/create">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <div class="mb-2">
            <label class="form-label">Canonical name</label>
            <input type="text" name="canonical_name" class="form-control" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Abbreviation</label>
            <input type="text" name="abbreviation" class="form-control" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Aliases</label>
            <textarea name="aliases" class="form-control" rows="3" placeholder="One per line or comma-separated"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2"></textarea>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Create show</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>Abbr</th>
            <th>Name</th>
            <th>Slots</th>
            <th>Active</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($shows === []): ?>
          <tr><td colspan="5" class="path-text text-center py-4">No shows yet.</td></tr>
          <?php else: ?>
          <?php foreach ($shows as $s): ?>
          <?php $sid = (int) $s['id']; ?>
          <tr>
            <td><code><?php echo View::e((string) $s['abbreviation']); ?></code></td>
            <td><?php echo View::e((string) $s['canonical_name']); ?></td>
            <td class="path-text"><?php echo (int) ($slotCounts[$sid] ?? 0); ?></td>
            <td><?php echo !empty($s['active']) ? 'Yes' : 'No'; ?></td>
            <td class="text-end">
              <a class="btn btn-outline-secondary btn-xs" href="/shows/<?php echo $sid; ?>">Open</a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
