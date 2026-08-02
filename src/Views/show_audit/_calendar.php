<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var callable $calUrl */
/** @var callable $statusBadge */
/** @var callable $queueLink */
/** @var array<string, mixed> $report */
/** @var list<array<string, mixed>> $monthTiles */
/** @var list<array<string, mixed>> $slots */
/** @var list<array<string, mixed>> $showRollups */
/** @var list<array<string, mixed>> $shows */
/** @var list<list<?array<string, mixed>>> $monthGrid */
/** @var array<string, mixed>|null $weekGrid */
/** @var string $view */
/** @var string $mode */
/** @var string $dateIso */
/** @var string $fromIso */
/** @var string $toIso */
/** @var int $year */
/** @var int $month */
/** @var int $showId */
/** @var array<int, string> $monthNames */
/** @var \DateTimeImmutable $weekStart */
/** @var \DateTimeImmutable $focusDate */

$prevYear = $year - 1;
$nextYear = $year + 1;
$monthStart = \DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $year, $month));
$prevMonth = $monthStart->modify('-1 month');
$nextMonth = $monthStart->modify('+1 month');
$prevWeek = $weekStart->modify('-7 days');
$nextWeek = $weekStart->modify('+7 days');
$prevDay = $focusDate->modify('-1 day');
$nextDay = $focusDate->modify('+1 day');
?>

<div class="gap-legend">
  <span class="lg-ok">Confirmed</span>
  <span class="lg-warn">Needs split / review</span>
  <span class="lg-missing">Missing</span>
  <span class="lg-dup">Duplicate</span>
  <span class="lg-gap">Accepted gap</span>
  <span class="lg-empty">No schedule</span>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <nav class="gap-crumb" aria-label="Calendar breadcrumb">
    <a href="<?php echo View::e($calUrl(['view' => 'year', 'year' => $year])); ?>"><?php echo (int) $year; ?></a>
    <?php if (in_array($view, ['month', 'week', 'day', 'show'], true)): ?>
      <span class="mx-1">/</span>
      <a href="<?php echo View::e($calUrl(['view' => 'month', 'year' => $year, 'month' => $month, 'date' => sprintf('%04d-%02d-01', $year, $month)])); ?>">
        <?php echo View::e($monthNames[$month] ?? (string) $month); ?>
      </a>
    <?php endif; ?>
    <?php if ($view === 'week'): ?>
      <span class="mx-1">/</span>
      <span>Week of <?php echo View::e($weekStart->format('M j')); ?></span>
    <?php elseif ($view === 'day'): ?>
      <span class="mx-1">/</span>
      <a href="<?php echo View::e($calUrl(['view' => 'week', 'date' => $weekStart->format('Y-m-d'), 'year' => $year])); ?>">
        Week of <?php echo View::e($weekStart->format('M j')); ?>
      </a>
      <span class="mx-1">/</span>
      <span><?php echo View::e($focusDate->format('D M j')); ?></span>
    <?php elseif ($view === 'show'): ?>
      <span class="mx-1">/</span>
      <span>Show runway</span>
    <?php endif; ?>
  </nav>

  <div class="d-flex flex-wrap gap-1">
    <a class="btn btn-outline-secondary btn-sm<?php echo $view === 'year' ? ' active' : ''; ?>"
       href="<?php echo View::e($calUrl(['view' => 'year', 'year' => $year])); ?>">Year</a>
    <a class="btn btn-outline-secondary btn-sm<?php echo $view === 'month' ? ' active' : ''; ?>"
       href="<?php echo View::e($calUrl(['view' => 'month', 'year' => $year, 'month' => $month, 'date' => sprintf('%04d-%02d-01', $year, $month)])); ?>">Month</a>
    <a class="btn btn-outline-secondary btn-sm<?php echo $view === 'week' ? ' active' : ''; ?>"
       href="<?php echo View::e($calUrl(['view' => 'week', 'date' => $weekStart->format('Y-m-d'), 'year' => $year])); ?>">Week</a>
    <a class="btn btn-outline-secondary btn-sm<?php echo $view === 'day' ? ' active' : ''; ?>"
       href="<?php echo View::e($calUrl(['view' => 'day', 'date' => $dateIso, 'year' => $year])); ?>">Day</a>
    <a class="btn btn-outline-secondary btn-sm<?php echo $view === 'show' ? ' active' : ''; ?>"
       href="<?php echo View::e($calUrl(['view' => 'show', 'year' => $year])); ?>">Show runway</a>
  </div>
</div>

<?php if ($view === 'year'): ?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div class="btn-group btn-group-sm">
    <a class="btn btn-outline-secondary" href="<?php echo View::e($calUrl(['view' => 'year', 'year' => $prevYear])); ?>">← <?php echo $prevYear; ?></a>
    <a class="btn btn-outline-secondary" href="<?php echo View::e($calUrl(['view' => 'year', 'year' => $nextYear])); ?>"><?php echo $nextYear; ?> →</a>
  </div>
  <div class="path-text" style="font-size:0.78rem">
    <?php echo number_format((int) ($report['metrics']['expected_slots'] ?? 0)); ?> expected slots in <?php echo (int) $year; ?>
    · <?php echo number_format((int) ($report['metrics']['unfilled'] ?? 0)); ?> missing
  </div>
</div>

<div class="gap-year-grid mb-4">
  <?php foreach ($monthTiles as $tile): ?>
  <a class="gap-month-tile tone-<?php echo View::e((string) $tile['tone']); ?>"
     href="<?php echo View::e($calUrl([
         'view'  => 'month',
         'year'  => $year,
         'month' => (int) $tile['month'],
         'date'  => sprintf('%04d-%02d-01', $year, (int) $tile['month']),
     ])); ?>">
    <div class="name"><?php echo View::e((string) $tile['label']); ?></div>
    <div class="pct">
      <?php if ($tile['pct_filled'] === null): ?>
        —
      <?php else: ?>
        <?php echo (int) $tile['pct_filled']; ?>%
      <?php endif; ?>
    </div>
    <div class="brief"><?php echo View::e((string) $tile['brief']); ?></div>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($showRollups !== []): ?>
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>By show in <?php echo (int) $year; ?></span>
    <span class="path-text" style="font-size:0.72rem">Open runway for a continuous slot list</span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead>
        <tr>
          <th>Show</th>
          <th class="text-end">Filled</th>
          <th class="text-end">Missing</th>
          <th class="text-end">Accepted</th>
          <th class="text-end">Expected</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($showRollups as $row): ?>
        <tr>
          <td>
            <code><?php echo View::e($row['show_abbr']); ?></code>
            <div class="path-text" style="font-size:0.72rem"><?php echo View::e($row['show_name']); ?></div>
          </td>
          <td class="text-end"><?php echo number_format((int) $row['filled']); ?></td>
          <td class="text-end"><?php echo number_format((int) $row['unfilled']); ?></td>
          <td class="text-end"><?php echo number_format((int) $row['expected_gap']); ?></td>
          <td class="text-end"><?php echo number_format((int) $row['expected']); ?></td>
          <td class="text-end">
            <a class="btn btn-outline-secondary btn-xs"
               href="<?php echo View::e($calUrl(['view' => 'show', 'year' => $year, 'show_id' => (int) $row['show_id']])); ?>">
              Runway
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php elseif ($view === 'month'): ?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div class="btn-group btn-group-sm">
    <a class="btn btn-outline-secondary" href="<?php echo View::e($calUrl([
        'view' => 'month', 'year' => (int) $prevMonth->format('Y'), 'month' => (int) $prevMonth->format('n'),
        'date' => $prevMonth->format('Y-m-d'),
    ])); ?>">← <?php echo View::e($prevMonth->format('M Y')); ?></a>
    <a class="btn btn-outline-secondary" href="<?php echo View::e($calUrl([
        'view' => 'month', 'year' => (int) $nextMonth->format('Y'), 'month' => (int) $nextMonth->format('n'),
        'date' => $nextMonth->format('Y-m-d'),
    ])); ?>"><?php echo View::e($nextMonth->format('M Y')); ?> →</a>
  </div>
  <h2 class="h5 mb-0"><?php echo View::e(($monthNames[$month] ?? '') . ' ' . $year); ?></h2>
</div>

<div class="card mb-3">
  <div class="card-body p-2">
    <table class="gap-month-cal">
      <thead>
        <tr>
          <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow): ?>
          <th><?php echo $dow; ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($monthGrid as $week): ?>
        <tr>
          <?php foreach ($week as $cell): ?>
          <td>
            <?php
            $tone = (string) ($cell['tone'] ?? 'empty');
            $iso = (string) ($cell['air_date_iso'] ?? '');
            $inMonth = !empty($cell['in_month']);
            ?>
            <?php if ($inMonth): ?>
            <a class="gap-day-cell tone-<?php echo View::e($tone); ?>"
               href="<?php echo View::e($calUrl(['view' => 'day', 'date' => $iso, 'year' => $year])); ?>"
               title="<?php echo View::e((string) ($cell['brief'] ?? '')); ?>">
              <div class="dn"><?php echo (int) ($cell['day_num'] ?? 0); ?></div>
              <div class="db"><?php echo View::e((string) ($cell['brief'] ?? '')); ?></div>
            </a>
            <?php else: ?>
            <div class="gap-day-cell tone-outside">
              <div class="dn"><?php echo (int) ($cell['day_num'] ?? 0); ?></div>
            </div>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<p class="path-text mb-0" style="font-size:0.78rem">
  Tip: open a day to accept breaking-news gaps, or switch to
  <a href="<?php echo View::e($calUrl(['view' => 'week', 'date' => $dateIso, 'year' => $year])); ?>">week view</a>
  for the Mon–Sun hour grid.
</p>

<?php elseif ($view === 'week' && is_array($weekGrid)): ?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div class="btn-group btn-group-sm">
    <a class="btn btn-outline-secondary" href="<?php echo View::e($calUrl([
        'view' => 'week', 'date' => $prevWeek->format('Y-m-d'), 'year' => (int) $prevWeek->format('Y'),
    ])); ?>">← Prev week</a>
    <a class="btn btn-outline-secondary" href="<?php echo View::e($calUrl([
        'view' => 'week', 'date' => $nextWeek->format('Y-m-d'), 'year' => (int) $nextWeek->format('Y'),
    ])); ?>">Next week →</a>
  </div>
  <h2 class="h5 mb-0">
    Week of <?php echo View::e($weekStart->format('M j, Y')); ?>
    <span class="path-text" style="font-size:0.8rem;font-weight:400">
      (<?php echo View::e($weekGrid['week_start']); ?> – <?php echo View::e($weekGrid['week_end']); ?>)
    </span>
  </h2>
</div>

<div class="card mb-3">
  <div class="card-body p-2 gap-week-wrap">
    <table class="gap-week">
      <thead>
        <tr>
          <th class="hour">ET</th>
          <?php foreach ($weekGrid['days'] as $day): ?>
          <th>
            <a href="<?php echo View::e($calUrl(['view' => 'day', 'date' => $day['iso'], 'year' => $year])); ?>"
               class="text-decoration-none" style="color:inherit">
              <?php echo View::e($day['dow']); ?><br>
              <span style="font-weight:400"><?php echo View::e($day['label']); ?></span>
            </a>
          </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($weekGrid['hours'] as $hourMin): ?>
        <tr>
          <td class="hour"><code><?php echo View::e(sprintf('%02d:00', intdiv((int) $hourMin, 60))); ?></code></td>
          <?php foreach ($weekGrid['days'] as $day): ?>
          <?php
            $key = $day['ymd'] . '|' . $hourMin;
            $cell = $weekGrid['cells'][$key] ?? null;
          ?>
          <td>
            <?php if ($cell === null): ?>
            <div class="gap-week-cell tone-empty" title="No scheduled show"></div>
            <?php else: ?>
            <a class="gap-week-cell tone-<?php echo View::e((string) ($cell['tone'] ?? 'empty')); ?>"
               href="<?php echo View::e($calUrl(['view' => 'day', 'date' => $day['iso'], 'year' => $year])); ?>"
               title="<?php echo View::e(($cell['count'] ?? 0) . ' slot(s) — ' . ($cell['status'] ?? '')); ?>">
              <?php
              $first = $cell['slots'][0] ?? null;
              if (is_array($first)):
              ?>
              <span class="abbr"><?php echo View::e((string) $first['show_abbr']); ?></span>
              <?php if ((int) ($cell['count'] ?? 0) > 1): ?>
              <span class="path-text">+<?php echo (int) $cell['count'] - 1; ?></span>
              <?php endif; ?>
              <?php endif; ?>
            </a>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<p class="path-text" style="font-size:0.78rem">
  Empty cells have no Timeline expectation. Colored cells are scheduled hours — click through to the day to accept gaps or open Catalog matches.
</p>

<?php elseif ($view === 'day'): ?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div class="btn-group btn-group-sm">
    <a class="btn btn-outline-secondary" href="<?php echo View::e($calUrl([
        'view' => 'day', 'date' => $prevDay->format('Y-m-d'), 'year' => (int) $prevDay->format('Y'),
    ])); ?>">← <?php echo View::e($prevDay->format('D M j')); ?></a>
    <a class="btn btn-outline-secondary" href="<?php echo View::e($calUrl([
        'view' => 'day', 'date' => $nextDay->format('Y-m-d'), 'year' => (int) $nextDay->format('Y'),
    ])); ?>"><?php echo View::e($nextDay->format('D M j')); ?> →</a>
  </div>
  <h2 class="h5 mb-0"><?php echo View::e($focusDate->format('l, F j, Y')); ?></h2>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Hourly slots</span>
        <span class="path-text" style="font-size:0.72rem"><?php echo number_format(count($slots)); ?> scheduled</span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead>
            <tr>
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
            <tr><td colspan="7" class="text-soft px-3 py-4">No Timeline slots for this day (check Eras / schedule).</td></tr>
            <?php endif; ?>
            <?php foreach ($slots as $slot): ?>
            <tr>
              <td><code><?php echo View::e(substr((string) $slot['hour_label'], 0, 2) . ':' . substr((string) $slot['hour_label'], 2, 2)); ?></code></td>
              <td>
                <code><?php echo View::e($slot['show_abbr']); ?></code>
                <div class="path-text" style="font-size:0.7rem"><?php echo View::e($slot['title']); ?></div>
              </td>
              <td><?php echo $statusBadge((string) $slot['status']); ?></td>
              <td><?php echo $statusBadge((string) $slot['program_status']); ?></td>
              <td><?php echo $statusBadge((string) $slot['clean_status']); ?></td>
              <td class="path-text" style="font-size:0.72rem;max-width:200px"><?php echo View::e((string) $slot['note']); ?></td>
              <td class="text-nowrap">
                <?php
                $allFiles = array_merge($slot['program_files'] ?? [], $slot['clean_files'] ?? []);
                foreach (array_slice($allFiles, 0, 2) as $f):
                ?>
                <button type="button" class="btn btn-outline-secondary btn-xs audit-open-preview"
                        data-file-id="<?php echo (int) $f['id']; ?>"
                        data-title="<?php echo View::e($f['original_filename'] ?? ''); ?>"
                        data-meta="<?php echo View::e(json_encode(View::mediaMetaPayload($f), JSON_THROW_ON_ERROR)); ?>">
                  Preview
                </button>
                <a class="btn btn-link btn-xs" href="<?php echo View::e($queueLink($f)); ?>">Catalog</a>
                <?php endforeach; ?>
                <?php if (($slot['status'] === 'missing' || $slot['program_status'] === 'missing' || $slot['clean_status'] === 'missing')): ?>
                <button type="button" class="btn btn-outline-dark btn-xs"
                        data-gap-prefill
                        data-show-id="<?php echo (int) $slot['show_id']; ?>"
                        data-date="<?php echo View::e($slot['air_date_iso']); ?>"
                        data-hour="<?php echo View::e(substr((string) $slot['hour_start_et'], 0, 5)); ?>">
                  Accept gap
                </button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="gap-accept-panel" id="gap-accept-inline">
      <h2 class="h6 mb-2">Accept expected gap</h2>
      <p class="path-text mb-3" style="font-size:0.78rem">
        Mark intentional absences (breaking news, specials). Optional end hour covers a range.
      </p>
      <form method="post" action="/show-audit/gap">
        <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
        <input type="hidden" name="return_tab" value="calendar">
        <input type="hidden" name="return_view" value="day">
        <input type="hidden" name="return_year" value="<?php echo (int) $year; ?>">
        <input type="hidden" name="return_date" value="<?php echo View::e($dateIso); ?>">
        <input type="hidden" name="return_mode" value="<?php echo View::e($mode); ?>">
        <?php if ($showId > 0): ?>
        <input type="hidden" name="return_show_id" value="<?php echo (int) $showId; ?>">
        <?php endif; ?>
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
            <label class="form-label form-label-sm">From</label>
            <input type="time" name="hour_start_et" class="form-control form-control-sm" required>
          </div>
          <div class="col-6">
            <label class="form-label form-label-sm">To (opt.)</label>
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
        <button type="submit" class="btn btn-dark btn-sm">Accept gap</button>
      </form>
    </div>
  </div>
</div>

<?php else: /* show runway */ ?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <h2 class="h5 mb-0">Show runway — <?php echo (int) $year; ?></h2>
  <div class="btn-group btn-group-sm">
    <a class="btn btn-outline-secondary" href="<?php echo View::e($calUrl(['view' => 'show', 'year' => $prevYear, 'show_id' => $showId > 0 ? $showId : null])); ?>">← <?php echo $prevYear; ?></a>
    <a class="btn btn-outline-secondary" href="<?php echo View::e($calUrl(['view' => 'show', 'year' => $nextYear, 'show_id' => $showId > 0 ? $showId : null])); ?>"><?php echo $nextYear; ?> →</a>
  </div>
</div>

<?php if ($showId <= 0): ?>
<div class="card">
  <div class="card-body">
    <p class="mb-3 path-text" style="font-size:0.85rem">
      Pick a show to see every scheduled hour from start to finish with gap status.
    </p>
    <div class="d-flex flex-wrap gap-2">
      <?php foreach ($showRollups as $row): ?>
      <a class="btn btn-outline-secondary btn-sm"
         href="<?php echo View::e($calUrl(['view' => 'show', 'year' => $year, 'show_id' => (int) $row['show_id']])); ?>">
        <code><?php echo View::e($row['show_abbr']); ?></code>
        <span class="ms-1 path-text"><?php echo number_format((int) $row['unfilled']); ?> missing</span>
      </a>
      <?php endforeach; ?>
      <?php if ($showRollups === []): ?>
      <p class="text-soft mb-0">No scheduled shows in <?php echo (int) $year; ?>. Check Timeline / Eras.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php else: ?>

<?php
$runwayShow = null;
foreach ($showRollups as $row) {
    if ((int) $row['show_id'] === $showId) {
        $runwayShow = $row;
        break;
    }
}
?>
<div class="card mb-3">
  <div class="card-header d-flex flex-wrap justify-content-between gap-2">
    <span>
      <?php if ($runwayShow): ?>
      <code><?php echo View::e($runwayShow['show_abbr']); ?></code>
      <?php echo View::e($runwayShow['show_name']); ?>
      <?php else: ?>
      Show #<?php echo (int) $showId; ?>
      <?php endif; ?>
    </span>
    <span class="path-text" style="font-size:0.72rem">
      <?php echo number_format(count($slots)); ?> slots
      <?php if ($runwayShow): ?>
      · <?php echo number_format((int) $runwayShow['unfilled']); ?> missing
      · <?php echo number_format((int) $runwayShow['filled']); ?> filled
      <?php endif; ?>
    </span>
  </div>
  <div class="table-responsive" style="max-height:70vh;overflow:auto">
    <table class="table table-sm mb-0 align-middle">
      <thead class="sticky-top" style="background:var(--panel)">
        <tr>
          <th>Date</th>
          <th>Hour</th>
          <th>Status</th>
          <th>Program</th>
          <th>Clean</th>
          <th>Note</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($slots === []): ?>
        <tr><td colspan="7" class="text-soft px-3 py-4">No slots for this show in <?php echo (int) $year; ?>.</td></tr>
        <?php endif; ?>
        <?php foreach ($slots as $slot): ?>
        <tr<?php echo $slot['status'] === 'missing' ? ' class="table-danger"' : ''; ?>>
          <td>
            <a href="<?php echo View::e($calUrl(['view' => 'day', 'date' => $slot['air_date_iso'], 'year' => $year])); ?>">
              <?php echo View::e($slot['air_date_iso']); ?>
            </a>
          </td>
          <td><code><?php echo View::e(substr((string) $slot['hour_label'], 0, 2) . ':' . substr((string) $slot['hour_label'], 2, 2)); ?></code></td>
          <td><?php echo $statusBadge((string) $slot['status']); ?></td>
          <td><?php echo $statusBadge((string) $slot['program_status']); ?></td>
          <td><?php echo $statusBadge((string) $slot['clean_status']); ?></td>
          <td class="path-text" style="font-size:0.72rem;max-width:220px"><?php echo View::e((string) $slot['note']); ?></td>
          <td class="text-nowrap">
            <?php if ($slot['status'] === 'missing'): ?>
            <a class="btn btn-outline-dark btn-xs"
               href="<?php echo View::e($calUrl(['view' => 'day', 'date' => $slot['air_date_iso'], 'year' => $year])); ?>">
              Accept / fix
            </a>
            <?php else: ?>
            <?php
            $allFiles = array_merge($slot['program_files'] ?? [], $slot['clean_files'] ?? []);
            $f = $allFiles[0] ?? null;
            if ($f):
            ?>
            <a class="btn btn-link btn-xs" href="<?php echo View::e($queueLink($f)); ?>">Catalog</a>
            <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>
