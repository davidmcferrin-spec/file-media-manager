<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var list<array<string, mixed>> $approvedFiles */
/** @var list<array<string, mixed>> $executedFiles */
/** @var int $approvedCount */

$workflowStepId = 'execute';
require dirname(__DIR__) . '/partials/workflow_step.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Execute</h1>
    <p class="mb-0" style="color:var(--text-soft);font-size:0.8rem;">
      Move and rename approved files on disk. Every operation is audit-logged before and after.
    </p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="/queue?status=APPROVED" class="btn btn-outline-secondary btn-sm">View Approved Catalog</a>
    <a href="/rollback" class="btn btn-link btn-sm path-text">Need to undo? Rollback</a>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Approved (<span id="execute-approved-count"><?php echo $approvedCount; ?></span>)</span>
        <span id="execute-all-wrap">
        <?php if ($approvedCount > 0): ?>
        <form method="post" action="/execute" class="d-inline" id="execute-all-form"
              onsubmit="return confirm('Execute ALL ' + document.getElementById('execute-approved-count').textContent + ' approved files on disk?');">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <button type="submit" class="btn btn-danger btn-sm">Execute All Approved</button>
        </form>
        <?php endif; ?>
        </span>
      </div>
      <?php if ($approvedFiles === []): ?>
      <div class="card-body text-center py-4" style="color:var(--text-soft)">
        No approved files waiting for execution.
      </div>
      <?php else: ?>
      <form method="post" action="/execute" id="execute-selected-form"
            onsubmit="return confirm('Execute selected files on disk?');">
        <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th style="width:32px"></th>
                <th>Original</th>
                <th>Target</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($approvedFiles as $file): ?>
              <tr>
                <td><input type="checkbox" name="ids[]" value="<?php echo (int) $file['id']; ?>"></td>
                <td>
                  <div class="path-filename"><?php echo View::e($file['original_filename']); ?></div>
                  <div class="path-text"><?php echo View::e($file['original_dir']); ?></div>
                </td>
                <td>
                  <div class="path-filename proposed"><?php echo View::e($file['proposed_filename']); ?></div>
                  <div class="path-text proposed">
                    <?php echo View::e(rtrim((string) $file['source_mount'], '/') . '/' . ($file['proposed_dir'] ?? '')); ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="card-footer">
          <button type="submit" class="btn btn-primary btn-sm">Execute Selected</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">Recently Executed — Rollback</div>
      <?php if ($executedFiles === []): ?>
      <div class="card-body text-center py-4" style="color:var(--text-soft)">Nothing executed yet.</div>
      <?php else: ?>
      <form method="post" action="/rollback" id="rollback-form"
            onsubmit="return confirm('Rollback selected files to original paths?');">
        <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0">
            <thead>
              <tr>
                <th style="width:32px"></th>
                <th>File</th>
                <th>When</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($executedFiles as $file): ?>
              <tr>
                <td><input type="checkbox" name="ids[]" value="<?php echo (int) $file['id']; ?>"></td>
                <td>
                  <div class="path-text" style="font-size:0.76rem"><?php echo View::e($file['executed_path'] ?? ''); ?></div>
                </td>
                <td class="path-text text-nowrap">
                  <?php echo View::e(substr((string) ($file['executed_at'] ?? ''), 0, 16)); ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="card-footer">
          <button type="submit" class="btn btn-outline-warning btn-sm">Rollback Selected</button>
        </div>
      </form>
      <?php endif; ?>
    </div>

    <div class="alert alert-warning mt-3 mb-0" style="font-size:0.82rem">
      Rollback moves files back to their original paths if the destination is still available
      and the original location is empty. Sidecars are restored when possible.
    </div>
  </div>
</div>

<script>
(function () {
  var knownIds = <?php echo json_encode(array_map(
      static fn (array $f): int => (int) ($f['id'] ?? 0),
      $approvedFiles
  ), JSON_THROW_ON_ERROR); ?>;
  var csrf = <?php echo json_encode(Session::csrfToken(), JSON_THROW_ON_ERROR); ?>;

  function selectionBusy() {
    return !!document.querySelector('#execute-selected-form input[type=checkbox]:checked, #rollback-form input[type=checkbox]:checked');
  }

  function poll() {
    if (document.hidden || selectionBusy()) return;
    fetch('/execute/list-status', {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      cache: 'no-store'
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data) return;
        var countEl = document.getElementById('execute-approved-count');
        if (countEl) countEl.textContent = String(data.approved_count || 0);
        var wrap = document.getElementById('execute-all-wrap');
        if (wrap && data.approved_count > 0 && !document.getElementById('execute-all-form')) {
          wrap.innerHTML = '<form method="post" action="/execute" class="d-inline" id="execute-all-form"'
            + ' onsubmit="return confirm(\'Execute ALL \' + document.getElementById(\'execute-approved-count\').textContent + \' approved files on disk?\');">'
            + '<input type="hidden" name="_csrf" value="' + csrf + '">'
            + '<button type="submit" class="btn btn-danger btn-sm">Execute All Approved</button></form>';
        }
        if (wrap && data.approved_count === 0) wrap.innerHTML = '';

        var nextIds = (data.approved_ids || []).slice().sort().join(',');
        var prevIds = knownIds.slice().sort().join(',');
        // New/removed approvals while page open — reload so the table stays accurate
        // (only when nothing is selected).
        if (prevIds !== nextIds && !selectionBusy()) {
          window.location.reload();
        }
      })
      .catch(function () {});
  }

  setInterval(poll, 8000);
})();
</script>
