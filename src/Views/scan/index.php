<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Repositories\ScanJobRepository;
use MediaManager\Services\ScanEtaEstimator;
use MediaManager\Support\View;

/** @var list<array<string, mixed>> $activeSources */
/** @var list<array<string, mixed>> $recentJobs */
/** @var ScanJobRepository $scanJobsRepo */
/** @var bool $ffprobeOk */
/** @var bool $timelineReady */
/** @var string $timelineReadyAt */
/** @var int $openEndedTotal */
/** @var int $timelineActive */

$workflowStepId = 'scan';
require dirname(__DIR__) . '/partials/workflow_step.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Scan</h1>
    <p class="mb-0" style="color:var(--text-soft);font-size:0.8rem;">
      Walk a NAS mount, classify files against naming policy, and queue them for Catalog review.
    </p>
  </div>
</div>

<?php if (!$timelineReady): ?>
<div class="alert alert-warning mb-4" style="font-size:0.84rem;">
  Timeline is not marked ready for Scan
  (<?php echo number_format($timelineActive); ?> active block<?php echo $timelineActive === 1 ? '' : 's'; ?><?php if ($openEndedTotal > 0): ?>,
  <?php echo number_format($openEndedTotal); ?> open-ended<?php endif; ?>).
  Vet schedule hygiene on
  <a href="/schedule#hygiene">Timeline</a>
  and click <strong>Mark Timeline ready for Scan</strong>, or acknowledge below to start anyway.
</div>
<?php else: ?>
<div class="alert alert-success mb-4" style="font-size:0.84rem;">
  Timeline marked ready for Scan<?php if ($timelineReadyAt !== ''): ?>
  (<?php echo View::e($timelineReadyAt); ?> ET)<?php endif; ?>.
  <?php if ($openEndedTotal > 0): ?>
  <?php echo number_format($openEndedTotal); ?> open-ended current block(s) kept — expected for shows still airing.
  <?php endif; ?>
  <a href="/schedule#hygiene" class="ms-1">Review hygiene</a>
</div>
<?php endif; ?>

<?php if (!$ffprobeOk): ?>
<div class="alert alert-warning mb-4" style="font-size:0.84rem;">
  FFprobe not found at configured path — scans will run without duration/codec metadata.
  Uncheck "Extract metadata" or install FFmpeg on the server.
</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">New Scan</div>
      <div class="card-body">
        <form method="post" action="/scan/start">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">

          <div class="mb-3">
            <label class="form-label">NAS Source</label>
            <select name="source_id" class="form-select" required>
              <?php foreach ($activeSources as $source): ?>
              <option value="<?php echo (int) $source['id']; ?>">
                <?php echo View::e($source['name']); ?> — <?php echo View::e($source['mount_path']); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Subpath (optional)</label>
            <input type="text" name="subpath" class="form-control" placeholder="cuomo">
            <div class="form-text" style="color:var(--text-soft)">
              Limit scan to a folder under the mount, e.g. <code>cuomo</code> for the Cuomo pilot.
            </div>
          </div>

          <div class="mb-3" style="font-size:0.82rem;color:var(--text-soft);line-height:1.45">
            <strong style="color:var(--text-main)">FFprobe + caption probe</strong> run for every file
            (duration, codecs, caption stream detection). Required — not optional.
          </div>

          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="use_dev_list"
                   id="use-dev-list">
            <label class="form-check-label" for="use-dev-list">
              Dev mode — scan from <code>example_file_trees</code> listing (no NAS mount)
            </label>
          </div>

          <?php if (!$timelineReady): ?>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="ack_timeline_not_ready"
                   id="ack-timeline-not-ready" required>
            <label class="form-check-label" for="ack-timeline-not-ready">
              Start anyway — I acknowledge Timeline hygiene is not marked ready
            </label>
          </div>
          <?php endif; ?>

          <button type="submit" class="btn btn-primary btn-sm">Start Scan</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">Recent Scan Jobs</div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>ID</th>
              <th>Source</th>
              <th>Subpath</th>
              <th>Status</th>
              <th>Progress</th>
              <th>Started</th>
              <th>Ended</th>
              <th>Elapsed / ETA</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($recentJobs === []): ?>
            <tr>
              <td colspan="9" class="text-center py-4" style="color:var(--text-soft)">No scans yet.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($recentJobs as $job):
            $total = (int) ($job['total_files'] ?? 0);
            $done  = (int) ($job['processed_files'] ?? 0);
            $pct   = $total > 0 ? round(($done / $total) * 100) : 0;
            $status = (string) $job['status'];
            $timingRow = ScanEtaEstimator::estimate($job);
            $orphanRow = $status === 'RUNNING' && !$scanJobsRepo->isWorkerAlive((int) $job['id']);
            $canStopRow = in_array($status, ['PENDING', 'RUNNING'], true);
            $canDeleteRow = $status !== 'RUNNING' || $orphanRow;
            ?>
            <tr data-scan-job="<?php echo (int) $job['id']; ?>">
              <td><a href="/scan/<?php echo (int) $job['id']; ?>">#<?php echo (int) $job['id']; ?></a></td>
              <td><?php echo View::e($job['source_name']); ?></td>
              <td class="path-text"><?php echo View::e($job['subpath'] ?: '—'); ?></td>
              <td class="scan-job-status">
                <?php
                $stopping = $status === 'RUNNING' && !empty($job['cancel_requested']);
                $badge  = match (true) {
                    $stopping               => 'warning',
                    $status === 'COMPLETED' => 'success',
                    $status === 'RUNNING'   => 'primary',
                    $status === 'PAUSED'    => 'info',
                    $status === 'FAILED'    => 'danger',
                    $status === 'CANCELLED' => 'warning',
                    default                 => 'secondary',
                };
                $statusLabel = $stopping ? 'STOPPING' : $status;
                ?>
                <span class="badge bg-<?php echo $badge; ?> scan-status-badge"><?php echo View::e($statusLabel); ?></span>
                <span class="scan-orphan-badge"><?php if ($orphanRow): ?>
                <span class="badge bg-warning text-dark" title="Worker process not running">hung</span>
                <?php endif; ?></span>
              </td>
              <td class="scan-job-progress" style="min-width:120px">
                <?php if ($total > 0): ?>
                <div class="progress" style="height:6px">
                  <div class="progress-bar scan-progress-bar" style="width:<?php echo $pct; ?>%"></div>
                </div>
                <span class="scan-progress-label" style="font-size:0.72rem;color:var(--text-soft)"><?php echo $done; ?> / <?php echo $total; ?></span>
                <?php else: ?>
                <span class="scan-progress-label">—</span>
                <?php endif; ?>
              </td>
              <td class="path-text scan-started"><?php echo View::e((string) $timingRow['started_label']); ?></td>
              <td class="path-text scan-ended"><?php echo View::e((string) $timingRow['ended_label']); ?></td>
              <td class="path-text scan-eta" style="font-size:0.78rem">
                <span class="scan-elapsed"><?php echo View::e((string) $timingRow['elapsed_label']); ?></span>
                <span class="scan-eta-line"><?php if (in_array($status, ['RUNNING', 'PENDING'], true)): ?>
                <br><span style="color:var(--accent)"><?php echo View::e((string) $timingRow['eta_label']); ?></span>
                <?php endif; ?></span>
              </td>
              <td class="text-end text-nowrap">
                <?php if ($status === 'PAUSED'): ?>
                <form method="post" action="/scan/resume" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
                  <button type="submit" class="btn btn-outline-primary btn-xs">Resume</button>
                </form>
                <?php endif; ?>
                <?php if ($canStopRow && !$orphanRow): ?>
                <form method="post" action="/scan/cancel" class="d-inline"
                      onsubmit="return confirm('Stop scan #<?php echo (int) $job['id']; ?>? Continuity aborts immediately.');">
                  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
                  <button type="submit" class="btn btn-outline-danger btn-xs">
                    <?php echo !empty($job['cancel_requested']) ? 'Stopping…' : 'Stop'; ?>
                  </button>
                </form>
                <form method="post" action="/scan/cancel" class="d-inline"
                      onsubmit="return confirm('Force-stop scan #<?php echo (int) $job['id']; ?> (SIGTERM worker)?');">
                  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
                  <input type="hidden" name="force" value="1">
                  <button type="submit" class="btn btn-danger btn-xs">Force</button>
                </form>
                <?php endif; ?>
                <?php if ($canDeleteRow): ?>
                <form method="post" action="/scan/delete" class="d-inline"
                      onsubmit="return confirm('<?php echo $orphanRow
                          ? 'Force-delete hung scan #' . (int) $job['id'] . ' and its queued files?'
                          : 'Delete scan #' . (int) $job['id'] . ' and its queued files?'; ?>');">
                  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
                  <?php if ($orphanRow): ?>
                  <input type="hidden" name="force" value="1">
                  <button type="submit" class="btn btn-danger btn-xs">Force del</button>
                  <?php else: ?>
                  <button type="submit" class="btn btn-outline-secondary btn-xs">Delete</button>
                  <?php endif; ?>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
$scanListLive = false;
foreach ($recentJobs as $j) {
    $st = (string) ($j['status'] ?? '');
    if ($st === 'RUNNING' || ($st === 'PENDING' && empty($j['cancel_requested']))) {
        $scanListLive = true;
        break;
    }
}
?>
<?php if ($scanListLive || $recentJobs !== []): ?>
<script src="/js/live-poll.js"></script>
<script>
(function () {
  var lastIds = null;
  var esc = LivePoll.escapeHtml;
  LivePoll.start({
    url: '/scan/list-status',
    intervalMs: 5000,
    onData: function (data) {
      var ids = (data.ids || []).join(',');
      if (lastIds !== null && lastIds !== ids) {
        window.location.reload();
        return;
      }
      lastIds = ids;
      (data.jobs || []).forEach(function (job) {
        var row = document.querySelector('[data-scan-job="' + job.id + '"]');
        if (!row) return;
        var badge = row.querySelector('.scan-status-badge');
        if (badge) {
          badge.className = 'badge bg-' + (job.status_badge || 'secondary') + ' scan-status-badge';
          badge.textContent = job.status_label || job.status || '';
        }
        var orphan = row.querySelector('.scan-orphan-badge');
        if (orphan) {
          orphan.innerHTML = job.worker_orphan
            ? '<span class="badge bg-warning text-dark" title="Worker process not running">hung</span>'
            : '';
        }
        var bar = row.querySelector('.scan-progress-bar');
        var label = row.querySelector('.scan-progress-label');
        if (job.total_files > 0) {
          if (bar) bar.style.width = (job.pct || 0) + '%';
          if (label) label.textContent = job.processed_files + ' / ' + job.total_files;
        } else if (label) {
          label.textContent = '—';
        }
        var t = job.timing || {};
        var started = row.querySelector('.scan-started');
        var ended = row.querySelector('.scan-ended');
        var elapsed = row.querySelector('.scan-elapsed');
        var etaLine = row.querySelector('.scan-eta-line');
        if (started) started.textContent = t.started_label || '—';
        if (ended) ended.textContent = t.ended_label || '—';
        if (elapsed) elapsed.textContent = t.elapsed_label || '—';
        if (etaLine) {
          var live = job.status === 'RUNNING' || job.status === 'PENDING';
          etaLine.innerHTML = live
            ? '<br><span style="color:var(--accent)">' + esc(t.eta_label || '—') + '</span>'
            : '';
        }
      });
    },
    shouldStop: function (data) { return data.poll === false; }
  });
})();
</script>
<?php endif; ?>
