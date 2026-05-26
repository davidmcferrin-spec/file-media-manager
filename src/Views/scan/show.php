<?php

declare(strict_types=1);

use MediaManager\Support\View;

/** @var array<string, mixed> $job */
/** @var list<array<string, mixed>> $jobFiles */
/** @var int $totalQueued */
/** @var array<string, int> $confidence */
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
  <div class="d-flex gap-2">
    <a href="/scan" class="btn btn-outline-secondary btn-sm">All Scans</a>
    <a href="/queue" class="btn btn-primary btn-sm">Review Queue</a>
  </div>
</div>

<?php
$status = (string) $job['status'];
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

<?php if ($status === 'RUNNING' || $status === 'PENDING'): ?>
<div class="card mb-4">
  <div class="card-body py-3">
    <div class="d-flex justify-content-between mb-1">
      <span style="font-size:0.82rem;color:var(--text-soft)">Processing…</span>
      <span style="font-size:0.82rem"><?php echo $done; ?> / <?php echo $total; ?> (<?php echo $pct; ?>%)</span>
    </div>
    <div class="progress" style="height:8px">
      <div class="progress-bar progress-bar-striped progress-bar-animated" style="width:<?php echo $pct; ?>%"></div>
    </div>
    <p class="mb-0 mt-2" style="font-size:0.78rem;color:var(--text-soft)">Refresh this page to update progress.</p>
  </div>
</div>
<meta http-equiv="refresh" content="5">
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
