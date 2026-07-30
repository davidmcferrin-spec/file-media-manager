<?php

declare(strict_types=1);

use MediaManager\Support\View;
use MediaManager\Support\WorkflowSteps;

/** @var array<string, int> $readiness */
$steps = WorkflowSteps::visibleForCurrentUser();
$statusLabel = [
    'ready'     => 'Ready',
    'attention' => 'Needs attention',
    'blocked'   => 'Blocked',
    'idle'      => 'Idle',
];
$statusClass = [
    'ready'     => 'bg-success',
    'attention' => 'bg-warning text-dark',
    'blocked'   => 'bg-secondary',
    'idle'      => 'bg-secondary',
];
?>
<div class="card mb-4">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span>Workflow</span>
    <span class="path-text" style="font-size:0.72rem">Setup → Scan → Catalog ↔ Gaps → Execute</span>
  </div>
  <div class="card-body py-3">
    <div class="row g-2">
      <?php foreach ($steps as $step): ?>
      <?php
        $st = WorkflowSteps::stepStatus((string) $step['id'], $readiness);
      ?>
      <div class="col-6 col-md-4 col-xl-2">
        <a href="<?php echo View::e($step['href']); ?>"
           class="d-block p-2 rounded text-decoration-none h-100"
           style="border:1px solid var(--border-color);background:var(--hover-bg);color:inherit">
          <div class="path-text mb-1" style="font-size:0.68rem;letter-spacing:0.04em;text-transform:uppercase">
            <?php echo View::e($step['phase_label']); ?> · <?php echo (int) $step['number']; ?>
          </div>
          <div style="font-weight:600;font-size:0.9rem"><?php echo View::e($step['label']); ?></div>
          <span class="badge <?php echo $statusClass[$st] ?? 'bg-secondary'; ?> mt-2"
                style="font-size:0.65rem"><?php echo View::e($statusLabel[$st] ?? $st); ?></span>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header">Review loop</div>
  <div class="card-body py-3">
    <p class="mb-3" style="color:var(--text-soft);font-size:0.8rem">
      Catalog and Gaps work together: correct associations in Catalog, then check Timeline coverage in Gaps.
    </p>
    <div class="d-flex flex-wrap gap-2">
      <a href="/queue" class="btn btn-primary btn-sm">
        Catalog
        <?php if (($readiness['pending'] ?? 0) > 0): ?>
        <span class="badge text-bg-light ms-1"><?php echo number_format($readiness['pending']); ?> pending</span>
        <?php endif; ?>
      </a>
      <a href="/show-audit" class="btn btn-outline-primary btn-sm">Gaps</a>
      <?php if (($readiness['approved'] ?? 0) > 0): ?>
      <a href="/execute" class="btn btn-outline-warning btn-sm">
        Execute
        <span class="badge text-bg-light ms-1"><?php echo number_format($readiness['approved']); ?> approved</span>
      </a>
      <?php endif; ?>
    </div>
  </div>
</div>
