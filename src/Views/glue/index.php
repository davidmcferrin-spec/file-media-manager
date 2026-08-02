<?php

declare(strict_types=1);

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var list<array{glue_group_key: string, part_count: int, files: list<array<string, mixed>>}> $groups */
/** @var int $totalParts */
/** @var list<array<string, mixed>> $jobItems */
/** @var array<string, int> $statusCounts */
/** @var array<string, array<string, mixed>> $activeByGroup */
/** @var string $statusFilter */
/** @var int $jobTotal */
/** @var int $page */
/** @var int $totalPages */

$statuses = ['', 'PENDING', 'RUNNING', 'READY_FOR_QC', 'APPROVED', 'DONE', 'FAILED', 'CANCELLED'];
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Glue Queue</h1>
    <p class="mb-0" style="color:var(--text-soft);font-size:0.8rem;">
      Multipart sets (<code>Name.ext</code> + <code>Name_1.ext</code> …): queue ffmpeg concat,
      QC the glued file, then delete source parts.
    </p>
  </div>
  <div class="d-flex gap-2">
    <a href="/queue?needs_glue=1&amp;status=ALL" class="btn btn-outline-secondary btn-sm">View in Catalog</a>
  </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-3" id="glue-status-pills">
  <?php foreach ($statuses as $st):
      $active = $statusFilter === $st;
      $label  = $st === '' ? 'All jobs' : $st;
      $cnt    = $st === '' ? array_sum($statusCounts) : ($statusCounts[$st] ?? 0);
      $pillKey = $st === '' ? 'ALL' : $st;
  ?>
  <a href="/glue<?php echo $st !== '' ? '?status=' . urlencode($st) : ''; ?>"
     class="btn btn-sm <?php echo $active ? 'btn-primary' : 'btn-outline-secondary'; ?>"
     data-glue-status-pill="<?php echo View::e($pillKey); ?>">
    <?php echo View::e($label); ?> <span class="opacity-75">(<span class="glue-status-cnt"><?php echo $cnt; ?></span>)</span>
  </a>
  <?php endforeach; ?>
</div>

<div class="card mb-4">
  <div class="card-header">Glue Jobs (<span id="glue-job-total"><?php echo number_format($jobTotal); ?></span>)</div>
  <?php if ($jobItems === []): ?>
  <div class="card-body path-text text-center py-4">
    No glue jobs yet. Queue a group below, then Run → QC → Delete sources.
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle" style="font-size:0.8rem">
      <thead>
        <tr>
          <th>Job</th>
          <th>Status</th>
          <th>Parts</th>
          <th>Output</th>
          <th>Created</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($jobItems as $job):
            $ids = json_decode((string) ($job['source_file_ids'] ?? '[]'), true);
            $partCount = is_array($ids) ? count($ids) : 0;
            $st = (string) ($job['status'] ?? '');
        ?>
        <tr data-glue-job="<?php echo (int) $job['id']; ?>">
          <td>
            <a href="/glue/<?php echo (int) $job['id']; ?>">#<?php echo (int) $job['id']; ?></a>
            <div class="path-text" style="font-size:0.7rem;max-width:16rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                 title="<?php echo View::e((string) $job['glue_group_key']); ?>">
              <?php echo View::e((string) $job['glue_group_key']); ?>
            </div>
          </td>
          <td class="glue-job-status">
            <?php
            $badge = match ($st) {
                'READY_FOR_QC' => 'bg-warning text-dark',
                'APPROVED'     => 'bg-info text-dark',
                'DONE'         => 'bg-success',
                'FAILED'       => 'bg-danger',
                'RUNNING'      => 'bg-primary',
                'CANCELLED'    => 'bg-secondary',
                default        => 'bg-secondary',
            };
            ?>
            <span class="badge glue-status-badge <?php echo $badge; ?>"><?php echo View::e($st); ?></span>
            <div class="glue-job-error path-text text-danger" style="font-size:0.68rem;max-width:14rem"
                 title="<?php echo View::e((string) ($job['error_message'] ?? '')); ?>">
              <?php if ($st === 'FAILED' && !empty($job['error_message'])): ?>
              <?php echo View::e((string) $job['error_message']); ?>
              <?php endif; ?>
            </div>
          </td>
          <td><?php echo $partCount; ?></td>
          <td>
            <?php if (!empty($job['output_filename'])): ?>
            <div class="path-filename"><?php echo View::e((string) $job['output_filename']); ?></div>
            <?php elseif (!empty($job['output_path'])): ?>
            <div class="path-text" style="font-size:0.7rem"><?php echo View::e(basename((string) $job['output_path'])); ?></div>
            <?php else: ?>
            —
            <?php endif; ?>
          </td>
          <td class="path-text" style="font-size:0.72rem">
            <?php echo View::e((string) ($job['created_by_email'] ?? '')); ?><br>
            <?php echo View::e((string) ($job['created_at'] ?? '')); ?>
          </td>
          <td class="text-end">
            <a href="/glue/<?php echo (int) $job['id']; ?>" class="btn btn-outline-secondary btn-xs">Open</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
  <div class="card-footer">
    <?php
    $baseUrl = '/glue' . ($statusFilter !== '' ? '?status=' . urlencode($statusFilter) : '');
    $pageParam = $statusFilter !== '' ? '&page=' : '?page=';
    ?>
    <div class="d-flex justify-content-between align-items-center" style="font-size:0.8rem">
      <span class="path-text">Page <?php echo $page; ?> / <?php echo $totalPages; ?></span>
      <div class="d-flex gap-2">
        <?php if ($page > 1): ?>
        <a class="btn btn-outline-secondary btn-xs" href="<?php echo View::e($baseUrl . $pageParam . ($page - 1)); ?>">Prev</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
        <a class="btn btn-outline-secondary btn-xs" href="<?php echo View::e($baseUrl . $pageParam . ($page + 1)); ?>">Next</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<div class="mb-3 path-text" style="font-size:0.8rem">
  <?php echo number_format($totalParts); ?> file(s) flagged · <?php echo number_format(count($groups)); ?> group(s)
</div>

<?php if ($groups === []): ?>
<div class="card">
  <div class="card-body text-center path-text py-5">
    No glue groups yet. Run a Scan, or select 2+ related parts in Catalog and choose
    <strong>Mark as Glue Group</strong>.
  </div>
</div>
<?php else: ?>
<?php foreach ($groups as $group): ?>
<?php
$groupKey = (string) $group['glue_group_key'];
$isManual = str_starts_with($groupKey, 'manual:');
$groupFiles = $group['files'];
$first = $groupFiles[0] ?? null;
$dir = $first !== null ? (string) ($first['original_dir'] ?? dirname((string) ($first['original_path'] ?? ''))) : '';
$activeJob = $activeByGroup[$groupKey] ?? null;
?>
<div class="card mb-3">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
    <div>
      <span class="badge <?php echo $isManual ? 'bg-info text-dark' : 'bg-secondary'; ?>">
        <?php echo $isManual ? 'Manual' : 'Auto'; ?>
      </span>
      <span class="ms-2" style="font-size:0.85rem">
        <?php echo (int) $group['part_count']; ?> parts
      </span>
      <span class="glue-group-active" data-glue-group="<?php echo View::e($groupKey); ?>">
      <?php if ($activeJob !== null): ?>
      <a href="/glue/<?php echo (int) $activeJob['id']; ?>" class="badge bg-primary text-decoration-none ms-1">
        Job #<?php echo (int) $activeJob['id']; ?> · <?php echo View::e((string) $activeJob['status']); ?>
      </a>
      <?php endif; ?>
      </span>
      <?php if ($dir !== ''): ?>
      <div class="path-text mt-1" style="font-size:0.72rem"><?php echo View::e($dir); ?></div>
      <?php endif; ?>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a href="/queue?status=ALL&amp;glue_group=<?php echo View::e(rawurlencode($groupKey)); ?>"
         class="btn btn-outline-secondary btn-xs">Open in Catalog</a>
      <?php if (Auth::isAdmin() && $activeJob === null): ?>
      <form method="post" action="/glue/queue" class="d-inline"
            onsubmit="return confirm('Queue this group for ffmpeg concat?');">
        <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
        <input type="hidden" name="glue_group_key" value="<?php echo View::e($groupKey); ?>">
        <button type="submit" class="btn btn-primary btn-xs">Queue concat</button>
      </form>
      <?php elseif (Auth::isAdmin() && $activeJob !== null): ?>
      <a href="/glue/<?php echo (int) $activeJob['id']; ?>" class="btn btn-outline-primary btn-xs">Manage job</a>
      <?php endif; ?>
      <?php if (Auth::isEditor()): ?>
      <form method="post" action="/queue/clear-glue" class="d-inline"
            onsubmit="return confirm('Clear glue flags for all parts in this group?');">
        <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
        <input type="hidden" name="return" value="/glue">
        <?php foreach ($groupFiles as $f): ?>
        <input type="hidden" name="ids[]" value="<?php echo (int) $f['id']; ?>">
        <?php endforeach; ?>
        <button type="submit" class="btn btn-outline-warning btn-xs">Clear group</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle" style="font-size:0.78rem">
      <thead>
        <tr>
          <th style="width:4rem">Part</th>
          <th>File</th>
          <th>Proposed</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($groupFiles as $f): ?>
        <tr>
          <td>
            <code><?php echo View::e((string) ($f['glue_part_index'] ?? '—')); ?></code>
          </td>
          <td>
            <div class="path-filename"><?php echo View::e((string) ($f['original_filename'] ?? '')); ?></div>
            <div class="path-text" style="font-size:0.7rem">
              <?php echo View::e((string) ($f['show_abbr'] ?? '—')); ?>
              · <?php echo View::e((string) ($f['media_type_name'] ?? '—')); ?>
              · <?php echo View::duration($f['duration_seconds'] ?? null); ?>
            </div>
          </td>
          <td>
            <?php if (!empty($f['proposed_filename'])): ?>
            <div class="path-filename proposed"><?php echo View::e((string) $f['proposed_filename']); ?></div>
            <?php else: ?>
            —
            <?php endif; ?>
          </td>
          <td>
            <span class="badge bg-secondary"><?php echo View::e((string) ($f['status'] ?? '')); ?></span>
          </td>
          <td class="text-end">
            <a href="/queue?status=ALL&amp;file_id=<?php echo (int) $f['id']; ?>"
               class="btn btn-outline-secondary btn-xs">Catalog</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (!empty($groupFiles[0]['glue_notes'])): ?>
  <div class="card-footer path-text py-2" style="font-size:0.72rem">
    <?php echo View::e((string) $groupFiles[0]['glue_notes']); ?>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<script src="/js/live-poll.js"></script>
<script>
(function () {
  var statusFilter = <?php echo json_encode($statusFilter, JSON_THROW_ON_ERROR); ?>;
  var page = <?php echo (int) $page; ?>;
  var lastIds = null;
  var params = new URLSearchParams();
  if (statusFilter) params.set('status', statusFilter);
  params.set('page', String(page));
  var esc = LivePoll.escapeHtml;

  LivePoll.start({
    url: '/glue/list-status?' + params.toString(),
    intervalMs: 5000,
    onData: function (data) {
      var ids = (data.ids || []).join(',');
      if (lastIds !== null && lastIds !== ids) {
        window.location.reload();
        return;
      }
      lastIds = ids;

      var counts = data.status_counts || {};
      var all = 0;
      Object.keys(counts).forEach(function (k) { all += Number(counts[k] || 0); });
      document.querySelectorAll('[data-glue-status-pill]').forEach(function (pill) {
        var key = pill.getAttribute('data-glue-status-pill');
        var el = pill.querySelector('.glue-status-cnt');
        if (!el) return;
        el.textContent = String(key === 'ALL' ? all : (counts[key] || 0));
      });
      var totalEl = document.getElementById('glue-job-total');
      if (totalEl) totalEl.textContent = Number(data.job_total || 0).toLocaleString();

      (data.jobs || []).forEach(function (job) {
        var row = document.querySelector('[data-glue-job="' + job.id + '"]');
        if (!row) return;
        var badge = row.querySelector('.glue-status-badge');
        if (badge) {
          badge.className = 'badge glue-status-badge ' + (job.status_badge || 'bg-secondary');
          badge.textContent = job.status || '';
        }
        var err = row.querySelector('.glue-job-error');
        if (err) {
          var msg = (job.status === 'FAILED' && job.error_message) ? String(job.error_message) : '';
          err.textContent = msg;
          err.title = msg;
        }
      });

      var active = data.active_by_group || {};
      document.querySelectorAll('.glue-group-active').forEach(function (wrap) {
        var key = wrap.getAttribute('data-glue-group') || '';
        var aj = active[key];
        if (!aj) {
          wrap.innerHTML = '';
          return;
        }
        wrap.innerHTML = '<a href="/glue/' + aj.id + '" class="badge bg-primary text-decoration-none ms-1">'
          + 'Job #' + aj.id + ' · ' + esc(aj.status || '') + '</a>';
      });
    },
    shouldStop: function (data) { return data.poll === false; }
  });
})();
</script>
