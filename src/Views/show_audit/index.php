<?php

declare(strict_types=1);

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var array<string, mixed> $report */
/** @var list<array<string, mixed>> $unmatchedFiles */
/** @var list<array<string, mixed>> $shows */
/** @var list<array<string, mixed>> $openEnded */
/** @var list<list<?array<string, mixed>>> $monthGrid */
/** @var array<string, mixed>|null $weekGrid */
/** @var string $fromIso */
/** @var string $toIso */
/** @var string $mode */
/** @var string $tab */
/** @var string $view */
/** @var string $status */
/** @var string $dateIso */
/** @var int $year */
/** @var int $month */
/** @var int $showId */
/** @var int $unmatchedTotal */
/** @var int $unmatchedPage */
/** @var int $unmatchedPages */
/** @var string $unmatchedSearch */
/** @var int $openEndedTotal */
/** @var int $previewWidth */
/** @var int $previewHeight */
/** @var int $previewDurationMin */
/** @var \DateTimeImmutable $weekStart */
/** @var \DateTimeImmutable $focusDate */

$metrics = $report['metrics'];
$slots = $report['slots'] ?? [];
$duplicates = $report['duplicates'] ?? [];
$expectedGaps = $report['expected_gaps'] ?? [];
$showRollups = $report['show_rollups'] ?? [];
$monthTiles = $report['month_tiles'] ?? [];

$statusBadge = static function (string $status): string {
    $map = [
        'confirmed'    => ['success', 'Confirmed'],
        'needs_split'  => ['warning', 'Needs split'],
        'needs_review' => ['info', 'Needs review'],
        'duplicate'    => ['danger', 'Duplicate'],
        'missing'      => ['secondary', 'Missing'],
        'expected_gap' => ['dark', 'Accepted gap'],
    ];
    [$cls, $label] = $map[$status] ?? ['secondary', $status];

    return '<span class="badge bg-' . $cls . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
};

$queryBase = [
    'mode' => $mode,
    'year' => $year,
    'view' => $view,
];
if ($showId > 0) {
    $queryBase['show_id'] = $showId;
}
if ($view === 'month' || $view === 'week' || $view === 'day') {
    $queryBase['month'] = $month;
    $queryBase['date'] = $dateIso;
}

$calUrl = static function (array $extra = []) use ($mode, $showId): string {
    $q = array_merge([
        'tab'  => 'calendar',
        'mode' => $mode,
    ], $extra);
    if ($showId > 0 && !array_key_exists('show_id', $extra)) {
        $q['show_id'] = $showId;
    }
    foreach ($q as $k => $v) {
        if ($v === null || $v === '' || $v === 0 || $v === '0') {
            unset($q[$k]);
        }
    }

    return '/show-audit?' . http_build_query($q);
};

$tabUrl = static function (string $t) use ($fromIso, $toIso, $mode, $showId, $year, $view, $dateIso): string {
    $q = [
        'tab'  => $t,
        'from' => $fromIso,
        'to'   => $toIso,
        'mode' => $mode,
        'year' => $year,
        'view' => $view,
        'date' => $dateIso,
    ];
    if ($showId > 0) {
        $q['show_id'] = $showId;
    }

    return '/show-audit?' . http_build_query($q);
};

$queueLink = static function (array $file): string {
    return '/queue?' . http_build_query([
        'status' => 'ALL',
        'q'      => (string) ($file['original_filename'] ?? ''),
    ]);
};

$monthNames = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
?>

<?php
$workflowStepId = 'gaps';
require dirname(__DIR__) . '/partials/workflow_step.php';
require dirname(__DIR__) . '/shows/_nav.php';
?>

<style>
.gap-cal { --gap-ok:#1b7a4a; --gap-warn:#b8860b; --gap-missing:#b33b3b; --gap-dup:#9b2c5a; --gap-gap:#3d4450; --gap-empty:#2a3038; }
.gap-legend { display:flex; flex-wrap:wrap; gap:.5rem 1rem; font-size:.72rem; color:var(--text-soft); margin-bottom:1rem; }
.gap-legend span::before { content:''; display:inline-block; width:.65rem; height:.65rem; border-radius:2px; margin-right:.35rem; vertical-align:-1px; }
.gap-legend .lg-ok::before { background:var(--gap-ok); }
.gap-legend .lg-warn::before { background:var(--gap-warn); }
.gap-legend .lg-missing::before { background:var(--gap-missing); }
.gap-legend .lg-dup::before { background:var(--gap-dup); }
.gap-legend .lg-gap::before { background:var(--gap-gap); }
.gap-legend .lg-empty::before { background:var(--gap-empty); border:1px solid var(--border-color); }
.gap-crumb { font-size:.8rem; color:var(--text-soft); }
.gap-crumb a { color:inherit; text-decoration:none; }
.gap-crumb a:hover { color:var(--bs-primary); }
.gap-year-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:.75rem; }
.gap-month-tile { display:block; text-decoration:none; color:inherit; border:1px solid var(--border-color); border-radius:.4rem; padding:.85rem .9rem; background:var(--panel); transition:border-color .15s, transform .15s; border-left:4px solid var(--gap-empty); }
.gap-month-tile:hover { border-color:var(--bs-primary); transform:translateY(-1px); color:inherit; }
.gap-month-tile.tone-ok { border-left-color:var(--gap-ok); }
.gap-month-tile.tone-warn { border-left-color:var(--gap-warn); }
.gap-month-tile.tone-missing { border-left-color:var(--gap-missing); }
.gap-month-tile.tone-duplicate { border-left-color:var(--gap-dup); }
.gap-month-tile.tone-gap, .gap-month-tile.tone-mixed-gap { border-left-color:var(--gap-gap); }
.gap-month-tile.tone-empty { opacity:.65; }
.gap-month-tile .name { font-weight:600; letter-spacing:.04em; font-size:.95rem; }
.gap-month-tile .pct { font-size:1.35rem; font-weight:600; line-height:1.1; margin:.35rem 0 .15rem; }
.gap-month-tile .brief { font-size:.72rem; color:var(--text-soft); }
.gap-month-cal { width:100%; table-layout:fixed; border-collapse:separate; border-spacing:4px; }
.gap-month-cal th { font-size:.7rem; font-weight:600; color:var(--text-soft); text-align:center; padding:.25rem; }
.gap-day-cell { display:block; min-height:72px; border:1px solid var(--border-color); border-radius:.35rem; padding:.35rem .4rem; text-decoration:none; color:inherit; background:var(--panel); border-top:3px solid var(--gap-empty); }
.gap-day-cell:hover { border-color:var(--bs-primary); color:inherit; }
.gap-day-cell.tone-ok { border-top-color:var(--gap-ok); background:rgba(27,122,74,.12); }
.gap-day-cell.tone-warn { border-top-color:var(--gap-warn); background:rgba(184,134,11,.12); }
.gap-day-cell.tone-missing { border-top-color:var(--gap-missing); background:rgba(179,59,59,.14); }
.gap-day-cell.tone-duplicate { border-top-color:var(--gap-dup); background:rgba(155,44,90,.14); }
.gap-day-cell.tone-gap, .gap-day-cell.tone-mixed-gap { border-top-color:var(--gap-gap); background:rgba(61,68,80,.25); }
.gap-day-cell.tone-empty { opacity:.55; }
.gap-day-cell.tone-outside { opacity:.25; pointer-events:none; background:transparent; border-style:dashed; }
.gap-day-cell .dn { font-size:.8rem; font-weight:600; }
.gap-day-cell .db { font-size:.65rem; color:var(--text-soft); line-height:1.25; margin-top:.2rem; }
.gap-week-wrap { overflow-x:auto; }
.gap-week { width:100%; min-width:720px; border-collapse:separate; border-spacing:3px; }
.gap-week th, .gap-week td { font-size:.72rem; }
.gap-week th { text-align:center; color:var(--text-soft); font-weight:600; padding:.35rem; }
.gap-week .hour { width:3.5rem; text-align:right; padding-right:.5rem; color:var(--text-soft); font-variant-numeric:tabular-nums; }
.gap-week-cell { display:block; min-height:2.1rem; border-radius:.3rem; padding:.25rem .3rem; text-decoration:none; color:inherit; background:var(--panel); border:1px solid var(--border-color); }
.gap-week-cell:hover { border-color:var(--bs-primary); color:inherit; }
.gap-week-cell.tone-ok { background:rgba(27,122,74,.22); border-color:var(--gap-ok); }
.gap-week-cell.tone-warn { background:rgba(184,134,11,.22); border-color:var(--gap-warn); }
.gap-week-cell.tone-missing { background:rgba(179,59,59,.28); border-color:var(--gap-missing); }
.gap-week-cell.tone-duplicate { background:rgba(155,44,90,.28); border-color:var(--gap-dup); }
.gap-week-cell.tone-gap { background:rgba(61,68,80,.45); border-color:var(--gap-gap); }
.gap-week-cell.tone-empty { opacity:.35; }
.gap-week-cell .abbr { font-weight:600; font-size:.68rem; }
.gap-accept-panel { border:1px solid var(--border-color); border-radius:.4rem; background:var(--panel); padding:1rem; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-3 gap-cal">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Gaps</h1>
    <p class="mb-0" style="color:var(--text-soft);font-size:0.8rem;">
      Timeline vs Program/Clean inventory — browse by year, month, week, or day.
      Accept intentional absences (breaking news, specials) so they leave the unfilled count.
    </p>
  </div>
</div>

<form method="get" action="/show-audit" class="card mb-3">
  <div class="card-body py-3">
    <input type="hidden" name="tab" value="<?php echo View::e($tab); ?>">
    <?php if ($tab === 'calendar'): ?>
    <input type="hidden" name="view" value="<?php echo View::e($view); ?>">
    <input type="hidden" name="month" value="<?php echo (int) $month; ?>">
    <input type="hidden" name="date" value="<?php echo View::e($dateIso); ?>">
    <?php endif; ?>
    <div class="row g-2 align-items-end">
      <div class="col-md-2">
        <label class="form-label form-label-sm mb-1">Year</label>
        <select name="year" class="form-select form-select-sm">
          <?php for ($y = 2020; $y <= 2026; $y++): ?>
          <option value="<?php echo $y; ?>"<?php echo $year === $y ? ' selected' : ''; ?>><?php echo $y; ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label form-label-sm mb-1">Coverage</label>
        <select name="mode" class="form-select form-select-sm">
          <?php foreach (['either' => 'Either (P or C)', 'both' => 'Both (P and C)', 'program' => 'Program only', 'clean' => 'Clean only'] as $val => $label): ?>
          <option value="<?php echo View::e($val); ?>"<?php echo $mode === $val ? ' selected' : ''; ?>>
            <?php echo View::e($label); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label form-label-sm mb-1">Show</label>
        <select name="show_id" class="form-select form-select-sm">
          <option value="">All shows</option>
          <?php foreach ($shows as $show): ?>
          <option value="<?php echo (int) $show['id']; ?>"<?php echo $showId === (int) $show['id'] ? ' selected' : ''; ?>>
            <?php echo View::e($show['abbreviation'] . ' — ' . $show['canonical_name']); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($tab !== 'calendar'): ?>
      <div class="col-md-2">
        <label class="form-label form-label-sm mb-1">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?php echo View::e($fromIso); ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label form-label-sm mb-1">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?php echo View::e($toIso); ?>">
      </div>
      <?php endif; ?>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100">Apply</button>
      </div>
    </div>
  </div>
</form>

<div class="row g-2 mb-3">
  <?php
  $cards = [
      ['Unfilled', $metrics['unfilled'] ?? 0, 'Still missing'],
      ['Confirmed', $metrics['confirmed'] ?? 0, 'Clear matches'],
      ['Needs work', ($metrics['needs_split'] ?? 0) + ($metrics['needs_review'] ?? 0), 'Split / review'],
      ['Accepted gaps', $metrics['expected_gap'] ?? 0, 'Excluded'],
      ['Expected slots', $metrics['expected_slots'] ?? 0, 'In this range'],
      ['To identify', $unmatchedTotal, 'Incomplete metadata'],
  ];
  foreach ($cards as [$label, $value, $sub]):
  ?>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card h-100">
      <div class="card-body py-2 px-3">
        <div class="stat-label"><?php echo View::e($label); ?></div>
        <div class="stat-value" style="font-size:1.3rem"><?php echo number_format((int) $value); ?></div>
        <div class="stat-sub"><?php echo View::e($sub); ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<ul class="nav page-tabs mb-3 flex-wrap" role="tablist">
  <?php
  $tabs = [
      'calendar'   => 'Calendar',
      'accepted'   => 'Accepted gaps',
      'duplicates' => 'Duplicates',
      'unmatched'  => 'Unmatched',
  ];
  if (Auth::isAdmin()) {
      $tabs['schedule'] = 'Schedule hygiene';
  }
  foreach ($tabs as $key => $label):
  ?>
  <li class="nav-item">
    <a class="page-tab<?php echo $tab === $key ? ' active' : ''; ?>"
       href="<?php echo View::e($tabUrl($key)); ?>"
       <?php echo $tab === $key ? 'aria-current="page"' : ''; ?>>
      <?php echo View::e($label); ?>
      <?php if ($key === 'duplicates' && count($duplicates) > 0): ?>
        <span class="badge bg-danger ms-1"><?php echo count($duplicates); ?></span>
      <?php endif; ?>
      <?php if ($key === 'unmatched' && $unmatchedTotal > 0): ?>
        <span class="badge bg-secondary ms-1"><?php echo number_format($unmatchedTotal); ?></span>
      <?php endif; ?>
      <?php if ($key === 'accepted' && count($expectedGaps) > 0): ?>
        <span class="badge bg-dark ms-1"><?php echo count($expectedGaps); ?></span>
      <?php endif; ?>
      <?php if ($key === 'schedule' && $openEndedTotal > 0): ?>
        <span class="badge bg-warning text-dark ms-1"><?php echo number_format($openEndedTotal); ?></span>
      <?php endif; ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'calendar'): ?>
  <?php require __DIR__ . '/_calendar.php'; ?>

<?php elseif ($tab === 'accepted'): ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="gap-accept-panel">
      <h2 class="h6 mb-2">Accept expected gap</h2>
      <p class="path-text mb-3" style="font-size:0.78rem">
        Use for breaking news, specials, elections, or other intentional absences.
        Optional end hour flags a contiguous range.
      </p>
      <form method="post" action="/show-audit/gap">
        <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
        <input type="hidden" name="return_tab" value="accepted">
        <input type="hidden" name="return_view" value="<?php echo View::e($view); ?>">
        <input type="hidden" name="return_year" value="<?php echo (int) $year; ?>">
        <input type="hidden" name="return_date" value="<?php echo View::e($dateIso); ?>">
        <input type="hidden" name="return_mode" value="<?php echo View::e($mode); ?>">
        <div class="mb-2">
          <label class="form-label form-label-sm">Show</label>
          <select name="show_id" class="form-select form-select-sm" required>
            <option value="">Select…</option>
            <?php foreach ($shows as $show): ?>
            <option value="<?php echo (int) $show['id']; ?>"><?php echo View::e($show['abbreviation'] . ' — ' . $show['canonical_name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label form-label-sm">Air date</label>
          <input type="date" name="air_date" class="form-control form-control-sm" required value="<?php echo View::e($dateIso); ?>">
        </div>
        <div class="row g-2 mb-2">
          <div class="col-6">
            <label class="form-label form-label-sm">From hour (ET)</label>
            <input type="time" name="hour_start_et" class="form-control form-control-sm" required>
          </div>
          <div class="col-6">
            <label class="form-label form-label-sm">To hour (optional)</label>
            <input type="time" name="hour_end_et" class="form-control form-control-sm">
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label form-label-sm">Lane</label>
          <select name="media_lane" class="form-select form-select-sm">
            <option value="both">Both</option>
            <option value="program">Program</option>
            <option value="clean">Clean</option>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label form-label-sm">Reason</label>
          <input type="text" name="reason" class="form-control form-control-sm" required placeholder="Breaking news">
        </div>
        <div class="mb-3">
          <label class="form-label form-label-sm">Notes</label>
          <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Accept gap</button>
      </form>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">Accepted gaps in range (<?php echo View::e($fromIso); ?> – <?php echo View::e($toIso); ?>)</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead>
            <tr>
              <th>Date</th>
              <th>Hour</th>
              <th>Show</th>
              <th>Lane</th>
              <th>Reason</th>
              <th>By</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($expectedGaps === []): ?>
            <tr><td colspan="7" class="text-soft px-3 py-3">No accepted gaps in this range.</td></tr>
            <?php endif; ?>
            <?php foreach ($expectedGaps as $gap): ?>
            <tr>
              <td><?php echo View::e(substr((string) $gap['air_date'], 0, 10)); ?></td>
              <td><code><?php echo View::e(substr((string) $gap['hour_start_et'], 0, 5)); ?></code></td>
              <td><code><?php echo View::e($gap['show_abbr']); ?></code></td>
              <td><?php echo View::e($gap['media_lane']); ?></td>
              <td>
                <?php echo View::e($gap['reason']); ?>
                <?php if (!empty($gap['notes'])): ?>
                <div class="path-text" style="font-size:0.7rem"><?php echo View::e($gap['notes']); ?></div>
                <?php endif; ?>
              </td>
              <td class="path-text" style="font-size:0.72rem"><?php echo View::e($gap['created_by_email'] ?? '—'); ?></td>
              <td>
                <form method="post" action="/show-audit/gap/delete" class="d-inline"
                      onsubmit="return confirm('Remove this accepted gap?');">
                  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int) $gap['id']; ?>">
                  <input type="hidden" name="return_tab" value="accepted">
                  <input type="hidden" name="return_view" value="<?php echo View::e($view); ?>">
                  <input type="hidden" name="return_year" value="<?php echo (int) $year; ?>">
                  <input type="hidden" name="return_date" value="<?php echo View::e($dateIso); ?>">
                  <input type="hidden" name="return_mode" value="<?php echo View::e($mode); ?>">
                  <button type="submit" class="btn btn-outline-danger btn-xs">Remove</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php elseif ($tab === 'duplicates'): ?>

<div class="card">
  <div class="card-header">
    Duplicate Program/Clean candidates
    <span class="path-text ms-2" style="font-size:0.72rem">
      Same show + date + ±20 min + media type — compare before keeping one.
    </span>
  </div>
  <div class="card-body">
    <?php if ($duplicates === []): ?>
    <p class="text-soft mb-0">No duplicates detected in this range. Open a week or month on Calendar for a tighter audit window.</p>
    <?php endif; ?>
    <?php foreach ($duplicates as $group): ?>
    <div class="border rounded p-3 mb-3" style="border-color:var(--border-color)!important">
      <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
        <div>
          <strong><code><?php echo View::e($group['show_abbr']); ?></code></strong>
          <?php echo View::e($group['show_name']); ?>
          · <?php echo View::e($group['air_date_iso']); ?>
          · <code><?php echo View::e(substr((string) $group['hour_label'], 0, 2) . ':' . substr((string) $group['hour_label'], 2, 2)); ?></code>
          · <span class="badge bg-danger"><?php echo View::e(ucfirst((string) $group['lane'])); ?></span>
        </div>
        <span class="path-text"><?php echo count($group['files']); ?> files</span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead>
            <tr>
              <th></th>
              <th>Filename</th>
              <th>Size</th>
              <th>Duration</th>
              <th>Path</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($group['files'] as $f): ?>
            <tr>
              <td>
                <button type="button" class="btn btn-link p-0 audit-open-preview"
                        data-file-id="<?php echo (int) $f['id']; ?>"
                        data-title="<?php echo View::e($f['original_filename'] ?? ''); ?>"
                        data-meta="<?php echo View::e(json_encode(View::mediaMetaPayload($f), JSON_THROW_ON_ERROR)); ?>">
                  <img src="/queue/thumbnail/<?php echo (int) $f['id']; ?>"
                       alt="" width="72" height="40"
                       style="object-fit:cover;border-radius:4px;background:#111"
                       loading="lazy">
                </button>
              </td>
              <td>
                <?php echo View::e($f['original_filename'] ?? ''); ?>
                <?php if (!empty($f['proposed_filename'])): ?>
                <div class="path-text" style="font-size:0.7rem">→ <?php echo View::e($f['proposed_filename']); ?></div>
                <?php endif; ?>
              </td>
              <td><?php echo View::e(View::filesize(isset($f['filesize_bytes']) ? (int) $f['filesize_bytes'] : null)); ?></td>
              <td><?php echo View::e(View::duration(isset($f['duration_seconds']) ? (float) $f['duration_seconds'] : null)); ?></td>
              <td class="path-text" style="font-size:0.7rem;max-width:280px;word-break:break-all">
                <?php echo View::e($f['original_path'] ?? ''); ?>
              </td>
              <td><span class="badge bg-secondary"><?php echo View::e($f['status'] ?? ''); ?></span></td>
              <td class="text-nowrap">
                <a class="btn btn-primary btn-xs" href="<?php echo View::e($queueLink($f)); ?>">Edit / rename</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php elseif ($tab === 'unmatched'): ?>

<div class="card">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span>Files needing identification</span>
    <form method="get" action="/show-audit" class="d-flex gap-2">
      <input type="hidden" name="tab" value="unmatched">
      <input type="hidden" name="from" value="<?php echo View::e($fromIso); ?>">
      <input type="hidden" name="to" value="<?php echo View::e($toIso); ?>">
      <input type="hidden" name="mode" value="<?php echo View::e($mode); ?>">
      <input type="hidden" name="year" value="<?php echo (int) $year; ?>">
      <?php if ($showId > 0): ?><input type="hidden" name="show_id" value="<?php echo (int) $showId; ?>"><?php endif; ?>
      <input type="search" name="uq" class="form-control form-control-sm" style="width:220px"
             value="<?php echo View::e($unmatchedSearch); ?>" placeholder="Search filename/path">
      <button class="btn btn-outline-secondary btn-sm" type="submit">Search</button>
    </form>
  </div>
  <p class="px-3 pt-3 mb-0 path-text" style="font-size:0.78rem">
    Missing show, date, or time — they cannot be placed on the Timeline.
    Open them in Catalog to set metadata.
  </p>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead>
        <tr>
          <th></th>
          <th>File</th>
          <th>Show</th>
          <th>Type</th>
          <th>Date</th>
          <th>Time</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($unmatchedFiles === []): ?>
        <tr><td colspan="8" class="text-soft px-3 py-3">No unmatched files.</td></tr>
        <?php endif; ?>
        <?php foreach ($unmatchedFiles as $f): ?>
        <tr>
          <td>
            <button type="button" class="btn btn-link p-0 audit-open-preview"
                    data-file-id="<?php echo (int) $f['id']; ?>"
                    data-title="<?php echo View::e($f['original_filename'] ?? ''); ?>"
                    data-meta="<?php echo View::e(json_encode(View::mediaMetaPayload($f), JSON_THROW_ON_ERROR)); ?>">
              <img src="/queue/thumbnail/<?php echo (int) $f['id']; ?>" alt="" width="72" height="40"
                   style="object-fit:cover;border-radius:4px;background:#111" loading="lazy">
            </button>
          </td>
          <td>
            <?php echo View::e($f['original_filename'] ?? ''); ?>
            <div class="path-text" style="font-size:0.7rem;max-width:320px;word-break:break-all">
              <?php echo View::e($f['original_path'] ?? ''); ?>
            </div>
          </td>
          <td><?php echo View::e($f['show_abbr'] ?? '—'); ?></td>
          <td><?php echo View::e($f['media_type_abbr'] ?? '—'); ?></td>
          <td><?php echo View::e($f['file_date'] ?? '—'); ?></td>
          <td><?php echo View::e($f['file_time'] ?? '—'); ?></td>
          <td><span class="badge bg-secondary"><?php echo View::e($f['status'] ?? ''); ?></span></td>
          <td>
            <a class="btn btn-primary btn-xs" href="<?php echo View::e($queueLink($f)); ?>">Identify in Catalog</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($unmatchedPages > 1): ?>
  <div class="card-footer d-flex justify-content-between align-items-center">
    <span class="path-text" style="font-size:0.75rem">
      Page <?php echo (int) $unmatchedPage; ?> of <?php echo (int) $unmatchedPages; ?>
    </span>
    <div class="btn-group btn-group-sm">
      <?php if ($unmatchedPage > 1): ?>
      <a class="btn btn-outline-secondary"
         href="<?php echo View::e('/show-audit?' . http_build_query(array_merge(['tab' => 'unmatched', 'from' => $fromIso, 'to' => $toIso, 'mode' => $mode, 'year' => $year, 'uq' => $unmatchedSearch, 'upage' => $unmatchedPage - 1], $showId > 0 ? ['show_id' => $showId] : []))); ?>">Prev</a>
      <?php endif; ?>
      <?php if ($unmatchedPage < $unmatchedPages): ?>
      <a class="btn btn-outline-secondary"
         href="<?php echo View::e('/show-audit?' . http_build_query(array_merge(['tab' => 'unmatched', 'from' => $fromIso, 'to' => $toIso, 'mode' => $mode, 'year' => $year, 'uq' => $unmatchedSearch, 'upage' => $unmatchedPage + 1], $showId > 0 ? ['show_id' => $showId] : []))); ?>">Next</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'schedule' && Auth::isAdmin()): ?>

<div class="card">
  <div class="card-header">
    Open-ended schedule entries
    <span class="path-text ms-2" style="font-size:0.72rem">
      Replaced shows should end the day before the new show’s start.
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead>
        <tr>
          <th>Show</th>
          <th>Title</th>
          <th>Hours</th>
          <th>From</th>
          <th>Set end date</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($openEnded === []): ?>
        <tr><td colspan="5" class="text-soft px-3 py-3">No open-ended active schedule rows.</td></tr>
        <?php endif; ?>
        <?php foreach ($openEnded as $entry): ?>
        <tr>
          <td><code><?php echo View::e($entry['show_abbr']); ?></code></td>
          <td><?php echo View::e($entry['title']); ?></td>
          <td>
            <code><?php echo View::e(substr((string) $entry['hour_start_et'], 0, 5)); ?>–<?php echo View::e(substr((string) $entry['hour_end_et'], 0, 5)); ?></code>
          </td>
          <td><?php echo View::e(substr((string) $entry['effective_from'], 0, 10)); ?></td>
          <td>
            <form method="post" action="/show-audit/schedule/close" class="d-flex gap-2 align-items-center">
              <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
              <input type="hidden" name="id" value="<?php echo (int) $entry['id']; ?>">
              <input type="hidden" name="return_tab" value="schedule">
              <input type="hidden" name="return_from" value="<?php echo View::e($fromIso); ?>">
              <input type="hidden" name="return_to" value="<?php echo View::e($toIso); ?>">
              <input type="date" name="effective_to" class="form-control form-control-sm" required style="width:150px">
              <button type="submit" class="btn btn-outline-primary btn-xs">Set end</button>
              <a class="btn btn-link btn-xs" href="/schedule?edit=<?php echo (int) $entry['id']; ?>">Full edit</a>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

<!-- Shared media preview modal -->
<div class="modal fade" id="media-preview-modal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content" style="background:var(--panel);border-color:var(--border-color)">
      <div class="modal-header border-secondary py-2">
        <h6 class="modal-title fs-6 mb-0" id="media-preview-title">Preview</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-2 text-center">
        <div id="media-preview-stage">
          <img id="media-preview-image" src="" alt="" class="img-fluid rounded"
               style="max-height:55vh;cursor:pointer" title="Click to play video preview">
          <p class="path-text mt-2 mb-0" style="font-size:0.75rem">
            Click image to load <?php echo (int) $previewDurationMin; ?>-minute preview (with audio).
          </p>
        </div>
        <div id="media-preview-video-wrap" class="d-none">
          <video id="media-preview-video" controls autoplay
                 style="width:100%;max-width:<?php echo (int) $previewWidth; ?>px;max-height:<?php echo (int) round($previewHeight * 1.2); ?>px;background:#000;border-radius:6px">
          </video>
        </div>
        <div id="media-preview-loading" class="d-none py-5">
          <div class="spinner-border spinner-border-sm text-secondary" role="status"></div>
          <span class="ms-2 path-text">Generating preview…</span>
        </div>
        <div id="media-meta-panel" class="text-start mt-3 pt-3" style="border-top:1px solid var(--border-color)">
          <dl id="media-meta-summary" class="row mb-0" style="font-size:0.78rem"></dl>
        </div>
      </div>
    </div>
  </div>
</div>

<?php ob_start(); ?>
<script>
(function () {
    var bs = window.bootstrap;
    if (!bs || !bs.Modal) return;
    var previewModal = document.getElementById('media-preview-modal');
    if (!previewModal) return;
    if (previewModal.parentElement !== document.body) {
        document.body.appendChild(previewModal);
    }
    var modal = bs.Modal.getOrCreateInstance(previewModal);
    var img = document.getElementById('media-preview-image');
    var stage = document.getElementById('media-preview-stage');
    var videoWrap = document.getElementById('media-preview-video-wrap');
    var video = document.getElementById('media-preview-video');
    var loading = document.getElementById('media-preview-loading');
    var titleEl = document.getElementById('media-preview-title');
    var metaSummary = document.getElementById('media-meta-summary');
    var currentId = 0;

    function renderMeta(meta) {
        if (!metaSummary) return;
        metaSummary.innerHTML = '';
        if (!meta) return;
        [['Duration', meta.duration_label || '—'], ['Size', meta.filesize_label || '—'],
         ['Resolution', meta.resolution || '—'], ['Video', meta.codec_video || '—'],
         ['Audio', meta.codec_audio || '—'], ['Container', meta.container || '—']].forEach(function (pair) {
            var dt = document.createElement('dt');
            dt.className = 'col-4 text-soft';
            dt.textContent = pair[0];
            var dd = document.createElement('dd');
            dd.className = 'col-8';
            dd.textContent = pair[1];
            metaSummary.appendChild(dt);
            metaSummary.appendChild(dd);
        });
    }

    function resetVideo() {
        if (video) { video.pause(); video.removeAttribute('src'); video.load(); }
        if (videoWrap) videoWrap.classList.add('d-none');
        if (stage) stage.classList.remove('d-none');
        if (loading) loading.classList.add('d-none');
    }

    document.querySelectorAll('.audit-open-preview').forEach(function (btn) {
        btn.addEventListener('click', function () {
            currentId = parseInt(btn.getAttribute('data-file-id') || '0', 10);
            if (!currentId) return;
            resetVideo();
            if (titleEl) titleEl.textContent = btn.getAttribute('data-title') || 'Preview';
            if (img) img.src = '/queue/thumbnail/' + currentId + '?size=large&t=' + Date.now();
            try { renderMeta(JSON.parse(btn.getAttribute('data-meta') || 'null')); }
            catch (e) { renderMeta(null); }
            modal.show();
        });
    });

    if (img) {
        img.addEventListener('click', function () {
            if (!currentId || !video || !videoWrap || !stage || !loading) return;
            stage.classList.add('d-none');
            loading.classList.remove('d-none');
            videoWrap.classList.add('d-none');
            video.src = '/queue/preview/' + currentId + '?t=' + Date.now();
            video.onloadeddata = function () { loading.classList.add('d-none'); videoWrap.classList.remove('d-none'); };
            video.onerror = function () { loading.classList.add('d-none'); stage.classList.remove('d-none'); };
        });
    }
    previewModal.addEventListener('hidden.bs.modal', resetVideo);

    document.querySelectorAll('[data-gap-prefill]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = document.getElementById('gap-accept-inline');
            if (!form) return;
            var show = btn.getAttribute('data-show-id');
            var date = btn.getAttribute('data-date');
            var hour = btn.getAttribute('data-hour');
            if (show) form.querySelector('[name=show_id]').value = show;
            if (date) form.querySelector('[name=air_date]').value = date;
            if (hour) form.querySelector('[name=hour_start_et]').value = hour;
            form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            var reason = form.querySelector('[name=reason]');
            if (reason) reason.focus();
        });
    });
})();
</script>
<?php
$extraScripts = ($extraScripts ?? '') . ob_get_clean();
?>
