<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Repositories\CaptionExtractJobRepository;
use MediaManager\Support\View;

/** @var list<array<string, mixed>> $recent */
/** @var array<string, mixed>|null $running */
/** @var CaptionExtractJobRepository $jobs */
/** @var array{count: int, duration_seconds: float} $missing */
/** @var array{count: int, duration_seconds: float} $knownCc */
/** @var array{count: int, duration_seconds: float} $unprobed */
$unprobed = $unprobed ?? ['count' => 0, 'duration_seconds' => 0.0];
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Caption Extract</h1>
    <p class="mb-0 path-text">
      Jobs are queued here; the <code>media-manager-caption-extract</code> systemd worker probes each file
      and writes an <code>.srt</code> sidecar when captions are available.
      Progress, duration-weighted ETA, and a detailed log are on each job page.
    </p>
  </div>
</div>

<div id="caption-running-banner">
<?php if ($running !== null): ?>
<div class="alert alert-info py-2 mb-0">
  Job <a href="/captions/<?php echo (int) $running['id']; ?>">#<?php echo (int) $running['id']; ?></a>
  is <strong>RUNNING</strong>
  (<span id="caption-running-progress"><?php echo (int) ($running['processed_files'] ?? 0); ?> /
  <?php echo (int) ($running['total_files'] ?? 0); ?></span>).
  Only one extract job runs at a time.
</div>
<?php endif; ?>
</div>

<div class="row g-4 mb-4">
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header">Start new job</div>
      <div class="card-body">
        <p class="path-text mb-3" style="font-size:0.82rem">
          Not probed yet (grey <strong>CC?</strong>):
          <strong><?php echo number_format($unprobed['count']); ?></strong> files<br>
          Missing SRT: <strong><?php echo number_format($missing['count']); ?></strong> files
          (~<?php echo number_format($missing['duration_seconds'] / 3600, 1); ?>h media).<br>
          Already flagged CC, missing SRT:
          <strong><?php echo number_format($knownCc['count']); ?></strong>
          (~<?php echo number_format($knownCc['duration_seconds'] / 3600, 1); ?>h).
        </p>
        <form method="post" action="/captions/start"
              onsubmit="return confirm('Start background caption job for this scope?');">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <div class="mb-3">
            <label class="form-label">Scope</label>
            <select name="scope" class="form-select">
              <option value="probe_only">Probe CC badges only (fast — no SRT extract)</option>
              <option value="missing_srt">All files missing SRT (probe + extract)</option>
              <option value="has_captions">Only files already marked has_captions</option>
            </select>
            <div class="form-text path-text">
              Start with <strong>Probe CC badges only</strong> to paint Catalog orange/grey CC marks without waiting on FFmpeg extract.
              Catalog <strong>Extract CC</strong> enqueues or prioritizes a selected-scope extract job.
            </div>
          </div>
          <button type="submit" class="btn btn-primary btn-sm" id="caption-start-btn"
                  <?php echo $running !== null ? 'disabled' : ''; ?>>
            Start job
          </button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header">Notes</div>
      <div class="card-body path-text" style="font-size:0.82rem">
        <ul class="mb-0 ps-3">
          <li>Worker: <code>systemctl status media-manager-caption-extract</code></li>
          <li>Logs: <code>journalctl -u media-manager-caption-extract -f</code> · <code>storage/logs/caption-extract-{id}.log</code></li>
          <li>Per-file timeout: <code>CAPTION_EXTRACT_TIMEOUT_SECONDS</code> (default 900)</li>
          <li>ETA uses processed media duration ÷ wall time (longer files weigh more)</li>
          <li>On a job page: select one/many upcoming clips → <strong>Move selected to top</strong></li>
          <li>While a job is active, Catalog <strong>Extract CC</strong> bumps those clips to the top</li>
          <li>Files with no subtitle stream are skipped (not counted as hard failures)</li>
          <li>Bulk one-time probe: <code>php scripts/probe_captions.php</code> (or <code>--run</code>)</li>
          <li>Local without systemd: set <code>WORKER_MODE=spawn</code> in <code>.env</code></li>
        </ul>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">Recent jobs</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th>ID</th>
          <th>Status</th>
          <th>Scope</th>
          <th>Progress</th>
          <th>OK / Fail / Skip</th>
          <th>Created</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($recent === []): ?>
        <tr><td colspan="7" class="path-text">No jobs yet.</td></tr>
        <?php else: ?>
        <?php foreach ($recent as $j):
          $jStatus = (string) ($j['status'] ?? '');
          $jOrphan = $jStatus === 'RUNNING' && !$jobs->isWorkerAlive((int) $j['id']);
          $jCanDelete = $jStatus !== 'RUNNING' || $jOrphan;
        ?>
        <tr data-caption-job="<?php echo (int) $j['id']; ?>">
          <td>#<?php echo (int) $j['id']; ?></td>
          <td class="caption-job-status">
            <span class="caption-status-badge"><?php echo View::statusBadge($jStatus); ?></span>
            <span class="caption-orphan-badge"><?php if ($jOrphan): ?>
            <span class="badge bg-warning text-dark">hung</span>
            <?php endif; ?></span>
          </td>
          <td class="path-text"><?php echo View::e((string) ($j['scope'] ?? '')); ?></td>
          <td class="caption-job-progress">
            <?php echo (int) ($j['processed_files'] ?? 0); ?> /
            <?php echo (int) ($j['total_files'] ?? 0); ?>
          </td>
          <td class="path-text caption-job-counts">
            <?php echo (int) ($j['ok_count'] ?? 0); ?> /
            <?php echo (int) ($j['fail_count'] ?? 0); ?> /
            <?php echo (int) ($j['skip_count'] ?? 0); ?>
          </td>
          <td class="path-text"><?php echo View::e(substr((string) ($j['created_at'] ?? ''), 0, 19)); ?></td>
          <td class="text-end text-nowrap">
            <a href="/captions/<?php echo (int) $j['id']; ?>" class="btn btn-outline-secondary btn-xs">Open</a>
            <?php if ($jCanDelete): ?>
            <form method="post" action="/captions/delete" class="d-inline"
                  onsubmit="return confirm('<?php echo $jOrphan
                      ? 'Force-delete hung caption job #' . (int) $j['id'] . '?'
                      : 'Delete caption job #' . (int) $j['id'] . '?'; ?>');">
              <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
              <input type="hidden" name="id" value="<?php echo (int) $j['id']; ?>">
              <?php if ($jOrphan): ?>
              <input type="hidden" name="force" value="1">
              <button type="submit" class="btn btn-danger btn-xs">Force del</button>
              <?php else: ?>
              <button type="submit" class="btn btn-outline-danger btn-xs">Delete</button>
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

<script src="/js/live-poll.js"></script>
<script>
(function () {
  var lastIds = null;
  LivePoll.start({
    url: '/captions/list-status',
    intervalMs: 5000,
    onData: function (data) {
      var ids = (data.ids || []).join(',');
      if (lastIds !== null && lastIds !== ids) {
        window.location.reload();
        return;
      }
      lastIds = ids;

      var banner = document.getElementById('caption-running-banner');
      var startBtn = document.getElementById('caption-start-btn');
      if (data.running) {
        if (banner) {
          banner.innerHTML = '<div class="alert alert-info py-2 mb-0">Job <a href="/captions/'
            + data.running.id + '">#' + data.running.id + '</a> is <strong>RUNNING</strong> ('
            + '<span id="caption-running-progress">' + data.running.processed_files + ' / '
            + data.running.total_files + '</span>). Only one extract job runs at a time.</div>';
        }
        if (startBtn) startBtn.disabled = true;
      } else {
        if (banner) banner.innerHTML = '';
        if (startBtn) startBtn.disabled = false;
      }

      (data.jobs || []).forEach(function (job) {
        var row = document.querySelector('[data-caption-job="' + job.id + '"]');
        if (!row) return;
        var badge = row.querySelector('.caption-status-badge');
        if (badge) badge.innerHTML = job.status_badge_html || '';
        var orphan = row.querySelector('.caption-orphan-badge');
        if (orphan) {
          orphan.innerHTML = job.worker_orphan
            ? '<span class="badge bg-warning text-dark">hung</span>' : '';
        }
        var prog = row.querySelector('.caption-job-progress');
        if (prog) prog.textContent = job.processed_files + ' / ' + job.total_files;
        var counts = row.querySelector('.caption-job-counts');
        if (counts) counts.textContent = job.ok_count + ' / ' + job.fail_count + ' / ' + job.skip_count;
      });
    },
    shouldStop: function (data) { return data.poll === false; }
  });
})();
</script>
