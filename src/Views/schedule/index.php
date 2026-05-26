<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var list<array<string, mixed>> $entries */
/** @var list<array<string, mixed>> $shows */
/** @var int $total */
/** @var int $page */
/** @var string $search */
/** @var array{skipped?: list<string>, warnings?: list<string>}|null $importLog */

$dayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$dayBits   = [1, 2, 4, 8, 16, 32, 64];
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Program Schedule</h1>
    <p class="mb-0" style="color:var(--text-soft);font-size:0.8rem;">
      Historical hourly blocks used to identify shows from date/time. <?php echo number_format($total); ?> entries loaded.
    </p>
  </div>
</div>

<div class="row g-4 mb-4">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">Import Schedule CSV</div>
      <div class="card-body">
        <form method="post" action="/schedule/import" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <p class="path-text mb-3" style="font-size:0.78rem">
            Default: <code>example_file_trees/newsnation_schedule.csv</code><br>
            Hourly blocks · skips replays &amp; overnight spans · auto-creates show abbreviations.
          </p>
          <div class="mb-3">
            <label class="form-label">Optional CSV upload</label>
            <input type="file" name="csv_file" class="form-control form-control-sm" accept=".csv,text/csv">
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

<form method="get" action="/schedule" class="card mb-3">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
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
        </tr>
      </thead>
      <tbody>
        <?php if ($entries === []): ?>
        <tr>
          <td colspan="7" class="text-center py-4" style="color:var(--text-soft)">
            No schedule entries — import the CSV to get started.
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($entries as $row): ?>
        <tr>
          <td class="text-nowrap">
            <span class="badge bg-secondary"><?php echo View::e($row['show_abbr']); ?></span>
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
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
