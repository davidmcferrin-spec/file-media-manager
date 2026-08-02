<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var list<array<string, mixed>> $eras */
/** @var array<int, int> $windowCounts */
$showsTab = 'eras';
?>
<?php require dirname(__DIR__) . '/shows/_nav.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h1 class="h3 mb-1">Broadcast eras</h1>
    <p class="path-text mb-0">
      Define network on-air windows over time (e.g. 2 hours/day early, ~20 hours today). Gaps only expects hours inside these windows when an era covers the date.
    </p>
  </div>
  <a href="/shows" class="btn btn-outline-secondary btn-sm">Shows</a>
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
        <h2 class="h6 mb-3">New era</h2>
        <form method="post" action="/eras/create">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <div class="mb-2">
            <label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" required placeholder="Launch prime (2020)">
          </div>
          <div class="mb-2">
            <label class="form-label">From</label>
            <input type="date" name="effective_from" class="form-control" required>
          </div>
          <div class="mb-2">
            <label class="form-label">To (blank = current)</label>
            <input type="date" name="effective_to" class="form-control">
          </div>
          <div class="mb-2">
            <label class="form-label">Sort order</label>
            <input type="number" name="sort_order" class="form-control" value="0">
          </div>
          <div class="mb-3">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2"></textarea>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Create era</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>Name</th>
            <th>Dates</th>
            <th>Windows</th>
            <th>Active</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($eras === []): ?>
          <tr><td colspan="5" class="path-text text-center py-4">No eras yet — create one to define network coverage.</td></tr>
          <?php else: ?>
          <?php foreach ($eras as $era): ?>
          <?php $eid = (int) $era['id']; ?>
          <tr>
            <td><?php echo View::e((string) $era['name']); ?></td>
            <td class="path-text text-nowrap" style="font-size:0.8rem">
              <?php echo View::e(substr((string) $era['effective_from'], 0, 10)); ?>
              →
              <?php echo $era['effective_to'] ? View::e(substr((string) $era['effective_to'], 0, 10)) : 'current'; ?>
            </td>
            <td><?php echo (int) ($windowCounts[$eid] ?? 0); ?></td>
            <td><?php echo !empty($era['active']) ? 'Yes' : 'No'; ?></td>
            <td class="text-end">
              <a class="btn btn-outline-secondary btn-xs" href="/eras/<?php echo $eid; ?>">Open</a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
