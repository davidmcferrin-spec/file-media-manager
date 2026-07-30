<?php

declare(strict_types=1);

use MediaManager\Support\View;
use MediaManager\Support\WorkflowSteps;

/** @var string $workflowStepId */
$step = WorkflowSteps::byId($workflowStepId ?? '');
if ($step === null) {
    return;
}

$prev = $step['prev'] !== null ? WorkflowSteps::byId((string) $step['prev']) : null;
$next = $step['next'] !== null ? WorkflowSteps::byId((string) $step['next']) : null;
?>
<div class="card mb-4" style="border-color:var(--border-color);background:var(--hover-bg)">
  <div class="card-body py-3 px-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
      <div style="min-width:0;flex:1">
        <div class="path-text mb-1" style="font-size:0.72rem;letter-spacing:0.04em;text-transform:uppercase">
          <?php echo View::e($step['phase_label']); ?>
          · Step <?php echo (int) $step['number']; ?> of 6
        </div>
        <div class="mb-1" style="font-weight:600"><?php echo View::e($step['title']); ?></div>
        <p class="mb-1" style="color:var(--text-soft);font-size:0.8rem;max-width:52rem">
          <?php echo View::e($step['purpose']); ?>
        </p>
        <p class="mb-0 path-text" style="font-size:0.72rem">
          Done when: <?php echo View::e($step['done_when']); ?>
        </p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <?php if ($prev !== null && (!($prev['admin_only'] ?? false) || \MediaManager\Auth\Auth::isAdmin())): ?>
        <a href="<?php echo View::e($prev['href']); ?>" class="btn btn-outline-secondary btn-sm">
          &larr; <?php echo View::e($prev['label']); ?>
        </a>
        <?php endif; ?>
        <?php if ($next !== null && (!($next['admin_only'] ?? false) || \MediaManager\Auth\Auth::isAdmin())): ?>
        <a href="<?php echo View::e($next['href']); ?>" class="btn btn-outline-primary btn-sm">
          <?php echo View::e($next['label']); ?> &rarr;
        </a>
        <?php endif; ?>
        <?php if (($step['id'] ?? '') === 'gaps'): ?>
        <a href="/queue" class="btn btn-outline-secondary btn-sm">Back to Catalog</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
