<?php

declare(strict_types=1);

use MediaManager\Support\View;
use MediaManager\Auth\Auth;

// Build confidence map
$confMap = ['LOW' => 0, 'MEDIUM' => 0, 'HIGH' => 0];
foreach ($confidenceStats as $row) {
    $confMap[$row['confidence']] = (int) $row['cnt'];
}
$totalPending = (int) ($queueStats['pending'] ?? 0);
?>

<!-- ── Page header ──────────────────────────────────────────── -->
<div class="d-flex flex-wrap justify-content-between align-items-start mb-2 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Home</h1>
    <p class="mb-0" style="color:var(--text-soft);font-size:0.8rem;">
      Follow the workflow: define Shows and Timeline, Scan libraries, Catalog files, check Gaps, then Execute.
    </p>
  </div>
  <?php if (Auth::isAdmin()): ?>
  <div class="d-flex gap-2 flex-wrap">
    <a href="/scan" class="btn btn-outline-secondary btn-sm">Scan</a>
    <a href="/queue" class="btn btn-primary btn-sm">Catalog</a>
  </div>
  <?php else: ?>
  <a href="/queue" class="btn btn-primary btn-sm">Catalog</a>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/_nav.php'; ?>
<?php require __DIR__ . '/_workflow.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <h2 class="h6 mb-0" style="letter-spacing:0.03em;">Pipeline health</h2>
  <span class="path-text" style="font-size:0.72rem">Queue and scan activity</span>
</div>

<!-- ── Stat cards ────────────────────────────────────────────── -->
<div class="row g-3 mb-4">

  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label">Total Files</div>
        <div class="stat-value"><?php echo number_format((int)($queueStats['total'] ?? 0)); ?></div>
      </div>
    </div>
  </div>

  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label">Pending Review</div>
        <div class="stat-value" style="color:var(--accent)">
          <?php echo number_format($totalPending); ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label">Approved</div>
        <div class="stat-value" style="color:#22c55e">
          <?php echo number_format((int)($queueStats['approved'] ?? 0)); ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label">Executed</div>
        <div class="stat-value" style="color:#a78bfa">
          <?php echo number_format((int)($queueStats['executed'] ?? 0)); ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label">Flagged</div>
        <div class="stat-value" style="color:#facc15">
          <?php echo number_format((int)($queueStats['flagged'] ?? 0)); ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label">Needs Split</div>
        <div class="stat-value" style="color:#fb923c">
          <?php echo number_format((int)($queueStats['needs_split'] ?? 0)); ?>
        </div>
        <?php if ($splitPending > 0): ?>
        <div class="stat-sub"><?php echo $splitPending; ?> in split queue</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<!-- ── Confidence breakdown + recent scans ───────────────────── -->
<div class="row g-3 mb-4">

  <!-- Confidence breakdown -->
  <div class="col-xl-4">
    <div class="card h-100">
      <div class="card-header">Pending — By Confidence</div>
      <div class="card-body">
        <?php if ($totalPending === 0): ?>
          <p class="text-secondary mb-0" style="font-size:0.82rem;">No pending files.</p>
        <?php else: ?>
          <?php foreach (['LOW' => '#f87171', 'MEDIUM' => '#facc15', 'HIGH' => '#22c55e'] as $conf => $color): ?>
          <?php $cnt = $confMap[$conf]; $pct = $totalPending > 0 ? round($cnt / $totalPending * 100) : 0; ?>
          <div class="mb-3">
            <div class="d-flex justify-content-between mb-1" style="font-size:0.78rem;">
              <span style="color:<?php echo $color; ?>;font-weight:600;">
                <?php echo $conf; ?>
              </span>
              <span style="color:var(--text-soft)">
                <?php echo number_format($cnt); ?> &nbsp;(<?php echo $pct; ?>%)
              </span>
            </div>
            <div class="progress" style="height:6px;background:var(--panel-strong);">
              <div class="progress-bar" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>"></div>
            </div>
          </div>
          <?php endforeach; ?>
          <a href="/queue?confidence=LOW" class="btn btn-sm btn-outline-danger mt-1" style="font-size:0.76rem;">
            Review LOW confidence →
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Recent scans -->
  <div class="col-xl-8">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Recent Scan Jobs</span>
        <?php if (Auth::isAdmin()): ?>
        <a href="/scan" class="btn btn-xs btn-outline-secondary">View All</a>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if (empty($recentScans)): ?>
        <div class="px-3 py-3" style="color:var(--text-soft);font-size:0.82rem;">
          No scans yet. <a href="/scan">Start a scan</a> to populate the queue.
        </div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>Source</th>
                <th>Status</th>
                <th>Files</th>
                <th>Started</th>
                <th>By</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentScans as $scan): ?>
              <tr>
                <td><?php echo View::e($scan['source_name']); ?></td>
                <td>
                  <?php
                    $cls = match($scan['status']) {
                        'COMPLETED' => 'success',
                        'RUNNING'   => 'info',
                        'FAILED'    => 'danger',
                        default     => 'secondary',
                    };
                  ?>
                  <span class="badge bg-<?php echo $cls; ?>" style="font-size:0.7rem;">
                    <?php echo View::e($scan['status']); ?>
                  </span>
                </td>
                <td>
                  <?php echo number_format((int)$scan['processed_files']); ?>
                  / <?php echo number_format((int)$scan['total_files']); ?>
                </td>
                <td class="path-text">
                  <?php echo View::e(substr($scan['created_at'] ?? '', 0, 16)); ?>
                </td>
                <td style="color:var(--text-soft);font-size:0.78rem;">
                  <?php echo View::e($scan['created_by_email']); ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>
