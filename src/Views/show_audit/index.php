<?php

declare(strict_types=1);

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var array<string, mixed> $report */
/** @var list<array<string, mixed>> $unmatchedFiles */
/** @var list<array<string, mixed>> $shows */
/** @var list<array<string, mixed>> $openEnded */
/** @var string $fromIso */
/** @var string $toIso */
/** @var string $mode */
/** @var string $grain */
/** @var string $tab */
/** @var string $status */
/** @var int $showId */
/** @var int $unmatchedTotal */
/** @var int $unmatchedPage */
/** @var int $unmatchedPages */
/** @var string $unmatchedSearch */
/** @var int $openEndedTotal */
/** @var int $previewWidth */
/** @var int $previewHeight */
/** @var int $previewDurationMin */

$metrics = $report['metrics'];
$slots = $report['slots'];
$duplicates = $report['duplicates'];
$expectedGaps = $report['expected_gaps'];
$showRollups = $report['show_rollups'];
$dayRollups = $report['day_rollups'];

$statusBadge = static function (string $status): string {
    $map = [
        'confirmed'    => ['success', 'Confirmed'],
        'needs_split'  => ['warning', 'Needs split'],
        'needs_review' => ['info', 'Needs review'],
        'duplicate'    => ['danger', 'Duplicate'],
        'missing'      => ['secondary', 'Missing'],
        'expected_gap' => ['dark', 'Expected gap'],
    ];
    [$cls, $label] = $map[$status] ?? ['secondary', $status];

    return '<span class="badge bg-' . $cls . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
};

$queryBase = [
    'from' => $fromIso,
    'to'   => $toIso,
    'mode' => $mode,
    'grain'=> $grain,
];
if ($showId > 0) {
    $queryBase['show_id'] = $showId;
}
if ($status !== '') {
    $queryBase['status'] = $status;
}

$tabUrl = static function (string $t) use ($queryBase): string {
    $q = $queryBase;
    $q['tab'] = $t;

    return '/show-audit?' . http_build_query($q);
};

$queueLink = static function (array $file): string {
    $params = [
        'status' => 'ALL',
        'q'      => (string) ($file['original_filename'] ?? ''),
    ];

    return '/queue?' . http_build_query($params);
};
?>

<?php
$workflowStepId = 'gaps';
require dirname(__DIR__) . '/partials/workflow_step.php';
require dirname(__DIR__) . '/shows/_nav.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Gaps</h1>
    <p class="mb-0" style="color:var(--text-soft);font-size:0.8rem;">
      Timeline vs Program/Clean inventory — confirmed coverage, missing hours, duplicates, and files that still need identification.
      Fix matches in Catalog, then re-check here.
    </p>
  </div>
</div>

<form method="get" action="/show-audit" class="card mb-4">
  <div class="card-body py-3">
    <input type="hidden" name="tab" value="<?php echo View::e($tab); ?>">
    <div class="row g-2 align-items-end">
      <div class="col-md-2">
        <label class="form-label form-label-sm mb-1">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?php echo View::e($fromIso); ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label form-label-sm mb-1">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?php echo View::e($toIso); ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label form-label-sm mb-1">Mode</label>
        <select name="mode" class="form-select form-select-sm">
          <?php foreach (['either' => 'Either (P or C)', 'both' => 'Both (P and C)', 'program' => 'Program only', 'clean' => 'Clean only'] as $val => $label): ?>
          <option value="<?php echo View::e($val); ?>"<?php echo $mode === $val ? ' selected' : ''; ?>>
            <?php echo View::e($label); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
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
      <div class="col-md-2">
        <label class="form-label form-label-sm mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All statuses</option>
          <?php foreach (['missing', 'confirmed', 'needs_split', 'needs_review', 'duplicate', 'expected_gap'] as $st): ?>
          <option value="<?php echo View::e($st); ?>"<?php echo $status === $st ? ' selected' : ''; ?>>
            <?php echo View::e(ucwords(str_replace('_', ' ', $st))); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100">Run audit</button>
      </div>
    </div>
  </div>
</form>

<div class="row g-3 mb-4">
  <?php
  $cards = [
      ['Unfilled', $metrics['unfilled'] ?? 0, 'Slots still missing (excl. expected gaps)'],
      ['Confirmed', $metrics['confirmed'] ?? 0, 'Clear single-file matches'],
      ['Needs split', $metrics['needs_split'] ?? 0, 'Content present — split required'],
      ['Needs review', $metrics['needs_review'] ?? 0, '1.5–2.5h — judge content'],
      ['Duplicates', $metrics['duplicate'] ?? 0, 'Same slot, multiple files'],
      ['To identify', $unmatchedTotal, 'Missing show/date/time/type'],
      ['Expected gaps', $metrics['expected_gap'] ?? 0, 'Excluded with a reason'],
      ['Expected slots', $metrics['expected_slots'] ?? 0, 'Schedule hours in range'],
  ];
  foreach ($cards as [$label, $value, $sub]):
  ?>
  <div class="col-6 col-md-3 col-xl">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label"><?php echo View::e($label); ?></div>
        <div class="stat-value" style="font-size:1.45rem"><?php echo number_format((int) $value); ?></div>
        <div class="stat-sub"><?php echo View::e($sub); ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<ul class="nav page-tabs mb-3 flex-wrap" role="tablist">
  <?php
  $tabs = [
      'overview'    => 'Overview',
      'gaps'        => 'Gaps & flags',
      'duplicates'  => 'Duplicates',
      'unmatched'   => 'Unmatched',
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
      <?php if ($key === 'schedule' && $openEndedTotal > 0): ?>
        <span class="badge bg-warning text-dark ms-1"><?php echo number_format($openEndedTotal); ?></span>
      <?php endif; ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'overview'): ?>

<div class="row g-3 mb-4">
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">By show</div>
      <div class="table-responsive" style="max-height:360px;overflow:auto">
        <table class="table table-sm mb-0 align-middle">
          <thead>
            <tr>
              <th>Show</th>
              <th class="text-end">Filled</th>
              <th class="text-end">Unfilled</th>
              <th class="text-end">Expected</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($showRollups === []): ?>
            <tr><td colspan="4" class="text-soft px-3 py-3">No expected slots in this range.</td></tr>
            <?php endif; ?>
            <?php foreach ($showRollups as $row): ?>
            <tr>
              <td>
                <a href="<?php echo View::e('/show-audit?' . http_build_query(array_merge($queryBase, ['tab' => 'overview', 'show_id' => $row['show_id'], 'grain' => 'hourly']))); ?>">
                  <code><?php echo View::e($row['show_abbr']); ?></code>
                </a>
                <div class="path-text" style="font-size:0.72rem"><?php echo View::e($row['show_name']); ?></div>
              </td>
              <td class="text-end"><?php echo number_format((int) $row['filled']); ?></td>
              <td class="text-end"><?php echo number_format((int) $row['unfilled']); ?></td>
              <td class="text-end"><?php echo number_format((int) $row['expected']); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">By day</div>
      <div class="table-responsive" style="max-height:360px;overflow:auto">
        <table class="table table-sm mb-0 align-middle">
          <thead>
            <tr>
              <th>Date</th>
              <th class="text-end">Filled</th>
              <th class="text-end">Unfilled</th>
              <th class="text-end">Needs split</th>
              <th class="text-end">Expected</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($dayRollups === []): ?>
            <tr><td colspan="5" class="text-soft px-3 py-3">No expected slots in this range.</td></tr>
            <?php endif; ?>
            <?php foreach ($dayRollups as $row): ?>
            <tr>
              <td><?php echo View::e($row['air_date_iso']); ?></td>
              <td class="text-end"><?php echo number_format((int) $row['filled']); ?></td>
              <td class="text-end"><?php echo number_format((int) $row['unfilled']); ?></td>
              <td class="text-end"><?php echo number_format((int) $row['needs_split']); ?></td>
              <td class="text-end"><?php echo number_format((int) $row['expected']); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Hourly slots</span>
    <span class="path-text" style="font-size:0.72rem"><?php echo number_format(count($slots)); ?> shown</span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead>
        <tr>
          <th>Date</th>
          <th>Hour</th>
          <th>Show</th>
          <th>Status</th>
          <th>Program</th>
          <th>Clean</th>
          <th>Note</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($slots === []): ?>
        <tr><td colspan="8" class="text-soft px-3 py-4">No slots match these filters. Import/clean the program schedule if expected is zero.</td></tr>
        <?php endif; ?>
        <?php foreach ($slots as $slot): ?>
        <tr>
          <td><?php echo View::e($slot['air_date_iso']); ?></td>
          <td><code><?php echo View::e(substr((string) $slot['hour_label'], 0, 2) . ':' . substr((string) $slot['hour_label'], 2, 2)); ?></code></td>
          <td>
            <code><?php echo View::e($slot['show_abbr']); ?></code>
            <div class="path-text" style="font-size:0.7rem"><?php echo View::e($slot['title']); ?></div>
          </td>
          <td><?php echo $statusBadge((string) $slot['status']); ?></td>
          <td><?php echo $statusBadge((string) $slot['program_status']); ?></td>
          <td><?php echo $statusBadge((string) $slot['clean_status']); ?></td>
          <td class="path-text" style="font-size:0.72rem;max-width:220px">
            <?php echo View::e((string) $slot['note']); ?>
          </td>
          <td class="text-nowrap">
            <?php
            $allFiles = array_merge($slot['program_files'] ?? [], $slot['clean_files'] ?? []);
            foreach (array_slice($allFiles, 0, 3) as $f):
            ?>
            <button type="button" class="btn btn-outline-secondary btn-xs audit-open-preview"
                    data-file-id="<?php echo (int) $f['id']; ?>"
                    data-title="<?php echo View::e($f['original_filename'] ?? ''); ?>"
                    data-meta="<?php echo View::e(json_encode(View::mediaMetaPayload($f), JSON_THROW_ON_ERROR)); ?>">
              Preview
            </button>
            <a class="btn btn-link btn-xs" href="<?php echo View::e($queueLink($f)); ?>">Queue</a>
            <?php endforeach; ?>
            <?php if (($slot['status'] === 'missing' || $slot['program_status'] === 'missing' || $slot['clean_status'] === 'missing')): ?>
            <button type="button" class="btn btn-outline-dark btn-xs"
                    data-bs-toggle="collapse"
                    data-bs-target="#gap-form-<?php echo View::e($slot['air_date'] . '-' . $slot['hour_minutes'] . '-' . $slot['show_id']); ?>">
              Flag gap
            </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php if (($slot['status'] === 'missing' || $slot['program_status'] === 'missing' || $slot['clean_status'] === 'missing')): ?>
        <tr class="collapse" id="gap-form-<?php echo View::e($slot['air_date'] . '-' . $slot['hour_minutes'] . '-' . $slot['show_id']); ?>">
          <td colspan="8" class="bg-body-tertiary">
            <form method="post" action="/show-audit/gap" class="row g-2 align-items-end py-2 px-1">
              <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
              <input type="hidden" name="show_id" value="<?php echo (int) $slot['show_id']; ?>">
              <input type="hidden" name="air_date" value="<?php echo View::e($slot['air_date_iso']); ?>">
              <input type="hidden" name="hour_start_et" value="<?php echo View::e($slot['hour_start_et']); ?>">
              <input type="hidden" name="return_from" value="<?php echo View::e($fromIso); ?>">
              <input type="hidden" name="return_to" value="<?php echo View::e($toIso); ?>">
              <input type="hidden" name="return_mode" value="<?php echo View::e($mode); ?>">
              <input type="hidden" name="return_show_id" value="<?php echo $showId > 0 ? (int) $showId : ''; ?>">
              <input type="hidden" name="return_status" value="<?php echo View::e($status); ?>">
              <input type="hidden" name="return_tab" value="overview">
              <div class="col-md-2">
                <label class="form-label form-label-sm mb-0">Lane</label>
                <select name="media_lane" class="form-select form-select-sm">
                  <option value="both">Both</option>
                  <option value="program">Program</option>
                  <option value="clean">Clean</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label form-label-sm mb-0">Reason</label>
                <input type="text" name="reason" class="form-control form-control-sm" required
                       placeholder="Breaking news / election / special">
              </div>
              <div class="col-md-4">
                <label class="form-label form-label-sm mb-0">Notes</label>
                <input type="text" name="notes" class="form-control form-control-sm" placeholder="Optional detail">
              </div>
              <div class="col-md-3">
                <button type="submit" class="btn btn-dark btn-sm">Exclude as expected gap</button>
              </div>
            </form>
          </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($tab === 'gaps'): ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">Flag expected gap</div>
      <div class="card-body">
        <p class="path-text" style="font-size:0.78rem">
          Use for breaking news, specials, elections, or other intentional absences so they leave the unfilled count.
        </p>
        <form method="post" action="/show-audit/gap">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <input type="hidden" name="return_from" value="<?php echo View::e($fromIso); ?>">
          <input type="hidden" name="return_to" value="<?php echo View::e($toIso); ?>">
          <input type="hidden" name="return_mode" value="<?php echo View::e($mode); ?>">
          <input type="hidden" name="return_tab" value="gaps">
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
            <input type="date" name="air_date" class="form-control form-control-sm" required value="<?php echo View::e($fromIso); ?>">
          </div>
          <div class="mb-2">
            <label class="form-label form-label-sm">Hour (ET)</label>
            <input type="time" name="hour_start_et" class="form-control form-control-sm" required>
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
            <input type="text" name="reason" class="form-control form-control-sm" required placeholder="Election coverage">
          </div>
          <div class="mb-3">
            <label class="form-label form-label-sm">Notes</label>
            <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Save expected gap</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">Expected gaps in range</div>
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
            <tr><td colspan="7" class="text-soft px-3 py-3">No expected gaps flagged in this range.</td></tr>
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
                      onsubmit="return confirm('Remove this expected gap flag?');">
                  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int) $gap['id']; ?>">
                  <input type="hidden" name="return_from" value="<?php echo View::e($fromIso); ?>">
                  <input type="hidden" name="return_to" value="<?php echo View::e($toIso); ?>">
                  <input type="hidden" name="return_mode" value="<?php echo View::e($mode); ?>">
                  <input type="hidden" name="return_tab" value="gaps">
                  <button type="submit" class="btn btn-outline-danger btn-xs">Remove</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header">Missing slots (unfilled)</div>
      <div class="table-responsive" style="max-height:420px;overflow:auto">
        <table class="table table-sm mb-0 align-middle">
          <thead>
            <tr><th>Date</th><th>Hour</th><th>Show</th><th>Program</th><th>Clean</th></tr>
          </thead>
          <tbody>
            <?php
            $missingSlots = array_values(array_filter($slots, static fn (array $s): bool => $s['status'] === 'missing'));
            if ($missingSlots === []):
            ?>
            <tr><td colspan="5" class="text-soft px-3 py-3">No unfilled slots for current filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($missingSlots as $slot): ?>
            <tr>
              <td><?php echo View::e($slot['air_date_iso']); ?></td>
              <td><code><?php echo View::e(substr((string) $slot['hour_label'], 0, 2) . ':' . substr((string) $slot['hour_label'], 2, 2)); ?></code></td>
              <td><code><?php echo View::e($slot['show_abbr']); ?></code></td>
              <td><?php echo $statusBadge((string) $slot['program_status']); ?></td>
              <td><?php echo $statusBadge((string) $slot['clean_status']); ?></td>
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
      Same show + date + ±20 min + media type — compare size, duration, path, and preview before keeping one.
    </span>
  </div>
  <div class="card-body">
    <?php if ($duplicates === []): ?>
    <p class="text-soft mb-0">No duplicates detected in this range.</p>
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
                        data-meta="<?php echo View::e(json_encode(View::mediaMetaPayload($f), JSON_THROW_ON_ERROR)); ?>"
                        title="Open preview">
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
                <div><?php echo View::e($f['source_name'] ?? ''); ?></div>
              </td>
              <td><span class="badge bg-secondary"><?php echo View::e($f['status'] ?? ''); ?></span></td>
              <td class="text-nowrap">
                <button type="button" class="btn btn-outline-secondary btn-xs audit-open-preview"
                        data-file-id="<?php echo (int) $f['id']; ?>"
                        data-title="<?php echo View::e($f['original_filename'] ?? ''); ?>"
                        data-meta="<?php echo View::e(json_encode(View::mediaMetaPayload($f), JSON_THROW_ON_ERROR)); ?>">
                  <?php echo (int) $previewDurationMin; ?>m preview
                </button>
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
      <?php if ($showId > 0): ?><input type="hidden" name="show_id" value="<?php echo (int) $showId; ?>"><?php endif; ?>
      <input type="search" name="uq" class="form-control form-control-sm" style="width:220px"
             value="<?php echo View::e($unmatchedSearch); ?>" placeholder="Search filename/path">
      <button class="btn btn-outline-secondary btn-sm" type="submit">Search</button>
    </form>
  </div>
  <p class="px-3 pt-3 mb-0 path-text" style="font-size:0.78rem">
    Missing show, date, or time — they cannot be placed on the Timeline.
    Open them in the queue to set metadata. Program/Clean files enter the audit once identified;
    ISO/GISO with full metadata are identified but never fill Program/Clean coverage.
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
            <a class="btn btn-primary btn-xs" href="<?php echo View::e($queueLink($f)); ?>">Identify in queue</a>
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
      (<?php echo number_format($unmatchedTotal); ?> files)
    </span>
    <div class="btn-group btn-group-sm">
      <?php if ($unmatchedPage > 1): ?>
      <a class="btn btn-outline-secondary"
         href="<?php echo View::e('/show-audit?' . http_build_query(array_merge($queryBase, ['tab' => 'unmatched', 'uq' => $unmatchedSearch, 'upage' => $unmatchedPage - 1]))); ?>">Prev</a>
      <?php endif; ?>
      <?php if ($unmatchedPage < $unmatchedPages): ?>
      <a class="btn btn-outline-secondary"
         href="<?php echo View::e('/show-audit?' . http_build_query(array_merge($queryBase, ['tab' => 'unmatched', 'uq' => $unmatchedSearch, 'upage' => $unmatchedPage + 1]))); ?>">Next</a>
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
      Replaced shows should end the day before the new show’s start. Leave blank only for shows still in production.
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
  <?php if ($openEndedTotal > count($openEnded)): ?>
  <div class="card-footer path-text" style="font-size:0.75rem">
    Showing <?php echo count($openEnded); ?> of <?php echo number_format($openEndedTotal); ?>.
    Use <a href="/schedule">Program Schedule</a> for bulk edits.
  </div>
  <?php endif; ?>
</div>

<?php endif; ?>

<!-- Shared media preview modal (same endpoints as queue) -->
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
    if (!bs || !bs.Modal) {
        return;
    }
    var previewModal = document.getElementById('media-preview-modal');
    if (!previewModal) {
        return;
    }
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
        var rows = [
            ['Duration', meta.duration_label || '—'],
            ['Size', meta.filesize_label || '—'],
            ['Resolution', meta.resolution || '—'],
            ['Video', meta.codec_video || '—'],
            ['Audio', meta.codec_audio || '—'],
            ['Container', meta.container || '—']
        ];
        rows.forEach(function (pair) {
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
        if (video) {
            video.pause();
            video.removeAttribute('src');
            video.load();
        }
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
            if (img) {
                img.src = '/queue/thumbnail/' + currentId + '?size=large&t=' + Date.now();
            }
            try {
                renderMeta(JSON.parse(btn.getAttribute('data-meta') || 'null'));
            } catch (e) {
                renderMeta(null);
            }
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
            video.onloadeddata = function () {
                loading.classList.add('d-none');
                videoWrap.classList.remove('d-none');
            };
            video.onerror = function () {
                loading.classList.add('d-none');
                stage.classList.remove('d-none');
            };
        });
    }

    previewModal.addEventListener('hidden.bs.modal', resetVideo);
})();
</script>
<?php
$extraScripts = ($extraScripts ?? '') . ob_get_clean();
?>
