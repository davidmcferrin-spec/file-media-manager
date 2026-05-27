<?php

declare(strict_types=1);

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var array<string, mixed> $job */
/** @var list<array<string, mixed>> $jobFiles */
/** @var int $totalQueued */
/** @var array<string, int> $confidence */
/** @var int $protectedCount */
/** @var bool $canStop */
/** @var bool $canDelete */
$status = (string) ($job['status'] ?? '');
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Scan Job #<?php echo (int) $job['id']; ?></h1>
    <p class="mb-0" style="color:var(--text-soft);font-size:0.82rem;">
      <?php echo View::e($job['source_name']); ?>
      <?php if (!empty($job['subpath'])): ?>
      / <?php echo View::e($job['subpath']); ?>
      <?php endif; ?>
      — <?php echo View::e($job['created_by_email']); ?>
    </p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($canStop): ?>
    <form method="post" action="/scan/cancel" class="d-inline"
          onsubmit="return confirm('Stop this scan? Files already queued will remain in the review queue.');">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
      <button type="submit" class="btn btn-outline-danger btn-sm">Stop Scan</button>
    </form>
    <?php endif; ?>
    <?php if ($canDelete): ?>
    <form method="post" action="/scan/delete" class="d-inline"
          onsubmit="return confirm('Delete scan job #<?php echo (int) $job['id']; ?> and remove <?php echo number_format($totalQueued); ?> queued file(s) from the review queue?');">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
      <button type="submit" class="btn btn-outline-danger btn-sm">Delete Scan</button>
    </form>
    <?php elseif ($protectedCount > 0): ?>
    <span class="align-self-center" style="font-size:0.78rem;color:var(--text-soft)">
      Cannot delete — <?php echo number_format($protectedCount); ?> protected file(s)
    </span>
    <?php endif; ?>
    <a href="/scan" class="btn btn-outline-secondary btn-sm">All Scans</a>
    <?php if (Auth::isAdmin() && in_array($status, ['COMPLETED', 'CANCELLED', 'FAILED'], true) && $totalQueued > 0): ?>
    <form method="post" action="/scan/apply-map" class="d-inline"
          onsubmit="return confirm('Apply legacy rename map to this scan job? Matches map rows and reconciles confidence.');">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
      <button type="submit" class="btn btn-outline-warning btn-sm">Apply Legacy Map</button>
    </form>
    <?php endif; ?>
    <a href="/queue?scan_job_id=<?php echo (int) $job['id']; ?>" class="btn btn-primary btn-sm">Review Queue</a>
  </div>
</div>

<?php
$total  = (int) ($job['total_files'] ?? 0);
$done   = (int) ($job['processed_files'] ?? 0);
$pct    = $total > 0 ? round(($done / $total) * 100) : 0;
?>

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label">Status</div>
        <div class="stat-value" style="font-size:1.4rem"><?php echo View::e($status); ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label">Queued Files</div>
        <div class="stat-value"><?php echo number_format($totalQueued); ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-2">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label">High</div>
        <div class="stat-value" style="color:#22c55e"><?php echo $confidence['HIGH']; ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-2">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label">Medium</div>
        <div class="stat-value" style="color:#facc15"><?php echo $confidence['MEDIUM']; ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-2">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label">Low</div>
        <div class="stat-value" style="color:#f87171"><?php echo $confidence['LOW']; ?></div>
      </div>
    </div>
  </div>
</div>

<?php if ($status === 'RUNNING' || ($status === 'PENDING' && empty($job['cancel_requested']))): ?>
<div class="card mb-4">
  <div class="card-body py-3">
    <div class="d-flex justify-content-between mb-1">
      <span style="font-size:0.82rem;color:var(--text-soft)">
        <?php echo $status === 'PENDING' ? 'Waiting to start…' : 'Processing…'; ?>
      </span>
      <span style="font-size:0.82rem"><?php echo $done; ?> / <?php echo $total; ?> (<?php echo $pct; ?>%)</span>
    </div>
    <div class="progress" style="height:8px">
      <div class="progress-bar progress-bar-striped progress-bar-animated" style="width:<?php echo $pct; ?>%"></div>
    </div>
    <p class="mb-0 mt-2" style="font-size:0.78rem;color:var(--text-soft)">
      <?php if (!empty($job['cancel_requested'])): ?>
      Stop requested — scan will halt shortly.
      <?php else: ?>
      Refresh this page to update progress.
      <?php endif; ?>
    </p>
  </div>
</div>
<meta http-equiv="refresh" content="5">
<?php elseif ($status === 'CANCELLED'): ?>
<div class="alert alert-warning mb-4" style="font-size:0.84rem;">
  Scan was stopped<?php echo $done > 0 ? ' after processing ' . number_format($done) . ' file(s)' : ''; ?>.
  Queued results remain in the review queue until you delete this scan job.
</div>
<?php endif; ?>

<?php if ($status === 'FAILED' && !empty($job['error_message'])): ?>
<div class="alert alert-danger mb-4"><?php echo View::e($job['error_message']); ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-header">Sample Results (first 50)</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Original</th>
          <th>Proposed</th>
          <th>Conf</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($jobFiles === []): ?>
        <tr>
          <td colspan="3" class="text-center py-4" style="color:var(--text-soft)">
            <?php echo $status === 'RUNNING' ? 'Scan in progress…' : 'No files queued.'; ?>
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($jobFiles as $file): ?>
        <tr>
          <td>
            <div class="path-filename"><?php echo View::e($file['original_filename']); ?></div>
            <div class="path-text"><?php echo View::e($file['original_dir']); ?></div>
          </td>
          <td>
            <?php if ($file['proposed_filename']): ?>
            <div class="path-filename proposed"><?php echo View::e($file['proposed_filename']); ?></div>
            <div class="path-text proposed"><?php echo View::e($file['proposed_dir']); ?></div>
            <?php else: ?>
            <span style="color:var(--text-soft)">—</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge badge-confidence-<?php echo View::e($file['confidence']); ?>">
              <?php echo View::e($file['confidence']); ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
