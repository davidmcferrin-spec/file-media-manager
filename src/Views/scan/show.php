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
/** @var int $reclassifiableCount */
/** @var bool $canStop */
/** @var bool $canDelete */
/** @var bool $canForceDelete */
/** @var bool $canResume */
/** @var bool $canReclassify */
/** @var bool $canRescan */
/** @var bool $workerAlive */
/** @var bool $workerOrphan */
/** @var array<string, mixed> $timing */
$status = (string) ($job['status'] ?? '');
$reclassifiableCount = $reclassifiableCount ?? 0;
$canReclassify = $canReclassify ?? false;
$canRescan = $canRescan ?? false;
$canForceDelete = $canForceDelete ?? false;
$workerAlive = $workerAlive ?? false;
$workerOrphan = $workerOrphan ?? false;
$timing = $timing ?? [
    'started_label' => '—',
    'ended_label' => '—',
    'elapsed_label' => '—',
    'eta_label' => '—',
    'rate_label' => '—',
];
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
          onsubmit="return confirm('Stop this scan? Continuity requests abort immediately. Files already queued stay in the review queue.');">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
      <button type="submit" class="btn btn-outline-danger btn-sm">
        <?php echo !empty($job['cancel_requested']) ? 'Stopping…' : 'Stop Scan'; ?>
      </button>
    </form>
    <form method="post" action="/scan/cancel" class="d-inline"
          onsubmit="return confirm('Force-stop scan #<?php echo (int) $job['id']; ?>?\n\nSends SIGTERM to the worker process. Use if Stop is stuck. Partial progress is paused when possible.');">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
      <input type="hidden" name="force" value="1">
      <button type="submit" class="btn btn-danger btn-sm">Force stop</button>
    </form>
    <?php endif; ?>
    <?php if ($canResume): ?>
    <form method="post" action="/scan/resume" class="d-inline">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
      <button type="submit" class="btn btn-primary btn-sm">Resume Scan</button>
    </form>
    <?php endif; ?>
    <?php if ($canForceDelete): ?>
    <form method="post" action="/scan/delete" class="d-inline"
          onsubmit="return confirm('Worker is not running but job still shows <?php echo View::e($status); ?>. Force-delete scan #<?php echo (int) $job['id']; ?> and remove <?php echo number_format($totalQueued); ?> queued file(s)?');">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
      <input type="hidden" name="force" value="1">
      <button type="submit" class="btn btn-danger btn-sm">Force delete (hung)</button>
    </form>
    <?php elseif ($canDelete): ?>
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
    <?php elseif ($workerAlive): ?>
    <span class="align-self-center" style="font-size:0.78rem;color:var(--text-soft)">
      Worker still running — Stop before delete
    </span>
    <?php endif; ?>
    <a href="/scan" class="btn btn-outline-secondary btn-sm">All Scans</a>
    <?php if (Auth::isAdmin() && in_array($status, ['COMPLETED', 'CANCELLED', 'PAUSED', 'FAILED'], true) && $totalQueued > 0): ?>
    <form method="post" action="/scan/apply-map" class="d-inline"
          onsubmit="return confirm('Apply legacy rename map to this scan job? Matches map rows and reconciles confidence.');">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
      <button type="submit" class="btn btn-outline-warning btn-sm">Apply Legacy Map</button>
    </form>
    <?php endif; ?>
    <?php if ($canRescan): ?>
    <form method="post" action="/scan/rescan" class="d-inline"
          onsubmit="return confirm('Full rescan job #<?php echo (int) $job['id']; ?>?\n\n• Re-walks the same source/path\n• Reclassifies pending/flagged/rejected files\n• Queues newly found files\n• Leaves approved/executed files unchanged');">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
      <button type="submit" class="btn btn-primary btn-sm">Rescan</button>
    </form>
    <?php endif; ?>
    <?php if ($canReclassify): ?>
    <form method="post" action="/scan/reclassify" class="d-inline"
          onsubmit="return confirm('Re-run the classifier on <?php echo number_format($reclassifiableCount); ?> pending/flagged/rejected file(s)? Approved and executed files are left unchanged. Legacy map matches will be cleared until you re-apply the map.');">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
      <button type="submit" class="btn btn-outline-primary btn-sm">Reclassify Files</button>
    </form>
    <?php endif; ?>
    <?php if ($totalQueued > 0): ?>
    <a href="/scan/<?php echo (int) $job['id']; ?>/export" class="btn btn-outline-secondary btn-sm">
      Export XLSX
    </a>
    <?php endif; ?>
    <a href="/queue?scan_job_id=<?php echo (int) $job['id']; ?>" class="btn btn-outline-secondary btn-sm">Review Queue</a>
  </div>
</div>

<?php
$total  = (int) ($job['total_files'] ?? 0);
$done   = (int) ($job['processed_files'] ?? 0);
$pct    = $total > 0 ? round(($done / $total) * 100) : 0;
?>

<?php
$scanLive = $status === 'RUNNING' || ($status === 'PENDING' && empty($job['cancel_requested']));
?>

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label">Status</div>
        <div id="scan-status-value" class="stat-value" style="font-size:1.4rem">
          <?php
          if ($status === 'RUNNING' && !empty($job['cancel_requested'])) {
              echo 'STOPPING';
          } else {
              echo View::e($status);
          }
          ?>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label">Queued Files</div>
        <div id="scan-queued-value" class="stat-value"><?php echo number_format($totalQueued); ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-2">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label">High</div>
        <div id="scan-conf-high" class="stat-value" style="color:#22c55e"><?php echo $confidence['HIGH']; ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-2">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label">Medium</div>
        <div id="scan-conf-medium" class="stat-value" style="color:#facc15"><?php echo $confidence['MEDIUM']; ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-1">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label">Low</div>
        <div id="scan-conf-low" class="stat-value" style="color:#f87171;font-size:1.4rem"><?php echo $confidence['LOW']; ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-1">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label">Uneval</div>
        <div id="scan-conf-uneval" class="stat-value" style="color:var(--text-soft);font-size:1.4rem"><?php echo $confidence['UNEVALUATED'] ?? 0; ?></div>
      </div>
    </div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header">Timing</div>
  <div class="card-body py-3">
    <div class="row g-2" style="font-size:0.84rem">
      <div class="col-sm-3">
        <div class="path-text">Started</div>
        <strong id="scan-started"><?php echo View::e((string) $timing['started_label']); ?></strong>
      </div>
      <div class="col-sm-3">
        <div class="path-text">Ended</div>
        <strong id="scan-ended"><?php echo View::e((string) $timing['ended_label']); ?></strong>
      </div>
      <div class="col-sm-3">
        <div class="path-text">Elapsed</div>
        <strong id="scan-elapsed"><?php echo View::e((string) $timing['elapsed_label']); ?></strong>
      </div>
      <div class="col-sm-3">
        <div class="path-text" id="scan-eta-heading"><?php echo in_array($status, ['RUNNING', 'PENDING'], true) ? 'ETA' : 'Rate'; ?></div>
        <strong id="scan-eta-value" style="color:var(--accent)">
          <?php echo View::e((string) (in_array($status, ['RUNNING', 'PENDING'], true) ? $timing['eta_label'] : $timing['rate_label'])); ?>
        </strong>
      </div>
    </div>
    <div id="scan-orphan-alert">
    <?php if ($workerOrphan): ?>
    <div class="alert alert-warning py-2 mt-3 mb-0" style="font-size:0.8rem">
      Job shows <strong><?php echo View::e($status); ?></strong> but the worker process is not running (orphaned).
      Use <strong>Force delete (hung)</strong> if you want to clear it.
    </div>
    <?php endif; ?>
    </div>
  </div>
</div>

<div id="scan-progress-wrap">
<?php if ($scanLive): ?>
<div class="card mb-4">
  <div class="card-body py-3">
    <div class="d-flex justify-content-between mb-1">
      <span id="scan-progress-status" style="font-size:0.82rem;color:var(--text-soft)">
        <?php echo $status === 'PENDING' ? 'Waiting to start…' : 'Processing…'; ?>
        · ETA <?php echo View::e((string) $timing['eta_label']); ?>
        · <?php echo View::e((string) $timing['rate_label']); ?>
      </span>
      <span id="scan-progress-counts" style="font-size:0.82rem"><?php echo $done; ?> / <?php echo $total; ?> (<?php echo $pct; ?>%)</span>
    </div>
    <div class="progress" style="height:8px">
      <div id="scan-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:<?php echo $pct; ?>%"></div>
    </div>
    <p id="scan-progress-hint" class="mb-0 mt-2" style="font-size:0.78rem;color:var(--text-soft)">
      <?php if (!empty($job['cancel_requested'])): ?>
      Stopping… aborting Continuity / waiting for the worker to park the job. Use <strong>Force stop</strong> if this hangs.
      <?php else: ?>
      Elapsed <?php echo View::e((string) $timing['elapsed_label']); ?> · live update 5s
      <?php endif; ?>
    </p>
  </div>
</div>
<?php elseif ($status === 'PAUSED'): ?>
<div class="alert alert-info mb-4" style="font-size:0.84rem;">
  Scan paused<?php echo $done > 0 ? ' after processing ' . number_format($done) . ' of ' . number_format($total) . ' file(s)' : ''; ?>.
  Click <strong>Resume Scan</strong> (the scan worker picks it up), or run
  <code>php scripts/scan.php --job-id=<?php echo (int) $job['id']; ?></code>.
  Already-queued files are kept; duplicates are skipped on resume.
</div>
<?php elseif ($status === 'CANCELLED'): ?>
<div class="alert alert-warning mb-4" style="font-size:0.84rem;">
  Scan was stopped<?php echo $done > 0 ? ' after processing ' . number_format($done) . ' file(s)' : ''; ?>.
  Queued results remain in the review queue until you delete this scan job.
</div>
<?php endif; ?>
</div>

<div id="scan-error-wrap">
<?php if ($status === 'FAILED' && !empty($job['error_message'])): ?>
<div class="alert alert-danger mb-4"><?php echo View::e($job['error_message']); ?></div>
<?php endif; ?>
</div>

<div class="card">
  <div class="card-header">Sample Results (latest 50)</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Original</th>
          <th>Proposed</th>
          <th>Conf</th>
        </tr>
      </thead>
      <tbody id="scan-sample-tbody">
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
            <?php
            $fc = (string) ($file['confidence'] ?? 'UNEVALUATED');
            $fcLabel = $fc === 'UNEVALUATED' ? 'Unevaluated' : $fc;
            $parsedDt = View::formatParsedDateTime($file['file_date'] ?? null, $file['file_time'] ?? null);
            ?>
            <span class="badge badge-confidence-<?php echo View::e($fc); ?>">
              <?php echo View::e($fcLabel); ?>
            </span>
            <?php if ($parsedDt !== ''): ?>
            <div class="path-text mt-1" style="font-size:0.72rem"><?php echo View::e($parsedDt); ?></div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($scanLive): ?>
<script src="/js/live-poll.js"></script>
<script>
(function () {
  var jobId = <?php echo (int) $job['id']; ?>;
  var esc = LivePoll.escapeHtml;
  var fmt = function (n) { return Number(n || 0).toLocaleString(); };

  function renderSample(sample, status) {
    if (!sample || !sample.length) {
      return '<tr><td colspan="3" class="text-center py-4" style="color:var(--text-soft)">'
        + (status === 'RUNNING' ? 'Scan in progress…' : 'No files queued.')
        + '</td></tr>';
    }
    return sample.map(function (f) {
      var conf = esc(f.confidence || 'UNEVALUATED');
      var label = esc(f.confidence_label || conf);
      var html = '<tr><td><div class="path-filename">' + esc(f.original_filename) + '</div>'
        + '<div class="path-text">' + esc(f.original_dir) + '</div></td><td>';
      if (f.proposed_filename) {
        html += '<div class="path-filename proposed">' + esc(f.proposed_filename) + '</div>'
          + '<div class="path-text proposed">' + esc(f.proposed_dir || '') + '</div>';
      } else {
        html += '<span style="color:var(--text-soft)">—</span>';
      }
      html += '</td><td><span class="badge badge-confidence-' + conf + '">' + label + '</span>';
      if (f.parsed_dt) {
        html += '<div class="path-text mt-1" style="font-size:0.72rem">' + esc(f.parsed_dt) + '</div>';
      }
      html += '</td></tr>';
      return html;
    }).join('');
  }

  LivePoll.start({
    url: '/scan/' + jobId + '/status',
    intervalMs: 5000,
    onData: function (data) {
      var timing = data.timing || {};
      var conf = data.confidence || {};
      var live = data.status === 'RUNNING' || (data.status === 'PENDING' && !data.cancel_requested);

      var statusLabel = (data.status === 'RUNNING' && data.cancel_requested)
        ? 'STOPPING'
        : (data.status || '');
      LivePoll.setText('scan-status-value', statusLabel);
      LivePoll.setText('scan-queued-value', fmt(data.total_queued));
      LivePoll.setText('scan-conf-high', String(conf.HIGH || 0));
      LivePoll.setText('scan-conf-medium', String(conf.MEDIUM || 0));
      LivePoll.setText('scan-conf-low', String(conf.LOW || 0));
      LivePoll.setText('scan-conf-uneval', String(conf.UNEVALUATED || 0));
      LivePoll.setText('scan-started', timing.started_label || '—');
      LivePoll.setText('scan-ended', timing.ended_label || '—');
      LivePoll.setText('scan-elapsed', timing.elapsed_label || '—');
      LivePoll.setText('scan-eta-heading', live ? 'ETA' : 'Rate');
      LivePoll.setText('scan-eta-value', live ? (timing.eta_label || '—') : (timing.rate_label || '—'));

      if (data.worker_orphan) {
        LivePoll.setHtml(
          'scan-orphan-alert',
          '<div class="alert alert-warning py-2 mt-3 mb-0" style="font-size:0.8rem">'
            + 'Job shows <strong>' + esc(data.status) + '</strong> but the worker process is not running (orphaned). '
            + 'Use <strong>Force delete (hung)</strong> if you want to clear it.</div>'
        );
      } else {
        LivePoll.setHtml('scan-orphan-alert', '');
      }

      if (live) {
        var label = data.cancel_requested
          ? ('Stopping… · ' + (timing.elapsed_label || '—'))
          : ((data.status === 'PENDING' ? 'Waiting to start…' : 'Processing…')
            + ' · ETA ' + (timing.eta_label || '—')
            + ' · ' + (timing.rate_label || '—'));
        LivePoll.setText('scan-progress-status', label);
        LivePoll.setText(
          'scan-progress-counts',
          data.processed_files + ' / ' + data.total_files + ' (' + data.pct + '%)'
        );
        LivePoll.setWidth('scan-progress-bar', data.pct);
        LivePoll.setText(
          'scan-progress-hint',
          data.cancel_requested
            ? 'Stopping… aborting Continuity / waiting for the worker to park the job. Use Force stop if this hangs.'
            : ('Elapsed ' + (timing.elapsed_label || '—') + ' · live update 5s')
        );
      }

      if (data.error_message && data.status === 'FAILED') {
        LivePoll.setHtml(
          'scan-error-wrap',
          '<div class="alert alert-danger mb-4">' + esc(data.error_message) + '</div>'
        );
      }

      LivePoll.setHtml('scan-sample-tbody', renderSample(data.sample || [], data.status));
    },
    shouldStop: function (data) {
      return data.poll === false;
    },
    onStop: function () {
      setTimeout(function () { window.location.reload(); }, 400);
    }
  });
})();
</script>
<?php endif; ?>
