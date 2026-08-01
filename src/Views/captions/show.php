<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var array<string, mixed> $job */
/** @var array<string, mixed> $eta */
/** @var string $logPath */
/** @var list<string> $logTail */
/** @var bool $refresh */
/** @var bool $canReorder */
/** @var bool $canDelete */
/** @var bool $canForceDelete */
/** @var bool $workerOrphan */
/** @var bool $workerAlive */
/** @var array<string, mixed> $timing */
/** @var list<array<string, mixed>> $priorityFiles */
/** @var list<array<string, mixed>> $upcoming */

$status = (string) ($job['status'] ?? '');
$canReorder = $canReorder ?? false;
$canDelete = $canDelete ?? true;
$canForceDelete = $canForceDelete ?? false;
$workerOrphan = $workerOrphan ?? false;
$workerAlive = $workerAlive ?? false;
$timing = $timing ?? [
    'started_label' => '—',
    'ended_label' => '—',
    'elapsed_label' => '—',
];
$priorityFiles = $priorityFiles ?? [];
$upcoming = $upcoming ?? [];
?>
<?php if ($refresh): ?>
<meta http-equiv="refresh" content="5">
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">
      Caption Extract #<?php echo (int) $job['id']; ?>
    </h1>
    <p class="mb-0 path-text">
      Scope <code><?php echo View::e((string) ($job['scope'] ?? '')); ?></code>
      · <?php echo View::statusBadge($status); ?>
      · by <?php echo View::e((string) ($job['created_by_email'] ?? '')); ?>
      <?php if ($refresh): ?>
      · auto-refresh 5s
      <?php endif; ?>
      <?php if ($workerOrphan): ?>
      · <span class="badge bg-warning text-dark">hung — worker not running</span>
      <?php endif; ?>
    </p>
  </div>
  <div class="d-flex gap-2">
    <a href="/captions" class="btn btn-outline-secondary btn-sm">All jobs</a>
    <?php if (in_array($status, ['PENDING', 'RUNNING'], true) && !$workerOrphan): ?>
    <form method="post" action="/captions/cancel"
          onsubmit="return confirm('Cancel after the current file finishes?');">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
      <button type="submit" class="btn btn-outline-danger btn-sm">Cancel</button>
    </form>
    <?php endif; ?>
    <?php if ($canForceDelete): ?>
    <form method="post" action="/captions/delete"
          onsubmit="return confirm('Worker is not running but job still shows <?php echo View::e($status); ?>. Force-delete job #<?php echo (int) $job['id']; ?>?');">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
      <input type="hidden" name="force" value="1">
      <button type="submit" class="btn btn-danger btn-sm">Force delete (hung)</button>
    </form>
    <?php elseif ($canDelete): ?>
    <form method="post" action="/captions/delete"
          onsubmit="return confirm('Delete caption job #<?php echo (int) $job['id']; ?>? This does not undo extracted SRTs.');">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
      <button type="submit" class="btn btn-outline-danger btn-sm">Delete job</button>
    </form>
    <?php elseif ($workerAlive): ?>
    <span class="align-self-center path-text" style="font-size:0.78rem">Worker still running — Cancel before delete</span>
    <?php endif; ?>
  </div>
</div>

<div class="row g-4 mb-4">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header">Timing</div>
      <div class="card-body py-3">
        <div class="row g-2" style="font-size:0.84rem">
          <div class="col-sm-4">
            <div class="path-text">Started</div>
            <strong><?php echo View::e((string) $timing['started_label']); ?></strong>
          </div>
          <div class="col-sm-4">
            <div class="path-text">Ended</div>
            <strong><?php echo View::e((string) $timing['ended_label']); ?></strong>
          </div>
          <div class="col-sm-4">
            <div class="path-text">Elapsed</div>
            <strong><?php echo View::e((string) $timing['elapsed_label']); ?></strong>
          </div>
        </div>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header">Progress</div>
      <div class="card-body">
        <div class="d-flex justify-content-between mb-1" style="font-size:0.82rem">
          <span>
            <?php echo (int) ($job['processed_files'] ?? 0); ?> /
            <?php echo (int) ($job['total_files'] ?? 0); ?> files
            (<?php echo View::e((string) ($eta['pct'] ?? 0)); ?>%)
          </span>
          <strong style="color:var(--accent)"><?php echo View::e((string) ($eta['eta_label'] ?? '')); ?></strong>
        </div>
        <div class="progress mb-3" style="height:10px;background:var(--panel-strong)">
          <div class="progress-bar" role="progressbar"
               style="width:<?php echo View::e((string) ($eta['pct'] ?? 0)); ?>%;background:var(--accent)"></div>
        </div>
        <div class="row g-2" style="font-size:0.82rem">
          <div class="col-sm-4">
            <div class="path-text">OK</div>
            <strong><?php echo number_format((int) ($job['ok_count'] ?? 0)); ?></strong>
          </div>
          <div class="col-sm-4">
            <div class="path-text">Failed</div>
            <strong><?php echo number_format((int) ($job['fail_count'] ?? 0)); ?></strong>
          </div>
          <div class="col-sm-4">
            <div class="path-text">Skipped (no CC)</div>
            <strong><?php echo number_format((int) ($job['skip_count'] ?? 0)); ?></strong>
          </div>
        </div>
        <hr style="border-color:var(--border-color)">
        <div style="font-size:0.82rem">
          <div class="path-text mb-1">Duration-weighted ETA</div>
          <div>
            Remaining media ~
            <strong><?php echo number_format(((float) ($eta['remaining_duration'] ?? 0)) / 3600, 1); ?>h</strong>
            · Rate: <?php echo View::e((string) ($eta['rate_label'] ?? '—')); ?>
            · Method: <code><?php echo View::e((string) ($eta['method'] ?? '')); ?></code>
          </div>
          <div class="path-text mt-1" style="font-size:0.75rem">
            ETA scales with file duration — a 3h MXF counts ~3× a 1h file.
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Current file</div>
      <div class="card-body" style="font-size:0.84rem">
        <?php if (!empty($job['current_filename'])): ?>
        <div class="path-filename"><?php echo View::e((string) $job['current_filename']); ?></div>
        <div class="path-text">
          File #<?php echo (int) ($job['current_file_id'] ?? 0); ?>
          <?php if (!empty($job['current_duration_seconds'])): ?>
          · duration <?php echo View::duration((float) $job['current_duration_seconds']); ?>
          <?php endif; ?>
          <?php if (!empty($eta['hang_seconds'])): ?>
          · running <?php echo View::duration((float) $eta['hang_seconds']); ?>
          <?php endif; ?>
        </div>
        <?php if (!empty($eta['hang_warning'])): ?>
        <div class="alert alert-warning py-2 mt-3 mb-0" style="font-size:0.82rem">
          This file has been processing for over 20 minutes — check the log for a hang or timeout
          (<code>CAPTION_EXTRACT_TIMEOUT_SECONDS</code>, default 900s).
        </div>
        <?php endif; ?>
        <?php else: ?>
        <span class="path-text">No file in progress.</span>
        <?php endif; ?>
        <?php if (!empty($job['last_error'])): ?>
        <div class="alert alert-danger py-2 mt-3 mb-0" style="font-size:0.78rem;white-space:pre-wrap">
          Last error: <?php echo View::e((string) $job['last_error']); ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($job['error_message'])): ?>
        <div class="alert alert-danger py-2 mt-3 mb-0" style="font-size:0.78rem;white-space:pre-wrap">
          Job error: <?php echo View::e((string) $job['error_message']); ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Detailed log</span>
        <span class="path-text" style="font-size:0.72rem"><?php echo View::e(basename($logPath)); ?></span>
      </div>
      <div class="card-body p-0">
        <pre class="mb-0 p-3 path-text"
             style="font-size:0.68rem;max-height:420px;overflow:auto;white-space:pre-wrap;background:var(--form-bg)"><?php
if ($logTail === []) {
    echo 'Log empty or not created yet: ' . View::e($logPath);
} else {
    foreach ($logTail as $line) {
        echo View::e($line) . "\n";
    }
}
?></pre>
      </div>
      <div class="card-footer path-text" style="font-size:0.72rem">
        Full path: <code><?php echo View::e($logPath); ?></code>
        · START / OK / SKIP / FAIL / EXCEPTION lines include path, duration, FFmpeg tail, and stack traces.
      </div>
    </div>
  </div>
</div>

<?php if ($canReorder): ?>
<form method="post" action="/captions/prioritize" id="caption-priority-form" class="mt-4">
  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
  <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">

  <div class="card mb-3">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <span>Extract cue — select clips &amp; move to top</span>
      <button type="submit" class="btn btn-primary btn-sm"
              onclick="return captionPriorityConfirm();">Move selected to top</button>
    </div>
    <div class="card-body py-2">
      <p class="path-text mb-2" style="font-size:0.78rem">
        Priority clips run next (after the current file). You can also select files in Catalog and click
        <strong>Extract CC</strong> — they jump to the top of this job.
      </p>

      <?php if ($priorityFiles !== []): ?>
      <div class="mb-3">
        <div class="path-text mb-1" style="font-size:0.75rem">Already prioritized (next up)</div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0" style="font-size:0.8rem">
            <thead>
              <tr>
                <th style="width:2rem">#</th>
                <th>File</th>
                <th>Duration</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($priorityFiles as $i => $pf): ?>
              <tr>
                <td class="path-text"><?php echo (int) $i + 1; ?></td>
                <td>
                  <div class="path-filename"><?php echo View::e((string) ($pf['original_filename'] ?? '')); ?></div>
                  <div class="path-text" style="font-size:0.72rem">#<?php echo (int) $pf['id']; ?></div>
                </td>
                <td class="path-text">
                  <?php echo !empty($pf['duration_seconds'])
                      ? View::duration((float) $pf['duration_seconds'])
                      : '—'; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <div class="d-flex justify-content-between align-items-center mb-1">
        <div class="path-text" style="font-size:0.75rem">
          Upcoming candidates (select one or many)
        </div>
        <div class="form-check mb-0">
          <input class="form-check-input" type="checkbox" id="caption-select-all-upcoming">
          <label class="form-check-label path-text" for="caption-select-all-upcoming" style="font-size:0.75rem">
            Select all shown
          </label>
        </div>
      </div>
      <?php if ($upcoming === []): ?>
      <p class="path-text mb-0" style="font-size:0.82rem">
        No upcoming candidates in this page window. Use Catalog → Extract CC to bump specific clips.
      </p>
      <?php else: ?>
      <div class="table-responsive" style="max-height:360px;overflow:auto">
        <table class="table table-sm table-hover align-middle mb-0" style="font-size:0.8rem">
          <thead>
            <tr>
              <th style="width:2rem"></th>
              <th>File</th>
              <th>Duration</th>
              <th>CC</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($upcoming as $uf): ?>
            <tr>
              <td>
                <input class="form-check-input caption-upcoming-cb" type="checkbox"
                       name="ids[]" value="<?php echo (int) $uf['id']; ?>">
              </td>
              <td>
                <div class="path-filename"><?php echo View::e((string) ($uf['original_filename'] ?? '')); ?></div>
                <div class="path-text" style="font-size:0.72rem">#<?php echo (int) $uf['id']; ?></div>
              </td>
              <td class="path-text">
                <?php echo !empty($uf['duration_seconds'])
                    ? View::duration((float) $uf['duration_seconds'])
                    : '—'; ?>
              </td>
              <td><?php echo !empty($uf['has_captions']) ? 'yes' : '—'; ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</form>
<script>
(function () {
  var all = document.getElementById('caption-select-all-upcoming');
  if (all) {
    all.addEventListener('change', function () {
      document.querySelectorAll('.caption-upcoming-cb').forEach(function (cb) {
        cb.checked = all.checked;
      });
    });
  }
  window.captionPriorityConfirm = function () {
    var n = document.querySelectorAll('.caption-upcoming-cb:checked').length;
    if (n < 1) {
      alert('Select at least one clip.');
      return false;
    }
    return confirm('Move ' + n + ' clip(s) to the top of this extract cue?');
  };
})();
</script>
<?php endif; ?>
