<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var array<string, mixed> $item */
/** @var list<array<string, mixed>> $segments */
/** @var list<array<string, mixed>> $shows */

$duration = (float) ($item['duration_seconds'] ?? 0);
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Split Job #<?php echo (int) $item['id']; ?></h1>
    <p class="mb-0 path-text"><?php echo View::e($item['original_path']); ?></p>
  </div>
  <a href="/split" class="btn btn-outline-secondary btn-sm">Back to Split Queue</a>
</div>

<div class="row g-4 mb-4">
  <div class="col-md-4">
    <div class="card">
      <div class="card-body">
        <div class="mb-2"><span style="color:var(--text-soft)">Duration</span>
          <strong class="ms-2"><?php echo View::duration($item['duration_seconds'] ?? null); ?></strong></div>
        <div class="mb-2"><span style="color:var(--text-soft)">Show</span>
          <strong class="ms-2"><?php echo View::e($item['show_abbr'] ?? '—'); ?></strong></div>
        <div class="mb-2"><span style="color:var(--text-soft)">Status</span>
          <span class="ms-2"><?php echo View::statusBadge((string) $item['status']); ?></span></div>
        <?php if (!empty($item['split_notes'])): ?>
        <div class="path-text mt-2"><?php echo View::e($item['split_notes']); ?></div>
        <?php endif; ?>
        <?php if (!empty($item['proposed_filename'])): ?>
        <hr style="border-color:var(--border-color)">
        <div class="path-text">Proposed target</div>
        <div class="path-filename proposed"><?php echo View::e($item['proposed_filename']); ?></div>
        <div class="path-text proposed"><?php echo View::e($item['proposed_dir']); ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-md-8">
    <form method="post" action="/split/update" id="split-form">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>Segments</span>
          <button type="button" class="btn btn-outline-secondary btn-xs" id="add-segment">Add Segment</button>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table mb-0" id="segments-table">
              <thead>
                <tr>
                  <th style="width:100px">Start (s)</th>
                  <th style="width:100px">End (s)</th>
                  <th style="width:140px">Show</th>
                  <th>Label</th>
                  <th style="width:40px"></th>
                </tr>
              </thead>
              <tbody>
                <?php if ($segments === []): ?>
                <tr class="segment-row">
                  <td><input type="number" name="segment_start[]" class="form-control form-control-sm" min="0" step="0.1" value="0"></td>
                  <td><input type="number" name="segment_end[]" class="form-control form-control-sm" min="0" step="0.1"
                             value="<?php echo $duration > 0 ? View::e((string) $duration) : ''; ?>"></td>
                  <td>
                    <select name="segment_show_id[]" class="form-select form-select-sm">
                      <option value="">—</option>
                      <?php foreach ($shows as $show): ?>
                      <option value="<?php echo (int) $show['id']; ?>"
                        <?php echo (int) ($item['show_id'] ?? 0) === (int) $show['id'] ? 'selected' : ''; ?>>
                        <?php echo View::e($show['abbreviation']); ?>
                      </option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><input type="text" name="segment_label[]" class="form-control form-control-sm" placeholder="Segment label"></td>
                  <td><button type="button" class="btn btn-outline-danger btn-xs remove-segment">&times;</button></td>
                </tr>
                <?php else: ?>
                <?php foreach ($segments as $seg): ?>
                <tr class="segment-row">
                  <td><input type="number" name="segment_start[]" class="form-control form-control-sm" min="0" step="0.1"
                             value="<?php echo View::e((string) ($seg['start'] ?? 0)); ?>"></td>
                  <td><input type="number" name="segment_end[]" class="form-control form-control-sm" min="0" step="0.1"
                             value="<?php echo View::e((string) ($seg['end'] ?? 0)); ?>"></td>
                  <td>
                    <select name="segment_show_id[]" class="form-select form-select-sm">
                      <option value="">—</option>
                      <?php foreach ($shows as $show): ?>
                      <option value="<?php echo (int) $show['id']; ?>"
                        <?php echo (int) ($seg['show_id'] ?? 0) === (int) $show['id'] ? 'selected' : ''; ?>>
                        <?php echo View::e($show['abbreviation']); ?>
                      </option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><input type="text" name="segment_label[]" class="form-control form-control-sm"
                             value="<?php echo View::e($seg['label'] ?? ''); ?>"></td>
                  <td><button type="button" class="btn btn-outline-danger btn-xs remove-segment">&times;</button></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="card-footer path-text">
          Times in seconds from file start. Total duration: <?php echo View::duration($item['duration_seconds'] ?? null); ?>.
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Notes</label>
              <textarea name="notes" class="form-control" rows="2"><?php echo View::e($item['notes'] ?? ''); ?></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <?php foreach (['PENDING', 'IN_PROGRESS', 'DONE', 'FAILED'] as $st): ?>
                <option value="<?php echo $st; ?>" <?php echo ($item['status'] ?? '') === $st ? 'selected' : ''; ?>>
                  <?php echo $st; ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="card-footer d-flex justify-content-between">
          <button type="submit" class="btn btn-primary btn-sm">Save Split Job</button>
          <small class="path-text align-self-center">
            Created by <?php echo View::e($item['created_by_email'] ?? ''); ?>
          </small>
        </div>
      </div>
    </form>

    <form method="post" action="/split/delete"
          onsubmit="return confirm('Remove this split job from the queue?');">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
      <button type="submit" class="btn btn-outline-danger btn-sm">Delete Split Job</button>
    </form>
  </div>
</div>

<template id="segment-row-template">
  <tr class="segment-row">
    <td><input type="number" name="segment_start[]" class="form-control form-control-sm" min="0" step="0.1" value="0"></td>
    <td><input type="number" name="segment_end[]" class="form-control form-control-sm" min="0" step="0.1" value=""></td>
    <td>
      <select name="segment_show_id[]" class="form-select form-select-sm">
        <option value="">—</option>
        <?php foreach ($shows as $show): ?>
        <option value="<?php echo (int) $show['id']; ?>"><?php echo View::e($show['abbreviation']); ?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td><input type="text" name="segment_label[]" class="form-control form-control-sm" placeholder="Segment label"></td>
    <td><button type="button" class="btn btn-outline-danger btn-xs remove-segment">&times;</button></td>
  </tr>
</template>

<script>
(function () {
    var tbody = document.querySelector('#segments-table tbody');
    var tpl = document.getElementById('segment-row-template');

    document.getElementById('add-segment').addEventListener('click', function () {
        tbody.appendChild(tpl.content.cloneNode(true));
    });

    tbody.addEventListener('click', function (e) {
        if (!e.target.classList.contains('remove-segment')) return;
        var rows = tbody.querySelectorAll('.segment-row');
        if (rows.length <= 1) return;
        e.target.closest('tr').remove();
    });
})();
</script>
