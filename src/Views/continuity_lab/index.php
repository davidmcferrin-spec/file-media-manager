<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var array<string, mixed> $status */
/** @var array<string, int> $summary */
/** @var list<array<string, mixed>> $entries */
/** @var array{outcome?: string, q?: string} $filters */
/** @var float|null $avgMs */
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
          <?php if (!empty($status['enabled']) && !empty($status['reachable'])): ?>
          <span class="badge bg-success">Online</span>
          <?php elseif (!empty($status['enabled'])): ?>
          <span class="badge bg-warning text-dark">Enabled · offline</span>
          <?php else: ?>
          <span class="badge bg-secondary">Disabled</span>
          <?php endif; ?>
        </div>
        <div class="path-text">Pack: <code><?php echo View::e((string) ($status['pack'] ?? '')); ?></code></div>
        <div class="path-text">Endpoint: <code><?php echo View::e((string) ($status['base_url'] ?? '')); ?></code></div>
        <div class="path-text">
          Probe:
          <?php if ($status['latency_ms'] !== null): ?>
          <?php echo (int) $status['latency_ms']; ?> ms
          <?php else: ?>
          —
          <?php endif; ?>
          · timeout <?php echo (int) ($status['timeout_seconds'] ?? 0); ?>s
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
      </div>
    </div>
  </div>
  <div class="col-md-8">
    <div class="card h-100">
      <div class="card-header">Progress</div>
      <div class="card-body">
        <div class="row g-2 text-center" style="font-size:0.82rem">
          <div class="col">
            <div class="h5 mb-0"><?php echo (int) ($summary['last_hour'] ?? 0); ?></div>
            <div class="path-text">Last hour</div>
          </div>
          <div class="col">
            <div class="h5 mb-0"><?php echo (int) ($summary['total'] ?? 0); ?></div>
            <div class="path-text">All time</div>
          </div>
          <div class="col">
            <div class="h5 mb-0 text-success"><?php echo (int) ($summary['confirmed'] ?? 0); ?></div>
            <div class="path-text">Confirmed</div>
          </div>
          <div class="col">
            <div class="h5 mb-0 text-warning"><?php echo (int) ($summary['conflict'] ?? 0); ?></div>
            <div class="path-text">Conflict</div>
          </div>
          <div class="col">
            <div class="h5 mb-0"><?php echo (int) ($summary['review'] ?? 0); ?></div>
            <div class="path-text">Review</div>
          </div>
          <div class="col">
            <div class="h5 mb-0"><?php echo (int) ($summary['error'] ?? 0); ?></div>
            <div class="path-text">Errors</div>
          </div>
          <div class="col">
            <div class="h5 mb-0">
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
    <span>Decisions (<?php echo (int) $total; ?>)</span>
    <span class="path-text" style="font-size:0.75rem">
      Page <?php echo (int) $page; ?> / <?php echo (int) $totalPages; ?>
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle" style="font-size:0.78rem">
      <thead>
        <tr>
          <th>Time (ET)</th>
          <th>Outcome</th>
          <th>Rule → Final</th>
          <th>Show</th>
          <th>Reason / signals</th>
          <th>File</th>
          <th>ms</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($entries === []): ?>
        <tr>
          <td colspan="8" class="text-center py-4 path-text">
            No continuity decisions logged yet. Run a Scan or Reclassify with continuity enabled.
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($entries as $row): ?>
        <?php
        $rowId = (int) ($row['id'] ?? 0);
        $outcome = (string) ($row['outcome'] ?? '');
        $badge = match ($outcome) {
            'confirmed' => 'bg-success',
            'conflict'  => 'bg-warning text-dark',
            'review'    => 'bg-info text-dark',
            'error'     => 'bg-danger',
            default     => 'bg-secondary',
        };
        $ruleShow = trim((string) ($row['rule_show_abbr'] ?? ''));
        $finalShow = trim((string) ($row['final_show_abbr'] ?? ''));
        $showChanged = $ruleShow !== '' && $finalShow !== '' && strcasecmp($ruleShow, $finalShow) !== 0;
        $signalsRaw = $row['rule_signals'] ?? '[]';
        if (is_string($signalsRaw)) {
            $signals = json_decode($signalsRaw, true);
        } else {
            $signals = $signalsRaw;
        }
        if (!is_array($signals)) {
            $signals = [];
        }
        $seedRaw = $row['seed_packet'] ?? null;
        if (is_string($seedRaw) && $seedRaw !== '') {
            $seed = json_decode($seedRaw, true);
        } elseif (is_array($seedRaw)) {
            $seed = $seedRaw;
        } else {
            $seed = null;
        }
        $seedPretty = '';
        if (is_array($seed)) {
            $seedPretty = (string) json_encode($seed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $engineRaw = trim((string) ($row['engine_raw'] ?? ''));
        $transportErr = trim((string) ($row['transport_error'] ?? ''));
        $hasArtifacts = $seedPretty !== '' || $engineRaw !== '' || $transportErr !== '';
        $timelineCount = is_array($seed['timeline'] ?? null) ? count($seed['timeline']) : 0;
        $exampleCount = is_array($seed['examples'] ?? null) ? count($seed['examples']) : 0;
        $showCount = is_array($seed['shows'] ?? null) ? count($seed['shows']) : 0;
        ?>
        <tr>
          <td class="path-text text-nowrap">
            <?php echo View::e(substr((string) ($row['created_at'] ?? ''), 0, 19)); ?>
          </td>
          <td>
            <span class="badge <?php echo View::e($badge); ?>"><?php echo View::e($outcome); ?></span>
            <?php if ($row['engine_agree'] !== null): ?>
            <div class="path-text mt-1">
              agree=<?php echo !empty($row['engine_agree']) ? 'yes' : 'no'; ?>
              <?php if (!empty($row['engine_confidence'])): ?>
              · eng <?php echo View::e((string) $row['engine_confidence']); ?>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </td>
          <td class="text-nowrap">
            <code><?php echo View::e((string) ($row['rule_confidence'] ?? '')); ?></code>
            →
            <code><?php echo View::e((string) ($row['final_confidence'] ?? '')); ?></code>
          </td>
          <td>
            <?php if ($showChanged): ?>
            <code><?php echo View::e($ruleShow); ?></code>
            →
            <code><?php echo View::e($finalShow); ?></code>
            <?php else: ?>
            <code><?php echo View::e($finalShow !== '' ? $finalShow : ($ruleShow !== '' ? $ruleShow : '—')); ?></code>
            <?php endif; ?>
          </td>
          <td style="max-width:280px">
            <?php if (trim((string) ($row['engine_reason'] ?? '')) !== ''): ?>
            <div><?php echo View::e((string) $row['engine_reason']); ?></div>
            <?php endif; ?>
            <?php if ($signals !== []): ?>
            <div class="path-text mt-1" style="font-size:0.7rem">
              <?php echo View::e(implode(' · ', array_slice(array_map('strval', $signals), 0, 4))); ?>
              <?php if (count($signals) > 4): ?>…<?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($row['signal'])): ?>
            <div class="path-text" style="font-size:0.7rem"><?php echo View::e((string) $row['signal']); ?></div>
            <?php endif; ?>
          </td>
          <td class="path-text" style="max-width:260px;word-break:break-all">
            <?php echo View::e((string) ($row['original_filename'] ?: $row['original_path'])); ?>
            <?php if (!empty($row['final_proposed_filename']) || !empty($row['rule_proposed_filename'])): ?>
            <div class="mt-1">
              → <?php echo View::e((string) ($row['final_proposed_filename'] ?? $row['rule_proposed_filename'])); ?>
            </div>
            <?php endif; ?>
          </td>
          <td class="path-text"><?php echo (int) ($row['duration_ms'] ?? 0); ?></td>
          <td>
            <?php if ($hasArtifacts): ?>
            <button type="button" class="btn btn-outline-secondary btn-xs"
                    data-bs-toggle="collapse" data-bs-target="#art-<?php echo $rowId; ?>"
                    aria-expanded="false">
              Artifacts
            </button>
            <?php else: ?>
            <span class="path-text">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($hasArtifacts): ?>
        <tr class="collapse-row">
          <td colspan="8" class="p-0 border-0">
            <div class="collapse" id="art-<?php echo $rowId; ?>">
              <div class="p-3" style="background:var(--hover-bg);border-top:1px solid var(--bs-border-color)">
                <div class="d-flex flex-wrap gap-3 mb-2 path-text" style="font-size:0.75rem">
                  <span>Shows seeded: <strong><?php echo (int) $showCount; ?></strong></span>
                  <span>Timeline rows: <strong><?php echo (int) $timelineCount; ?></strong></span>
                  <span>Approved examples: <strong><?php echo (int) $exampleCount; ?></strong></span>
                  <?php if ($row['http_status'] !== null): ?>
                  <span>HTTP: <strong><?php echo (int) $row['http_status']; ?></strong></span>
                  <?php endif; ?>
                </div>
                <?php if ($transportErr !== ''): ?>
                <div class="mb-2">
                  <div class="form-label mb-1">Transport</div>
                  <pre class="mb-0 p-2 rounded" style="font-size:0.72rem;white-space:pre-wrap;background:rgba(0,0,0,0.25)"><?php echo View::e($transportErr); ?></pre>
                </div>
                <?php endif; ?>
                <div class="row g-3">
                  <div class="col-lg-7">
                    <div class="form-label mb-1">Seed packet (what continuity saw)</div>
                    <?php if ($seedPretty !== ''): ?>
                    <pre class="mb-0 p-2 rounded" style="font-size:0.68rem;max-height:360px;overflow:auto;white-space:pre;background:rgba(0,0,0,0.25)"><?php echo View::e($seedPretty); ?></pre>
                    <?php else: ?>
                    <div class="path-text">No seed packet stored for this row (logged before artifacts).</div>
                    <?php endif; ?>
                  </div>
                  <div class="col-lg-5">
                    <div class="form-label mb-1">Engine reply (raw)</div>
                    <?php if ($engineRaw !== ''): ?>
                    <pre class="mb-0 p-2 rounded" style="font-size:0.68rem;max-height:360px;overflow:auto;white-space:pre-wrap;background:rgba(0,0,0,0.25)"><?php echo View::e($engineRaw); ?></pre>
                    <?php else: ?>
                    <div class="path-text">No raw reply captured.</div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
        <?php endif; ?>
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

<?php if ($live): ?>
<script>
setTimeout(function () { window.location.reload(); }, 8000);
</script>
<?php endif; ?>
