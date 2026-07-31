<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var list<array<string, mixed>> $entries */
/** @var list<array<string, mixed>> $shows */
/** @var array<string, mixed>|null $filterShow */
/** @var array<string, mixed>|null $editEntry */
/** @var int $total */
/** @var int $page */
/** @var int $showId */
/** @var string $search */
/** @var array{skipped?: list<string>, warnings?: list<string>}|null $importLog */
/** @var list<array<string, mixed>> $openEnded */
/** @var int $openEndedTotal */
/** @var bool $timelineReady */
/** @var string $timelineReadyAt */

$dayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$dayBits   = [1, 2, 4, 8, 16, 32, 64];
$showAddForm = $editEntry !== null || isset($_GET['add']);
$formEntry = $editEntry ?? [
    'show_id'        => $showId > 0 ? $showId : '',
    'title'          => $filterShow['canonical_name'] ?? '',
    'hour_start_et'  => '09:00:00',
    'hour_end_et'    => '10:00:00',
    'days_of_week'   => 31,
    'effective_from' => date('Y-m-d'),
    'effective_to'   => '',
    'era_name'       => '',
    'anchor_names'   => '',
    'show_type'      => '',
    'network_brand'  => '',
    'notes'          => '',
    'active'         => true,
];
$formMask = (int) ($formEntry['days_of_week'] ?? 31);
?>

<?php
$workflowStepId = 'timeline';
require dirname(__DIR__) . '/partials/workflow_step.php';
require dirname(__DIR__) . '/shows/_nav.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Timeline</h1>
    <p class="mb-0" style="color:var(--text-soft);font-size:0.8rem;">
      <?php if ($filterShow !== null): ?>
      Hourly blocks for
      <a href="/dictionary?edit=<?php echo (int) $filterShow['id']; ?>">
        <?php echo View::e($filterShow['canonical_name']); ?>
      </a>
      (<code><?php echo View::e($filterShow['abbreviation']); ?></code>) —
      <?php echo number_format($total); ?> entries.
      <a href="/schedule" class="ms-1">Show all</a>
      <?php else: ?>
      Historical hourly blocks used to identify shows from date/time.
      <?php echo number_format($total); ?> entries loaded.
      <?php endif; ?>
    </p>
  </div>
  <?php if (!$showAddForm): ?>
  <a href="/schedule?<?php echo http_build_query(array_filter(['show_id' => $showId > 0 ? $showId : null, 'add' => '1'])); ?>"
     class="btn btn-primary btn-sm">Add Entry</a>
  <?php endif; ?>
</div>

<div class="card mb-4" id="hygiene">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span>Schedule hygiene (before Scan)</span>
    <?php if ($timelineReady): ?>
    <span class="badge bg-success">Ready for Scan<?php if ($timelineReadyAt !== ''): ?> · <?php echo View::e($timelineReadyAt); ?> ET<?php endif; ?></span>
    <?php else: ?>
    <span class="badge bg-warning text-dark">Not marked ready</span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <p class="path-text mb-3" style="font-size:0.78rem">
      Open-ended rows (<code>effective_to</code> empty) mean the block is <strong>still current</strong>.
      Close past eras that have ended; keep current shows open-ended. Continuity receives the full active Timeline (past + current).
      Mark ready when hygiene looks good — Scan will prompt if this is skipped.
    </p>
    <?php if ($openEndedTotal > 0): ?>
    <p class="mb-2" style="font-size:0.8rem">
      <span class="badge bg-warning text-dark"><?php echo number_format($openEndedTotal); ?></span>
      open-ended active block(s)
    </p>
    <div class="table-responsive mb-3">
      <table class="table table-sm mb-0">
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
          <?php foreach ($openEnded as $entry): ?>
          <tr>
            <td><code><?php echo View::e((string) ($entry['show_abbr'] ?? '')); ?></code></td>
            <td><?php echo View::e((string) ($entry['title'] ?? '')); ?></td>
            <td>
              <code><?php echo View::e(substr((string) $entry['hour_start_et'], 0, 5)); ?>–<?php echo View::e(substr((string) $entry['hour_end_et'], 0, 5)); ?></code>
            </td>
            <td><?php echo View::e(substr((string) $entry['effective_from'], 0, 10)); ?></td>
            <td>
              <form method="post" action="/schedule/close-end" class="d-flex gap-2 align-items-center">
                <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
                <input type="hidden" name="id" value="<?php echo (int) $entry['id']; ?>">
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
    <p class="path-text mb-3" style="font-size:0.75rem">
      Showing <?php echo count($openEnded); ?> of <?php echo number_format($openEndedTotal); ?>.
    </p>
    <?php endif; ?>
    <?php else: ?>
    <p class="path-text mb-3" style="font-size:0.78rem">No open-ended active schedule rows.</p>
    <?php endif; ?>
    <form method="post" action="/schedule/mark-ready"
          onsubmit="return confirm('Mark Timeline ready for Scan? Open-ended current shows are OK to keep.');">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <button type="submit" class="btn btn-primary btn-sm">Mark Timeline ready for Scan</button>
      <a href="/scan" class="btn btn-outline-secondary btn-sm ms-1">Go to Scan</a>
    </form>
  </div>
</div>

<div class="row g-4 mb-4">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">Import Schedule</div>
      <div class="card-body">
        <form method="post" action="/schedule/import" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <p class="path-text mb-3" style="font-size:0.78rem">
            Default: <code>example_file_trees/newsnation_schedule.xlsx</code><br>
            Accepts .xlsx or .csv · Excel serial dates converted · hourly blocks · skips replays &amp; overnight spans · auto-creates show abbreviations.
          </p>
          <div class="mb-3">
            <label class="form-label">Optional file upload (.xlsx or .csv)</label>
            <input type="file" name="schedule_file" class="form-control form-control-sm" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="replace_existing" id="replace-existing" checked>
            <label class="form-check-label" for="replace-existing">Replace existing schedule entries</label>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Import Schedule</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">Merge Show Abbreviations</div>
      <div class="card-body">
        <form method="post" action="/schedule/merge"
              onsubmit="return confirm('Merge selected shows into the canonical entry? Schedule rows and queue files will be rewired.');">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <p class="path-text mb-3" style="font-size:0.78rem">
            Consolidate auto-generated schedule shows into dictionary entries (e.g. merge <code>CUOMO</code> duplicates).
          </p>
          <div class="row g-2">
            <div class="col-md-5">
              <label class="form-label">Keep (canonical)</label>
              <select name="canonical_id" class="form-select form-select-sm" required>
                <option value="">—</option>
                <?php foreach ($shows as $show): ?>
                <option value="<?php echo (int) $show['id']; ?>">
                  <?php echo View::e($show['abbreviation']); ?> — <?php echo View::e($show['canonical_name']); ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-7">
              <label class="form-label">Merge into canonical (select one or more)</label>
              <select name="absorbed_ids[]" class="form-select form-select-sm" multiple size="6" required>
                <?php foreach ($shows as $show): ?>
                <option value="<?php echo (int) $show['id']; ?>">
                  <?php echo View::e($show['abbreviation']); ?> — <?php echo View::e($show['canonical_name']); ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <button type="submit" class="btn btn-outline-warning btn-sm mt-3">Merge Shows</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if ($importLog !== null && (!empty($importLog['skipped']) || !empty($importLog['warnings']))): ?>
<div class="card mb-4">
  <div class="card-header">Import Log</div>
  <div class="card-body py-2" style="font-size:0.78rem;max-height:200px;overflow:auto">
    <?php foreach ($importLog['skipped'] ?? [] as $line): ?>
    <div class="path-text"><?php echo View::e($line); ?></div>
    <?php endforeach; ?>
    <?php foreach ($importLog['warnings'] ?? [] as $line): ?>
    <div class="text-warning"><?php echo View::e($line); ?></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($showAddForm): ?>
<div class="card mb-4">
  <div class="card-header"><?php echo $editEntry ? 'Edit Schedule Entry' : 'Add Schedule Entry'; ?></div>
  <div class="card-body">
    <form method="post" action="<?php echo $editEntry ? '/schedule/update' : '/schedule/create'; ?>">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <?php if ($editEntry): ?>
      <input type="hidden" name="id" value="<?php echo (int) $editEntry['id']; ?>">
      <?php endif; ?>
      <?php if ($showId > 0): ?>
      <input type="hidden" name="return_show_id" value="<?php echo $showId; ?>">
      <?php endif; ?>
      <?php if ($search !== ''): ?>
      <input type="hidden" name="return_q" value="<?php echo View::e($search); ?>">
      <?php endif; ?>

      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Show</label>
          <select name="show_id" class="form-select form-select-sm" required>
            <option value="">—</option>
            <?php foreach ($shows as $show): ?>
            <option value="<?php echo (int) $show['id']; ?>"
              <?php echo (int) ($formEntry['show_id'] ?? 0) === (int) $show['id'] ? 'selected' : ''; ?>>
              <?php echo View::e($show['abbreviation']); ?> — <?php echo View::e($show['canonical_name']); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-8">
          <label class="form-label">Title</label>
          <input type="text" name="title" class="form-control form-control-sm" required
                 value="<?php echo View::e((string) ($formEntry['title'] ?? '')); ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Hour start (ET)</label>
          <input type="time" name="hour_start_et" class="form-control form-control-sm" required
                 value="<?php echo View::e(substr((string) ($formEntry['hour_start_et'] ?? '09:00:00'), 0, 5)); ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Hour end (ET)</label>
          <input type="time" name="hour_end_et" class="form-control form-control-sm" required
                 value="<?php echo View::e(substr((string) ($formEntry['hour_end_et'] ?? '10:00:00'), 0, 5)); ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Days</label>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach ($dayBits as $i => $bit): ?>
            <label class="form-check form-check-inline mb-0" style="font-size:0.78rem">
              <input class="form-check-input" type="checkbox" name="days[]" value="<?php echo $bit; ?>"
                <?php echo ($formMask & $bit) !== 0 ? 'checked' : ''; ?>>
              <?php echo $dayLabels[$i]; ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="col-md-2">
          <label class="form-label">Effective from</label>
          <input type="date" name="effective_from" class="form-control form-control-sm" required
                 value="<?php echo View::e((string) ($formEntry['effective_from'] ?? '')); ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Effective to</label>
          <input type="date" name="effective_to" class="form-control form-control-sm"
                 value="<?php echo View::e((string) ($formEntry['effective_to'] ?? '')); ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Era</label>
          <input type="text" name="era_name" class="form-control form-control-sm"
                 value="<?php echo View::e((string) ($formEntry['era_name'] ?? '')); ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Anchors</label>
          <input type="text" name="anchor_names" class="form-control form-control-sm"
                 value="<?php echo View::e((string) ($formEntry['anchor_names'] ?? '')); ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Notes</label>
          <input type="text" name="notes" class="form-control form-control-sm"
                 value="<?php echo View::e((string) ($formEntry['notes'] ?? '')); ?>">
        </div>
        <div class="col-md-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="active" id="entry-active"
              <?php echo !empty($formEntry['active']) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="entry-active">Active</label>
          </div>
        </div>
      </div>
      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary btn-sm">
          <?php echo $editEntry ? 'Save Changes' : 'Add Entry'; ?>
        </button>
        <a href="/schedule<?php echo $showId > 0 || $search !== '' ? '?' . http_build_query(array_filter(['show_id' => $showId > 0 ? $showId : null, 'q' => $search !== '' ? $search : null])) : ''; ?>"
           class="btn btn-outline-secondary btn-sm">Cancel</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<form method="get" action="/schedule" class="card mb-3">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <?php if ($showId > 0): ?>
      <input type="hidden" name="show_id" value="<?php echo $showId; ?>">
      <?php endif; ?>
      <div class="col-md-8">
        <input type="text" name="q" class="form-control form-control-sm" value="<?php echo View::e($search); ?>"
               placeholder="Search title, abbreviation…">
      </div>
      <div class="col-md-4">
        <button type="submit" class="btn btn-primary btn-sm w-100">Search</button>
      </div>
    </div>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0">
      <thead>
        <tr>
          <th>Show</th>
          <th>Title</th>
          <th>Hour (ET)</th>
          <th>Days</th>
          <th>From</th>
          <th>To</th>
          <th>Era</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($entries === []): ?>
        <tr>
          <td colspan="8" class="text-center py-4" style="color:var(--text-soft)">
            No schedule entries — import the CSV to get started.
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($entries as $row): ?>
        <tr>
          <td class="text-nowrap">
            <a href="/dictionary?edit=<?php echo (int) $row['show_id']; ?>" class="badge bg-secondary text-decoration-none">
              <?php echo View::e($row['show_abbr']); ?>
            </a>
          </td>
          <td><?php echo View::e($row['title']); ?></td>
          <td class="text-nowrap path-text">
            <?php echo View::e(substr((string) $row['hour_start_et'], 0, 5)); ?>–<?php echo View::e(substr((string) $row['hour_end_et'], 0, 5)); ?>
          </td>
          <td class="path-text" style="font-size:0.72rem">
            <?php
            $mask = (int) ($row['days_of_week'] ?? 0);
            $parts = [];
            foreach ($dayBits as $i => $bit) {
                if (($mask & $bit) !== 0) {
                    $parts[] = $dayLabels[$i];
                }
            }
            echo View::e($parts !== [] ? implode(',', $parts) : '—');
            ?>
          </td>
          <td class="path-text"><?php echo View::e($row['effective_from']); ?></td>
          <td class="path-text"><?php echo View::e($row['effective_to'] ?? '—'); ?></td>
          <td class="path-text" style="font-size:0.72rem;max-width:160px"><?php echo View::e($row['era_name']); ?></td>
          <td class="text-end text-nowrap">
            <a href="/schedule?edit=<?php echo (int) $row['id']; ?><?php echo $showId > 0 ? '&amp;show_id=' . $showId : ''; ?>"
               class="btn btn-outline-secondary btn-xs">Edit</a>
            <form method="post" action="/schedule/delete" class="d-inline"
                  onsubmit="return confirm('Delete this schedule entry?');">
              <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
              <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
              <?php if ($showId > 0): ?>
              <input type="hidden" name="return_show_id" value="<?php echo $showId; ?>">
              <?php endif; ?>
              <?php if ($search !== ''): ?>
              <input type="hidden" name="return_q" value="<?php echo View::e($search); ?>">
              <?php endif; ?>
              <button type="submit" class="btn btn-outline-danger btn-xs">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
