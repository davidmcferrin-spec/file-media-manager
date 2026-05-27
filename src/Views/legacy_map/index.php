<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var list<array<string, mixed>> $entries */
/** @var list<array<string, mixed>> $recentJobs */
/** @var int $total */
/** @var array{skipped?: list<string>, warnings?: list<string>}|null $importLog */
?>

<?php require dirname(__DIR__) . '/shows/_nav.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Legacy Rename Map</h1>
    <p class="mb-0" style="color:var(--text-soft);font-size:0.8rem;">
      Curated per-file rename targets. Import the spreadsheet, then apply to a completed scan job to compare with classifier proposals.
      <?php echo number_format($total); ?> row(s) loaded.
    </p>
  </div>
</div>

<div class="row g-4 mb-4">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">Import Map</div>
      <div class="card-body">
        <form method="post" action="/legacy-map/import" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <p class="path-text mb-3" style="font-size:0.78rem">
            Default: <code>example_file_trees/NN_Legacy_Rename_Map.xlsx</code><br>
            Columns: Source, Original Path, Original Filename, Suggested Path, Suggested Filename,
            Show Abbr, Media Type, Confidence, Notes.
          </p>
          <div class="mb-3">
            <label class="form-label">Optional file upload (.xlsx or .csv)</label>
            <input type="file" name="map_file" class="form-control form-control-sm" accept=".xlsx,.csv,text/csv">
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="replace_existing" id="replace-map" checked>
            <label class="form-check-label" for="replace-map">Replace existing map rows</label>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Import Map</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">Apply to Scan Job</div>
      <div class="card-body">
        <form method="post" action="/legacy-map/apply"
              onsubmit="return confirm('Match map rows to this scan job and reconcile confidence? Pending/flagged files only.');">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <p class="path-text mb-3" style="font-size:0.78rem">
            Matches on <strong>Source + Original Path + Original Filename</strong>.
            Updates effective confidence and stores both classifier and map proposals for review.
          </p>
          <div class="row g-2 align-items-end">
            <div class="col-md-8">
              <label class="form-label">Scan job</label>
              <select name="scan_job_id" class="form-select form-select-sm" required>
                <option value="">—</option>
                <?php foreach ($recentJobs as $job): ?>
                <option value="<?php echo (int) $job['id']; ?>">
                  #<?php echo (int) $job['id']; ?> — <?php echo View::e($job['source_name']); ?>
                  (<?php echo View::e($job['status']); ?>, <?php echo number_format((int) ($job['processed_files'] ?? 0)); ?> files)
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <button type="submit" class="btn btn-outline-warning btn-sm w-100">Apply Map</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if ($importLog !== null && (!empty($importLog['skipped']) || !empty($importLog['warnings']))): ?>
<div class="card mb-4">
  <div class="card-header">Import Log</div>
  <div class="card-body py-2" style="font-size:0.78rem;max-height:200px;overflow:auto">
    <?php foreach ($importLog['warnings'] ?? [] as $line): ?>
    <div class="text-warning"><?php echo View::e($line); ?></div>
    <?php endforeach; ?>
    <?php foreach ($importLog['skipped'] ?? [] as $line): ?>
    <div class="path-text"><?php echo View::e($line); ?></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">Map Rows (sample)</div>
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0">
      <thead>
        <tr>
          <th>Src</th>
          <th>Original</th>
          <th>Suggested</th>
          <th>Show</th>
          <th>Type</th>
          <th>Conf</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($entries === []): ?>
        <tr>
          <td colspan="6" class="text-center py-4" style="color:var(--text-soft)">
            No map rows — import the spreadsheet to get started.
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($entries as $row): ?>
        <tr>
          <td><?php echo View::e($row['source_label']); ?></td>
          <td class="path-text" style="max-width:220px">
            <?php echo View::e(basename((string) $row['match_path']) . '/' . ($row['match_filename'] ?? '')); ?>
          </td>
          <td class="path-text proposed" style="max-width:220px">
            <?php if (($row['row_type'] ?? '') === 'template'): ?>
            <span class="text-warning">template</span>
            <?php elseif (!empty($row['target_filename'])): ?>
            <?php echo View::e($row['target_filename']); ?>
            <?php else: ?>
            —
            <?php endif; ?>
          </td>
          <td><code><?php echo View::e($row['show_abbr']); ?></code></td>
          <td><?php echo View::e($row['media_type_label']); ?></td>
          <td><?php echo (int) ($row['curator_confidence'] ?? 0); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
