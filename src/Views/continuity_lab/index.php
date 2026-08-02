<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var array<string, mixed> $status */
/** @var array<string, int> $summary */
/** @var list<array<string, mixed>> $entries */
/** @var array{outcome?: string, q?: string} $filters */
/** @var float|null $avgMs */
/** @var array<string, mixed> $eta */
/** @var int $total */
/** @var int $page */
/** @var int $totalPages */
/** @var int $perPage */
/** @var bool $live */

$queryBase = array_filter([
    'outcome' => $filters['outcome'] ?? '',
    'q'       => $filters['q'] ?? '',
    'live'    => $live ? '1' : '',
], static fn ($v) => $v !== '' && $v !== null);
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Continuity Lab</h1>
    <p class="mb-0 path-text" style="font-size:0.8rem">
      Private observer for broadcast continuity checks — decisions, reasons, and throughput.
      Not linked in the main nav.
    </p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="/continuity-lab/export?<?php echo View::e(http_build_query(array_filter([
           'outcome' => $filters['outcome'] ?? '',
           'q'       => $filters['q'] ?? '',
         ], static fn ($v) => $v !== '' && $v !== null))); ?>"
       class="btn btn-outline-secondary btn-sm"
       title="Exports up to 60,000 newest matching rows">Export XLSX</a>
    <form method="post" action="/continuity-lab/test" class="d-inline">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <button type="submit" class="btn btn-outline-info btn-sm">Test engine</button>
    </form>
    <button type="button" class="btn btn-outline-danger btn-sm"
            data-bs-toggle="modal" data-bs-target="#clear-continuity-log-modal">
      Clear log
    </button>
    <?php if ($live): ?>
    <a href="/continuity-lab?<?php echo View::e(http_build_query(array_diff_key($queryBase, ['live' => 1]))); ?>"
       class="btn btn-outline-secondary btn-sm">Pause live</a>
    <?php else: ?>
    <a href="/continuity-lab?<?php echo View::e(http_build_query($queryBase + ['live' => '1'])); ?>"
       class="btn btn-outline-primary btn-sm">Live refresh</a>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-header">Engine</div>
      <div class="card-body" style="font-size:0.82rem">
        <div class="mb-2">
          Status:
          <span id="continuity-engine-badge">
          <?php if (!empty($status['enabled']) && !empty($status['reachable'])): ?>
          <span class="badge bg-success">Online</span>
          <?php elseif (!empty($status['enabled'])): ?>
          <span class="badge bg-warning text-dark">Enabled · offline</span>
          <?php else: ?>
          <span class="badge bg-secondary">Disabled</span>
          <?php endif; ?>
          </span>
        </div>
        <div class="path-text">Pack: <code><?php echo View::e((string) ($status['pack'] ?? '')); ?></code></div>
        <div class="path-text">Endpoint: <code><?php echo View::e((string) ($status['base_url'] ?? '')); ?></code></div>
        <div class="path-text" id="continuity-probe">
          Probe:
          <?php if ($status['latency_ms'] !== null): ?>
          <?php echo (int) $status['latency_ms']; ?> ms
          <?php else: ?>
          —
          <?php endif; ?>
          · timeout <?php echo (int) ($status['timeout_seconds'] ?? 0); ?>s
          · keep_alive <code><?php echo View::e((string) ($status['keep_alive'] ?? '24h')); ?></code>
        </div>
        <?php
        $packs = $status['packs'] ?? [];
        if (!is_array($packs)) {
            $packs = [];
        }
        $configured = (string) ($status['pack'] ?? '');
        $packOk = $packs === [] ? null : (
            in_array($configured, $packs, true)
            || (bool) array_filter($packs, static fn ($p) => is_string($p) && (
                str_starts_with(strtolower($p), strtolower($configured))
                || str_starts_with(strtolower($configured), strtolower($p))
            ))
        );
        ?>
        <div class="path-text mt-2">
          Loaded packs:
          <?php if ($packs === []): ?>
          <em>none</em>
          <?php else: ?>
          <code><?php echo View::e(implode(', ', array_map('strval', $packs))); ?></code>
          <?php endif; ?>
        </div>
        <?php if ($packOk === false): ?>
        <div class="text-warning mt-2" style="font-size:0.78rem">
          Configured pack is not loaded. On the host run:
          <code>ollama pull <?php echo View::e($configured); ?></code>
        </div>
        <?php endif; ?>
        <div class="path-text mt-1">
          Effective decide timeout: <strong><?php echo (int) ($status['timeout_seconds'] ?? 0); ?>s</strong>
          (minimum 60s enforced in app)
        </div>
        <div class="path-text mt-1">
          Pack keep_alive: <code><?php echo View::e((string) ($status['keep_alive'] ?? '24h')); ?></code>
          — renews on each decide; host may also set <code>OLLAMA_KEEP_ALIVE</code>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-8">
    <div class="card h-100">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>Progress</span>
        <span id="continuity-parallel-meta" class="path-text" style="font-size:0.75rem;font-weight:400">
          Parallel ×<?php echo (int) ($eta['parallel'] ?? 1); ?>
          <?php if (!empty($eta['active']) && ($eta['method'] ?? '') === 'observed'): ?>
          · rate from last 5 min
          <?php elseif (!empty($eta['active']) && ($eta['method'] ?? '') === 'modeled'): ?>
          · modeled (avg ÷ parallel)
          <?php endif; ?>
        </span>
      </div>
      <div class="card-body">
        <div id="continuity-eta-block">
        <?php if (!empty($eta['active'])): ?>
        <div class="mb-3 pb-3" style="border-bottom:1px solid var(--border-color)">
          <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-1">
            <div>
              <span class="h4 mb-0" id="continuity-eta-label"><?php echo View::e((string) ($eta['eta_label'] ?? '—')); ?></span>
              <span class="path-text ms-2" style="font-size:0.78rem">ETA to scan completion</span>
            </div>
            <div class="path-text" style="font-size:0.78rem" id="continuity-eta-job">
              <?php if (!empty($eta['job_id'])): ?>
              <a href="/scan/<?php echo (int) $eta['job_id']; ?>">Scan #<?php echo (int) $eta['job_id']; ?></a>
              <?php endif; ?>
              <?php if (($eta['source_name'] ?? '') !== ''): ?>
              · <?php echo View::e((string) $eta['source_name']); ?>
              <?php endif; ?>
            </div>
          </div>
          <div class="progress mb-1" style="height:8px" role="progressbar"
               aria-valuenow="<?php echo (int) round((float) ($eta['pct'] ?? 0)); ?>"
               aria-valuemin="0" aria-valuemax="100">
            <div id="continuity-eta-bar" class="progress-bar" style="width:<?php echo View::e((string) ($eta['pct'] ?? 0)); ?>%"></div>
          </div>
          <div class="path-text" style="font-size:0.78rem" id="continuity-eta-counts">
            <?php echo number_format((int) ($eta['processed'] ?? 0)); ?>
            /
            <?php echo number_format((int) ($eta['total'] ?? 0)); ?>
            files
            <?php if ((int) ($eta['remaining'] ?? 0) > 0): ?>
            · <?php echo number_format((int) $eta['remaining']); ?> left
            <?php endif; ?>
            <?php if (!empty($eta['rate_per_sec'])): ?>
            · ~<?php echo View::e(number_format((float) $eta['rate_per_sec'], 2)); ?>/s
            <?php endif; ?>
          </div>
        </div>
        <?php else: ?>
        <div class="path-text mb-3 pb-3" style="font-size:0.8rem;border-bottom:1px solid var(--border-color)">
          No active scan — ETA appears when a Scan / Rescan is <code>RUNNING</code>.
          Parallelism: ×<?php echo (int) ($eta['parallel'] ?? 1); ?>
          (<code>CONTINUITY_CHECK_CONCURRENCY</code><?php
          $engineP = (int) env('OLLAMA_NUM_PARALLEL', 0);
          if ($engineP > 0): ?>
          ∩ <code>OLLAMA_NUM_PARALLEL</code>=<?php echo $engineP; ?>
          <?php endif; ?>)
        </div>
        <?php endif; ?>
        </div>
        <div class="row g-2 text-center" style="font-size:0.82rem">
          <div class="col">
            <div class="h5 mb-0" id="continuity-sum-hour"><?php echo (int) ($summary['last_hour'] ?? 0); ?></div>
            <div class="path-text">Last hour</div>
          </div>
          <div class="col">
            <div class="h5 mb-0" id="continuity-sum-total"><?php echo (int) ($summary['total'] ?? 0); ?></div>
            <div class="path-text">All time</div>
          </div>
          <div class="col">
            <div class="h5 mb-0 text-success" id="continuity-sum-confirmed"><?php echo (int) ($summary['confirmed'] ?? 0); ?></div>
            <div class="path-text">Confirmed</div>
          </div>
          <div class="col">
            <div class="h5 mb-0 text-warning" id="continuity-sum-conflict"><?php echo (int) ($summary['conflict'] ?? 0); ?></div>
            <div class="path-text">Conflict</div>
          </div>
          <div class="col">
            <div class="h5 mb-0" id="continuity-sum-review"><?php echo (int) ($summary['review'] ?? 0); ?></div>
            <div class="path-text">Review</div>
          </div>
          <div class="col">
            <div class="h5 mb-0" id="continuity-sum-error"><?php echo (int) ($summary['error'] ?? 0); ?></div>
            <div class="path-text">Errors</div>
          </div>
          <div class="col">
            <div class="h5 mb-0" id="continuity-sum-avg">
              <?php echo $avgMs !== null ? (string) (int) round($avgMs) . 'ms' : '—'; ?>
            </div>
            <div class="path-text">Avg decide</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<form method="get" action="/continuity-lab" class="row g-2 align-items-end mb-3">
  <?php if ($live): ?><input type="hidden" name="live" value="1"><?php endif; ?>
  <div class="col-md-3">
    <label class="form-label">Outcome</label>
    <select name="outcome" class="form-select form-select-sm">
      <option value="">All</option>
      <?php foreach (['confirmed', 'conflict', 'review', 'error', 'unreachable'] as $o): ?>
      <option value="<?php echo View::e($o); ?>"
        <?php echo ($filters['outcome'] ?? '') === $o ? 'selected' : ''; ?>>
        <?php echo View::e(ucfirst($o)); ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-6">
    <label class="form-label">Search path / reason</label>
    <input type="text" name="q" class="form-control form-control-sm"
           value="<?php echo View::e((string) ($filters['q'] ?? '')); ?>"
           placeholder="filename, path, or reason text">
  </div>
  <div class="col-md-3">
    <button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button>
  </div>
</form>

<div class="card">
  <div class="card-header d-flex justify-content-between">
    <span id="continuity-decisions-title">Decisions (<?php echo (int) $total; ?>)</span>
    <span id="continuity-page-meta" class="path-text" style="font-size:0.75rem">
      Page <?php echo (int) $page; ?> / <?php echo (int) $totalPages; ?>
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle" style="font-size:0.78rem">
      <thead>
        <tr>
          <th>Time (ET)</th>
          <th>Outcome</th>
          <th title="Parsing rules → model verdict → merged final">
            Confidence
            <div class="path-text" style="font-size:0.65rem;font-weight:400">Rules / Model / Final</div>
          </th>
          <th title="Show abbreviation from rules vs model vs merge">
            Show
            <div class="path-text" style="font-size:0.65rem;font-weight:400">Rules / Model / Final</div>
          </th>
          <th title="Media type from rules vs model vs merge">
            Type
            <div class="path-text" style="font-size:0.65rem;font-weight:400">Rules / Model / Final</div>
          </th>
          <th title="Air date/time from rules vs model vs merge">
            Date / Time
            <div class="path-text" style="font-size:0.65rem;font-weight:400">Rules / Model / Final</div>
          </th>
          <th>Reason / signals</th>
          <th>File</th>
          <th>ms</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="continuity-entries-tbody">
        <?php require __DIR__ . '/_entries_tbody.php'; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3">
  <ul class="pagination pagination-sm flex-wrap">
    <?php
    $start = max(1, $page - 5);
    $end = min($totalPages, $page + 5);
    for ($p = $start; $p <= $end; $p++):
      $pageQuery = $queryBase;
      $pageQuery['page'] = (string) $p;
    ?>
    <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
      <a class="page-link" href="/continuity-lab?<?php echo View::e(http_build_query($pageQuery)); ?>">
        <?php echo $p; ?>
      </a>
    </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<div class="modal fade" id="clear-continuity-log-modal" tabindex="-1" aria-labelledby="clear-continuity-log-label" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" action="/continuity-lab/clear" class="modal-content">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <div class="modal-header">
        <h2 class="modal-title h5" id="clear-continuity-log-label">Clear continuity log</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" style="font-size:0.85rem">
        <p class="mb-2">
          Permanently deletes all Continuity Lab rows
          (<?php echo number_format((int) ($summary['total'] ?? 0)); ?> currently).
          Catalog files and scan jobs are not affected.
        </p>
        <label class="form-label" for="clear-confirm">Type <code>CLEAR</code> to confirm</label>
        <input type="text" name="confirm" id="clear-confirm" class="form-control form-control-sm"
               autocomplete="off" required>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-danger btn-sm">Clear log</button>
      </div>
    </form>
  </div>
</div>

<?php if ($live): ?>
<script src="/js/live-poll.js"></script>
<script>
(function () {
  var page = <?php echo (int) $page; ?>;
  var outcome = <?php echo json_encode((string) ($filters['outcome'] ?? ''), JSON_THROW_ON_ERROR); ?>;
  var q = <?php echo json_encode((string) ($filters['q'] ?? ''), JSON_THROW_ON_ERROR); ?>;
  var newestId = null;
  var esc = LivePoll.escapeHtml;
  var fmt = function (n) { return Number(n || 0).toLocaleString(); };

  var params = new URLSearchParams();
  params.set('page', String(page));
  if (outcome) params.set('outcome', outcome);
  if (q) params.set('q', q);

  function uiBusy() {
    return !!(document.querySelector('.modal.show')
      || document.querySelector('.collapse.show')
      || (document.activeElement && (
        document.activeElement.tagName === 'INPUT'
        || document.activeElement.tagName === 'SELECT'
        || document.activeElement.tagName === 'TEXTAREA'
      )));
  }

  function engineBadge(st) {
    if (st && st.enabled && st.reachable) {
      return '<span class="badge bg-success">Online</span>';
    }
    if (st && st.enabled) {
      return '<span class="badge bg-warning text-dark">Enabled · offline</span>';
    }
    return '<span class="badge bg-secondary">Disabled</span>';
  }

  function renderEta(eta) {
    var parallel = Number(eta.parallel || 1);
    var meta = 'Parallel ×' + parallel;
    if (eta.active && eta.method === 'observed') meta += ' · rate from last 5 min';
    if (eta.active && eta.method === 'modeled') meta += ' · modeled (avg ÷ parallel)';
    LivePoll.setText('continuity-parallel-meta', meta);

    if (!eta.active) {
      LivePoll.setHtml(
        'continuity-eta-block',
        '<div class="path-text mb-3 pb-3" style="font-size:0.8rem;border-bottom:1px solid var(--border-color)">'
          + 'No active scan — ETA appears when a Scan / Rescan is <code>RUNNING</code>. '
          + 'Parallelism: ×' + parallel
          + ' (<code>CONTINUITY_CHECK_CONCURRENCY</code>)</div>'
      );
      return;
    }

    var jobHtml = '';
    if (eta.job_id) {
      jobHtml += '<a href="/scan/' + Number(eta.job_id) + '">Scan #' + Number(eta.job_id) + '</a>';
    }
    if (eta.source_name) {
      jobHtml += (jobHtml ? ' · ' : '') + esc(eta.source_name);
    }
    var counts = fmt(eta.processed) + ' / ' + fmt(eta.total) + ' files';
    if (Number(eta.remaining || 0) > 0) counts += ' · ' + fmt(eta.remaining) + ' left';
    if (eta.rate_per_sec) counts += ' · ~' + Number(eta.rate_per_sec).toFixed(2) + '/s';

    LivePoll.setHtml(
      'continuity-eta-block',
      '<div class="mb-3 pb-3" style="border-bottom:1px solid var(--border-color)">'
        + '<div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-1">'
        + '<div><span class="h4 mb-0" id="continuity-eta-label">' + esc(eta.eta_label || '—') + '</span>'
        + '<span class="path-text ms-2" style="font-size:0.78rem">ETA to scan completion</span></div>'
        + '<div class="path-text" style="font-size:0.78rem" id="continuity-eta-job">' + jobHtml + '</div></div>'
        + '<div class="progress mb-1" style="height:8px"><div id="continuity-eta-bar" class="progress-bar" style="width:'
        + Number(eta.pct || 0) + '%"></div></div>'
        + '<div class="path-text" style="font-size:0.78rem" id="continuity-eta-counts">' + counts + '</div></div>'
    );
  }

  LivePoll.start({
    url: '/continuity-lab/status?' + params.toString(),
    intervalMs: 8000,
    shouldSkip: uiBusy,
    onData: function (data) {
      var st = data.status || {};
      var summary = data.summary || {};
      var eta = data.eta || {};

      LivePoll.setHtml('continuity-engine-badge', engineBadge(st));
      LivePoll.setHtml(
        'continuity-probe',
        'Probe: '
          + (st.latency_ms != null ? (Number(st.latency_ms) + ' ms') : '—')
          + ' · timeout ' + Number(st.timeout_seconds || 0) + 's'
          + ' · keep_alive <code>' + esc(st.keep_alive || '24h') + '</code>'
      );

      renderEta(eta);
      LivePoll.setText('continuity-sum-hour', String(summary.last_hour || 0));
      LivePoll.setText('continuity-sum-total', String(summary.total || 0));
      LivePoll.setText('continuity-sum-confirmed', String(summary.confirmed || 0));
      LivePoll.setText('continuity-sum-conflict', String(summary.conflict || 0));
      LivePoll.setText('continuity-sum-review', String(summary.review || 0));
      LivePoll.setText('continuity-sum-error', String(summary.error || 0));
      LivePoll.setText(
        'continuity-sum-avg',
        data.avg_ms != null ? (Math.round(Number(data.avg_ms)) + 'ms') : '—'
      );
      LivePoll.setText('continuity-decisions-title', 'Decisions (' + Number(data.total || 0) + ')');
      LivePoll.setText(
        'continuity-page-meta',
        'Page ' + Number(data.page || page) + ' / ' + Number(data.total_pages || 1)
      );

      // Refresh table only when new rows arrive and UI is not mid-interaction.
      if (!uiBusy() && data.entries_html != null
          && (newestId === null || Number(data.newest_id || 0) !== newestId)) {
        LivePoll.setHtml('continuity-entries-tbody', data.entries_html);
        newestId = Number(data.newest_id || 0);
      }
    }
  });
})();
</script>
<?php endif; ?>
