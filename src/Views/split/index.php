<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var list<array<string, mixed>> $items */
/** @var list<array<string, mixed>> $splittable */
/** @var array<string, int> $statusCounts */
/** @var string $statusFilter */
/** @var int $total */
/** @var int $page */
/** @var int $totalPages */
?>

<style>
.sq-page-title { color: var(--text-main); letter-spacing: 0.02em; font-weight: 600; }
.sq-lede { color: var(--text-soft); font-size: 0.82rem; line-height: 1.5; max-width: 46rem; }
.sq-empty { color: var(--text-soft); }
.sq-side-item {
  background: var(--panel-strong) !important;
  border-color: var(--border-color) !important;
}
.sq-side-item .path-filename { color: var(--text-main); }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1 sq-page-title">Split Queue</h1>
    <p class="mb-0 sq-lede">
      Long files flagged for segmentation. Mark the show itself in the workbench — export will add ±5&nbsp;min handles later.
    </p>
  </div>
  <a href="/queue?needs_split=1" class="btn btn-outline-secondary btn-sm">View in Queue</a>
</div>

<div class="d-flex flex-wrap gap-2 mb-3" id="split-status-pills">
  <?php
  $statuses = ['', 'PENDING', 'IN_PROGRESS', 'DONE', 'FAILED'];
  foreach ($statuses as $st):
      $active = $statusFilter === $st;
      $label  = $st === '' ? 'All' : $st;
      $cnt    = $st === '' ? array_sum($statusCounts) : ($statusCounts[$st] ?? 0);
      $pillKey = $st === '' ? 'ALL' : $st;
  ?>
  <a href="/split<?php echo $st !== '' ? '?status=' . urlencode($st) : ''; ?>"
     class="btn btn-sm <?php echo $active ? 'btn-primary' : 'btn-outline-secondary'; ?>"
     data-split-status-pill="<?php echo View::e($pillKey); ?>">
    <?php echo View::e($label); ?> <span class="opacity-75">(<span class="split-status-cnt"><?php echo $cnt; ?></span>)</span>
  </a>
  <?php endforeach; ?>
</div>

<div class="row g-4">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">Split Jobs (<span id="split-job-total"><?php echo number_format($total); ?></span>)</div>
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead>
            <tr>
              <th>File</th>
              <th>Duration</th>
              <th>Status</th>
              <th>Segments</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($items === []): ?>
            <tr>
              <td colspan="5" class="text-center py-4 sq-empty">No split jobs.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($items as $item): ?>
            <?php
            $segs = json_decode((string) ($item['segments'] ?? '[]'), true);
            $segCount = is_array($segs) ? count($segs) : 0;
            ?>
            <tr data-split-job="<?php echo (int) $item['id']; ?>">
              <td>
                <div class="path-filename"><?php echo View::e($item['original_filename']); ?></div>
                <div class="path-text"><?php echo View::e($item['original_path']); ?></div>
                <?php echo View::assetIdBlock($item); ?>
                <?php if (!empty($item['split_notes'])): ?>
                <div class="path-text mt-1"><?php echo View::e($item['split_notes']); ?></div>
                <?php endif; ?>
                <div class="split-audio-badge path-text mt-1" style="font-size:0.7rem"></div>
              </td>
              <td class="text-nowrap"><?php echo View::duration($item['duration_seconds'] ?? null); ?></td>
              <td class="split-job-status"><?php echo View::statusBadge((string) $item['status']); ?></td>
              <td class="split-seg-count"><?php echo $segCount; ?></td>
              <td class="text-end">
                <a href="/split/<?php echo (int) $item['id']; ?><?php echo $statusFilter !== '' ? '?status=' . urlencode($statusFilter) : ''; ?>"
                   class="btn btn-outline-secondary btn-xs">Open</a>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php
    $paginationBasePath = '/split';
    $paginationQuery = [
        'status' => $statusFilter,
    ];
    require dirname(__DIR__) . '/partials/pagination.php';
    ?>
  </div>

  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">Add from Queue</div>
      <div class="card-body p-0">
        <?php if ($splittable === []): ?>
        <div class="p-4 text-center sq-empty" style="font-size:0.85rem">
          No flagged files waiting for split queue.
        </div>
        <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($splittable as $file): ?>
          <li class="list-group-item sq-side-item">
            <div class="path-filename" style="font-size:0.82rem"><?php echo View::e($file['original_filename']); ?></div>
            <div class="path-text"><?php echo View::duration($file['duration_seconds'] ?? null); ?>
              · <?php echo View::e($file['show_abbr'] ?? '—'); ?></div>
            <form method="post" action="/split/create" class="mt-2">
              <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
              <input type="hidden" name="file_id" value="<?php echo (int) $file['id']; ?>">
              <button type="submit" class="btn btn-primary btn-xs">Add to Split Queue</button>
            </form>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="/js/live-poll.js"></script>
<script>
(function () {
  var statusFilter = <?php echo json_encode($statusFilter, JSON_THROW_ON_ERROR); ?>;
  var page = <?php echo (int) $page; ?>;
  var lastIds = null;
  var params = new URLSearchParams();
  if (statusFilter) params.set('status', statusFilter);
  params.set('page', String(page));

  LivePoll.start({
    url: '/split/list-status?' + params.toString(),
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
      document.querySelectorAll('[data-split-status-pill]').forEach(function (pill) {
        var key = pill.getAttribute('data-split-status-pill');
        var el = pill.querySelector('.split-status-cnt');
        if (!el) return;
        el.textContent = String(key === 'ALL' ? all : (counts[key] || 0));
      });
      var totalEl = document.getElementById('split-job-total');
      if (totalEl) totalEl.textContent = Number(data.total || 0).toLocaleString();

      (data.jobs || []).forEach(function (job) {
        var row = document.querySelector('[data-split-job="' + job.id + '"]');
        if (!row) return;
        var status = row.querySelector('.split-job-status');
        if (status) status.innerHTML = job.status_badge_html || '';
        var seg = row.querySelector('.split-seg-count');
        if (seg) seg.textContent = String(job.segment_count || 0);
        var audio = row.querySelector('.split-audio-badge');
        if (audio) {
          if (job.active_audio_job) {
            var a = job.active_audio_job;
            audio.textContent = 'Audio ' + (a.kind || 'job') + ' · ' + (a.status || '')
              + (a.orphan ? ' (hung)' : '');
          } else {
            audio.textContent = '';
          }
        }
      });
    },
    shouldStop: function (data) { return data.poll === false; }
  });
})();
</script>
