<?php

declare(strict_types=1);

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var array<string, mixed> $filters */
/** @var list<array<string, mixed>> $queueItems */
/** @var array<string, int> $statusCounts */
/** @var list<array<string, mixed>> $showList */
/** @var list<array<string, mixed>> $mediaTypeList */
/** @var list<array<string, mixed>> $recentScans */
/** @var int $total */
/** @var int $page */
/** @var int $totalPages */

$returnQuery = http_build_query(array_filter([
    'status'      => $filters['status'] ?? '',
    'confidence'  => $filters['confidence'] ?? '',
    'scan_job_id' => $filters['scan_job_id'] ?? '',
    'show_id'     => $filters['show_id'] ?? '',
    'needs_split' => !empty($filters['needs_split']) ? '1' : '',
    'q'           => $filters['search'] ?? '',
    'page'        => $page > 1 ? (string) $page : '',
]));
$returnUrl = '/queue' . ($returnQuery !== '' ? '?' . $returnQuery : '');
$previewWidth       = (int) env('PREVIEW_WIDTH', 420);
$previewHeight      = (int) env('PREVIEW_HEIGHT', 236);
$previewDurationMin = (int) round(((int) env('PREVIEW_DURATION_SECONDS', 180)) / 60);
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Review Queue</h1>
    <p class="mb-0" style="color:var(--text-soft);font-size:0.8rem;">
      <?php echo number_format($total); ?> files matching filters.
      <?php if (Auth::isAdmin() && ($statusCounts['APPROVED'] ?? 0) > 0): ?>
      <a href="/execute" class="ms-1"><?php echo (int) $statusCounts['APPROVED']; ?> approved — ready to execute</a>
      <?php endif; ?>
    </p>
  </div>
</div>

<!-- Status pills -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php
  $statuses = ['PENDING', 'APPROVED', 'FLAGGED', 'REJECTED', 'EXECUTED'];
  foreach ($statuses as $st):
      $active = ($filters['status'] ?? '') === $st;
      $cnt = $statusCounts[$st] ?? 0;
  ?>
  <a href="/queue?status=<?php echo urlencode($st); ?>"
     class="btn btn-sm <?php echo $active ? 'btn-primary' : 'btn-outline-secondary'; ?>">
    <?php echo View::e($st); ?> <span class="opacity-75">(<?php echo $cnt; ?>)</span>
  </a>
  <?php endforeach; ?>
</div>

<!-- Filters -->
<form method="get" action="/queue" class="card mb-4">
  <div class="card-body py-3">
    <div class="row g-2 align-items-end">
      <input type="hidden" name="status" value="<?php echo View::e($filters['status'] ?? 'PENDING'); ?>">
      <div class="col-md-2">
        <label class="form-label">Confidence</label>
        <select name="confidence" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach (['LOW', 'MEDIUM', 'HIGH'] as $c): ?>
          <option value="<?php echo $c; ?>" <?php echo ($filters['confidence'] ?? '') === $c ? 'selected' : ''; ?>>
            <?php echo $c; ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Show</label>
        <select name="show_id" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach ($showList as $show): ?>
          <option value="<?php echo (int) $show['id']; ?>"
            <?php echo (int) ($filters['show_id'] ?? 0) === (int) $show['id'] ? 'selected' : ''; ?>>
            <?php echo View::e($show['abbreviation']); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Scan Job</label>
        <select name="scan_job_id" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach ($recentScans as $job): ?>
          <option value="<?php echo (int) $job['id']; ?>"
            <?php echo (int) ($filters['scan_job_id'] ?? 0) === (int) $job['id'] ? 'selected' : ''; ?>>
            #<?php echo (int) $job['id']; ?> — <?php echo View::e($job['subpath'] ?: 'full'); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Search</label>
        <input type="text" name="q" class="form-control form-control-sm"
               value="<?php echo View::e($filters['search'] ?? ''); ?>" placeholder="Filename or path">
      </div>
      <div class="col-md-1">
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="needs_split" id="needs-split"
                 <?php echo !empty($filters['needs_split']) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="needs-split" style="font-size:0.78rem">Split</label>
        </div>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
      </div>
    </div>
  </div>
</form>

<?php if (($filters['status'] ?? '') === 'PENDING' && Auth::isEditor()): ?>
<form method="post" action="/queue/batch" id="batch-form">
  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
  <input type="hidden" name="return" value="<?php echo View::e($returnUrl); ?>">
  <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
    <button type="submit" name="action" value="approve" class="btn btn-success btn-sm">Approve Selected</button>
    <button type="submit" name="action" value="reject" class="btn btn-outline-secondary btn-sm">Reject Selected</button>
    <button type="submit" name="action" value="flag" class="btn btn-outline-warning btn-sm">Flag Selected</button>
    <?php if (Auth::isAdmin()): ?>
    <button type="submit" formaction="/queue/add-split" class="btn btn-outline-info btn-sm">Add to Split Queue</button>
    <?php endif; ?>
    <span id="selection-count" class="path-text ms-1" style="font-size:0.78rem"></span>
    <button type="button" id="clear-selection" class="btn btn-link btn-sm p-0 path-text d-none"
            style="font-size:0.78rem">Clear selection</button>
    <span class="path-text ms-auto d-none d-md-inline" style="font-size:0.72rem;color:var(--text-soft)">
      Click row to toggle · Shift+click for range · Header box selects all on this page
    </span>
  </div>
<?php endif; ?>

<?php if (!empty($filters['needs_split']) && Auth::isAdmin() && ($filters['status'] ?? '') !== 'PENDING'): ?>
<form method="post" action="/queue/add-split" class="mb-3" id="split-only-form"
      onsubmit="return confirmSplitSelected(this)">
  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
  <input type="hidden" name="return" value="<?php echo View::e($returnUrl); ?>">
  <button type="submit" class="btn btn-outline-info btn-sm">Add Selected to Split Queue</button>
</form>
<?php endif; ?>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle" id="queue-table">
      <thead>
        <tr>
          <?php if (($filters['status'] ?? '') === 'PENDING' || !empty($filters['needs_split'])): ?>
          <th style="width:32px">
            <input type="checkbox" id="check-all" title="Select all on this page" aria-label="Select all on this page">
          </th>
          <?php endif; ?>
          <th style="width:90px"></th>
          <th>Original</th>
          <th>Proposed</th>
          <th>Meta</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php
        $colspan = (($filters['status'] ?? '') === 'PENDING' || !empty($filters['needs_split'])) ? 6 : 5;
        if ($queueItems === []):
        ?>
        <tr>
          <td colspan="<?php echo $colspan; ?>" class="text-center py-5" style="color:var(--text-soft)">No files in queue.</td>
        </tr>
        <?php else: ?>
        <?php foreach ($queueItems as $item): ?>
        <tr class="queue-select-row">
          <?php if (($filters['status'] ?? '') === 'PENDING' || !empty($filters['needs_split'])): ?>
          <td class="queue-check-cell">
            <input type="checkbox" name="ids[]" value="<?php echo (int) $item['id']; ?>" class="row-check"
                   aria-label="Select <?php echo View::e($item['original_filename']); ?>">
          </td>
          <?php endif; ?>
          <td>
            <button type="button" class="btn p-0 border-0 queue-thumb-btn"
                    data-file-id="<?php echo (int) $item['id']; ?>"
                    data-filename="<?php echo View::e($item['original_filename']); ?>"
                    data-meta="<?php echo View::e(json_encode(View::mediaMetaPayload($item), JSON_THROW_ON_ERROR)); ?>"
                    title="Preview">
              <img src="/queue/thumbnail/<?php echo (int) $item['id']; ?>"
                   alt="" width="80" height="45" class="rounded"
                   style="object-fit:cover;background:var(--form-bg);cursor:pointer"
                   loading="lazy"
                   onerror="this.style.display='none'">
            </button>
          </td>
          <td>
            <div class="path-filename"><?php echo View::e($item['original_filename']); ?></div>
            <div class="path-text"><?php echo View::e(dirname(\MediaManager\Repositories\FileRepository::displayPath($item))); ?></div>
            <?php if (!empty($item['needs_split'])): ?>
            <span class="badge bg-warning text-dark mt-1" style="font-size:0.68rem">Needs split</span>
            <?php endif; ?>
          </td>
          <td>
            <?php
            $primarySource = (string) ($item['proposed_source'] ?? 'classifier');
            $altDir  = (string) ($item['alt_proposed_dir'] ?? '');
            $altFile = (string) ($item['alt_proposed_filename'] ?? '');
            $agreement = (string) ($item['proposal_agreement'] ?? '');
            $mapScore = (int) ($item['map_curator_confidence'] ?? 0);
            $hasAlt = $altDir !== '' && $altFile !== '';
            ?>
            <?php if ($item['proposed_filename']): ?>
            <div class="path-filename proposed">
              <?php if ($primarySource === 'legacy_map'): ?>★ <?php endif; ?>
              <?php echo View::e($item['proposed_filename']); ?>
            </div>
            <div class="path-text proposed"><?php echo View::e($item['proposed_dir']); ?></div>
            <div class="path-text mt-1" style="font-size:0.72rem">
              <?php echo $primarySource === 'legacy_map' ? 'Legacy map' : 'Classifier'; ?>
              <?php if ($agreement !== '' && $agreement !== 'classifier_only'): ?>
              · <?php echo View::e($agreement); ?>
              <?php endif; ?>
            </div>
            <?php if ($hasAlt): ?>
            <div class="mt-2 pt-2" style="border-top:1px dashed var(--border-color);font-size:0.76rem">
              <div class="path-text">Alternate (<?php echo View::e((string) ($item['alt_source'] ?? 'legacy_map')); ?>
                <?php if ($mapScore > 0): ?> · <?php echo $mapScore; ?>/10<?php endif; ?>):</div>
              <div class="path-filename"><?php echo View::e($altFile); ?></div>
              <div class="path-text"><?php echo View::e($altDir); ?></div>
              <?php if (Auth::isEditor() && in_array($item['status'], ['PENDING', 'FLAGGED'], true)): ?>
              <div class="d-flex gap-1 mt-1">
                <?php if ($primarySource !== 'classifier'): ?>
                <form method="post" action="/queue/adopt-proposal" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
                  <input type="hidden" name="return" value="<?php echo View::e($returnUrl); ?>">
                  <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                  <input type="hidden" name="source" value="classifier">
                  <button type="submit" class="btn btn-outline-secondary btn-xs">Use classifier</button>
                </form>
                <?php endif; ?>
                <?php if ($primarySource !== 'legacy_map'): ?>
                <form method="post" action="/queue/adopt-proposal" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
                  <input type="hidden" name="return" value="<?php echo View::e($returnUrl); ?>">
                  <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                  <input type="hidden" name="source" value="legacy_map">
                  <button type="submit" class="btn btn-outline-secondary btn-xs">Use map</button>
                </form>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <span style="color:var(--text-soft)">—</span>
            <?php endif; ?>
          </td>
          <td class="text-nowrap" style="font-size:0.78rem">
            <span class="badge badge-confidence-<?php echo View::e($item['confidence']); ?>">
              <?php echo View::e($item['confidence']); ?>
            </span>
            <div class="path-text mt-1">
              <?php echo View::e($item['show_abbr'] ?? '—'); ?>
              · <?php echo View::e($item['media_type_name'] ?? '—'); ?>
            </div>
            <div class="path-text"><?php echo View::duration($item['duration_seconds'] ?? null); ?></div>
            <div class="path-text" style="font-size:0.72rem" title="Scan-time FFprobe summary">
              <?php echo View::e(View::mediaTechSummary($item)); ?>
            </div>
          </td>
          <td class="text-end text-nowrap">
            <?php if (Auth::isEditor() && in_array($item['status'], ['PENDING', 'FLAGGED'], true)): ?>
            <button type="button" class="btn btn-outline-secondary btn-xs"
                    data-bs-toggle="modal" data-bs-target="#edit-<?php echo (int) $item['id']; ?>">
              Edit
            </button>
            <?php if ($item['status'] === 'PENDING'): ?>
            <form method="post" action="/queue/approve" class="d-inline">
              <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
              <input type="hidden" name="return" value="<?php echo View::e($returnUrl); ?>">
              <input type="hidden" name="ids[]" value="<?php echo (int) $item['id']; ?>">
              <button type="submit" class="btn btn-success btn-xs">Approve</button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (Auth::isEditor() && !empty($item['needs_split'])
                && in_array($item['status'], ['PENDING', 'FLAGGED', 'APPROVED'], true)): ?>
            <form method="post" action="/queue/clear-split" class="d-inline"
                  onsubmit="return confirm('Clear split flag for this file? Any pending split jobs will be removed.');">
              <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
              <input type="hidden" name="return" value="<?php echo View::e($returnUrl); ?>">
              <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
              <button type="submit" class="btn btn-outline-warning btn-xs">Clear split</button>
            </form>
            <?php endif; ?>
            <?php if (Auth::isAdmin() && !empty($item['needs_split'])
                && in_array($item['status'], ['PENDING', 'FLAGGED', 'APPROVED'], true)): ?>
            <form method="post" action="/queue/add-split" class="d-inline">
              <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
              <input type="hidden" name="return" value="<?php echo View::e($returnUrl); ?>">
              <input type="hidden" name="ids[]" value="<?php echo (int) $item['id']; ?>">
              <button type="submit" class="btn btn-outline-info btn-xs">Split</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (($filters['status'] ?? '') === 'PENDING' && Auth::isEditor()): ?>
</form>
<?php endif; ?>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<nav class="mt-3">
  <ul class="pagination pagination-sm mb-0">
    <?php for ($p = 1; $p <= min($totalPages, 20); $p++): ?>
    <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
      <a class="page-link" href="/queue?<?php echo http_build_query(array_merge(
          array_filter([
              'status'      => $filters['status'] ?? '',
              'confidence'  => $filters['confidence'] ?? '',
              'scan_job_id' => $filters['scan_job_id'] ?? '',
              'show_id'     => $filters['show_id'] ?? '',
              'needs_split' => !empty($filters['needs_split']) ? '1' : '',
              'q'           => $filters['search'] ?? '',
          ]),
          ['page' => $p]
      )); ?>"><?php echo $p; ?></a>
    </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<!-- Edit modals -->
<?php foreach ($queueItems as $item): ?>
<?php if (!Auth::isEditor() || !in_array($item['status'], ['PENDING', 'FLAGGED'], true)) continue; ?>
<?php
$s = is_array($item['edit_suggest'] ?? null) ? $item['edit_suggest'] : [];
$editDir  = (string) ($item['proposed_dir'] ?? '') ?: (string) ($s['proposed_dir'] ?? '');
$editFile = (string) ($item['proposed_filename'] ?? '') ?: (string) ($s['proposed_filename'] ?? '');
$editShow = (int) ($item['show_id'] ?? 0) ?: (int) ($s['show_id'] ?? 0);
$editType = (int) ($item['media_type_id'] ?? 0) ?: (int) ($s['media_type_id'] ?? 0);
$editDate = (string) ($item['file_date'] ?? '') ?: (string) ($s['file_date'] ?? '');
$editTime = (string) ($item['file_time'] ?? '') ?: (string) ($s['file_time'] ?? '');
$suggestSignals = is_array($s['signals'] ?? null) ? $s['signals'] : [];
$suggestHint = $suggestSignals !== [] ? implode(' · ', array_slice($suggestSignals, 0, 4)) : '';
?>
<div class="modal fade" id="edit-<?php echo (int) $item['id']; ?>" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="background:var(--panel);border-color:var(--border-color)">
      <form method="post" action="/queue/edit" class="edit-file-form"
            data-suggest="<?php echo View::e(json_encode([
                'proposed_dir' => $s['proposed_dir'] ?? '',
                'proposed_filename' => $s['proposed_filename'] ?? '',
                'show_id' => $s['show_id'] ?? '',
                'media_type_id' => $s['media_type_id'] ?? '',
                'file_date' => $s['file_date'] ?? '',
                'file_time' => $s['file_time'] ?? '',
            ], JSON_THROW_ON_ERROR)); ?>">
        <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
        <input type="hidden" name="return" value="<?php echo View::e($returnUrl); ?>">
        <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
        <div class="modal-header border-secondary">
          <h5 class="modal-title fs-6">Edit Proposed Path</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="path-text mb-2"><?php echo View::e($item['original_path']); ?></p>
          <?php if ($suggestHint !== ''): ?>
          <div class="alert alert-secondary py-2 px-3 mb-3" style="font-size:0.78rem">
            Detected: <?php echo View::e($suggestHint); ?>
            <?php if (!empty($s['confidence'])): ?>
            <span class="badge badge-confidence-<?php echo View::e((string) $s['confidence']); ?> ms-1">
              <?php echo View::e((string) $s['confidence']); ?>
            </span>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Proposed Directory</label>
              <input type="text" name="proposed_dir" class="form-control" required
                     value="<?php echo View::e($editDir); ?>"
                     placeholder="SHOW/YYYY/MM/MediaType">
            </div>
            <div class="col-md-6">
              <label class="form-label">Proposed Filename</label>
              <input type="text" name="proposed_filename" class="form-control" required
                     value="<?php echo View::e($editFile); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Show</label>
              <select name="show_id" class="form-select">
                <option value="">—</option>
                <?php foreach ($showList as $show): ?>
                <option value="<?php echo (int) $show['id']; ?>"
                  <?php echo $editShow === (int) $show['id'] ? 'selected' : ''; ?>>
                  <?php echo View::e($show['abbreviation']); ?>
                  <?php if (!empty($show['canonical_name'])): ?>
                  — <?php echo View::e($show['canonical_name']); ?>
                  <?php endif; ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Media Type</label>
              <select name="media_type_id" class="form-select">
                <option value="">—</option>
                <?php foreach ($mediaTypeList as $mt): ?>
                <option value="<?php echo (int) $mt['id']; ?>"
                  <?php echo $editType === (int) $mt['id'] ? 'selected' : ''; ?>>
                  <?php echo View::e($mt['name']); ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Date</label>
              <input type="text" name="file_date" class="form-control" maxlength="8"
                     value="<?php echo View::e($editDate); ?>" placeholder="YYYYMMDD">
            </div>
            <div class="col-md-2">
              <label class="form-label">Time</label>
              <input type="text" name="file_time" class="form-control" maxlength="4"
                     value="<?php echo View::e($editTime); ?>" placeholder="HHMM">
            </div>
            <div class="col-12">
              <hr class="border-secondary my-1">
              <div class="form-check">
                <input type="checkbox" class="form-check-input" name="needs_split" id="needs-split-<?php echo (int) $item['id']; ?>"
                       value="1" <?php echo !empty($item['needs_split']) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="needs-split-<?php echo (int) $item['id']; ?>">
                  Needs split
                </label>
                <?php if (!empty($item['duration_seconds'])): ?>
                <span class="text-muted ms-2" style="font-size:0.78rem">
                  Duration: <?php echo View::e(View::duration($item['duration_seconds'])); ?>
                </span>
                <?php endif; ?>
              </div>
              <label class="form-label mt-2 mb-1">Split notes</label>
              <textarea name="split_notes" class="form-control form-control-sm" rows="2"
                        placeholder="Why this file should be split (optional)"><?php echo View::e((string) ($item['split_notes'] ?? '')); ?></textarea>
              <?php if (!empty($item['split_notes']) && empty($item['needs_split'])): ?>
              <div class="form-text">Previous scan notes shown; check “Needs split” to re-flag.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="modal-footer border-secondary d-flex justify-content-between">
          <button type="button" class="btn btn-outline-info btn-sm apply-suggest-btn">Apply Detected Values</button>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary btn-sm">Save</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- Media preview modal -->
<div class="modal fade" id="media-preview-modal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content" style="background:var(--panel);border-color:var(--border-color)">
      <div class="modal-header border-secondary py-2">
        <h6 class="modal-title fs-6 mb-0" id="media-preview-title">Preview</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" id="media-preview-close"></button>
      </div>
      <div class="modal-body p-2 text-center">
        <div id="media-preview-stage">
          <img id="media-preview-image" src="" alt="" class="img-fluid rounded"
               style="max-height:55vh;cursor:pointer" title="Click to play video preview">
          <p class="path-text mt-2 mb-0" style="font-size:0.75rem">
            Click image to load <?php echo $previewDurationMin; ?>-minute preview
          </p>
        </div>
        <div id="media-preview-video-wrap" class="d-none">
          <video id="media-preview-video" controls autoplay
                 style="width:100%;max-width:<?php echo $previewWidth; ?>px;max-height:<?php echo (int) round($previewHeight * 1.2); ?>px;background:#000;border-radius:6px">
          </video>
          <p class="path-text mt-2 mb-0" style="font-size:0.75rem">
            <?php echo $previewDurationMin; ?>-minute proxy · <?php echo $previewWidth; ?>×<?php echo $previewHeight; ?>
          </p>
        </div>
        <div id="media-preview-loading" class="d-none py-5">
          <div class="spinner-border spinner-border-sm text-secondary" role="status"></div>
          <span class="ms-2 path-text">Generating preview…</span>
        </div>

        <div id="media-meta-panel" class="text-start mt-3 pt-3" style="border-top:1px solid var(--border-color)">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span style="font-size:0.74rem;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:var(--text-soft)">
              Technical Metadata
            </span>
            <button type="button" class="btn btn-outline-secondary btn-xs" id="media-ffprobe-load-btn">
              Refresh FFprobe
            </button>
          </div>
          <dl id="media-meta-summary" class="row mb-0" style="font-size:0.78rem"></dl>
          <div class="mt-2">
            <button class="btn btn-link btn-sm p-0 path-text" type="button"
                    data-bs-toggle="collapse" data-bs-target="#media-ffprobe-raw-wrap"
                    id="media-ffprobe-toggle" style="font-size:0.76rem">
              Show full FFprobe JSON
            </button>
            <div class="collapse mt-2" id="media-ffprobe-raw-wrap">
              <div id="media-ffprobe-loading" class="d-none path-text py-2" style="font-size:0.76rem">
                Running ffprobe…
              </div>
              <pre id="media-ffprobe-raw" class="path-text mb-0 p-2 rounded"
                   style="font-size:0.68rem;max-height:240px;overflow:auto;background:var(--form-bg);white-space:pre-wrap"></pre>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
#queue-table .queue-select-row.queue-row-selected > td {
    background: rgba(var(--bs-primary-rgb, 13, 110, 253), 0.08);
}
#queue-table .queue-select-row:not(.queue-row-selected):hover > td {
    background: rgba(255, 255, 255, 0.03);
}
#queue-table .queue-check-cell {
    cursor: default;
}
#queue-table .queue-select-row:has(.row-check) {
    cursor: pointer;
}
</style>

<script>
(function () {
    var checkAll = document.getElementById('check-all');
    var countEl = document.getElementById('selection-count');
    var clearBtn = document.getElementById('clear-selection');
    var batchForm = document.getElementById('batch-form');
    var lastIndex = -1;

    function rowChecks() {
        return Array.prototype.slice.call(document.querySelectorAll('.row-check'));
    }

    function updateSelectionUi() {
        var checks = rowChecks();
        var checked = checks.filter(function (cb) { return cb.checked; });
        if (checkAll) {
            checkAll.checked = checks.length > 0 && checked.length === checks.length;
            checkAll.indeterminate = checked.length > 0 && checked.length < checks.length;
        }
        if (countEl) {
            countEl.textContent = checked.length > 0
                ? checked.length + ' of ' + checks.length + ' selected'
                : '';
        }
        if (clearBtn) {
            clearBtn.classList.toggle('d-none', checked.length === 0);
        }
        checks.forEach(function (cb) {
            var row = cb.closest('tr');
            if (row) {
                row.classList.toggle('queue-row-selected', cb.checked);
            }
        });
    }

    function setRange(fromIndex, toIndex, state) {
        var checks = rowChecks();
        var start = Math.min(fromIndex, toIndex);
        var end = Math.max(fromIndex, toIndex);
        for (var i = start; i <= end; i++) {
            if (checks[i]) {
                checks[i].checked = state;
            }
        }
        updateSelectionUi();
    }

    function toggleAtIndex(index, shiftKey, state) {
        if (shiftKey && lastIndex >= 0) {
            setRange(lastIndex, index, state);
            return;
        }
        lastIndex = index;
        updateSelectionUi();
    }

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            rowChecks().forEach(function (cb) {
                cb.checked = checkAll.checked;
            });
            lastIndex = -1;
            updateSelectionUi();
        });
    }

    rowChecks().forEach(function (cb, index) {
        cb.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleAtIndex(index, e.shiftKey, cb.checked);
        });
    });

    document.querySelectorAll('#queue-table .queue-select-row').forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest(
                'a, button, input, form, select, textarea, label, [data-bs-toggle], .queue-thumb-btn'
            )) {
                return;
            }
            var cb = row.querySelector('.row-check');
            if (!cb) {
                return;
            }
            var index = rowChecks().indexOf(cb);
            if (index < 0) {
                return;
            }
            var newState = !cb.checked;
            cb.checked = newState;
            toggleAtIndex(index, e.shiftKey, newState);
        });
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            rowChecks().forEach(function (cb) {
                cb.checked = false;
            });
            lastIndex = -1;
            if (checkAll) {
                checkAll.checked = false;
                checkAll.indeterminate = false;
            }
            updateSelectionUi();
        });
    }

    if (batchForm) {
        batchForm.addEventListener('submit', function (e) {
            var checked = rowChecks().filter(function (cb) { return cb.checked; });
            if (checked.length === 0) {
                e.preventDefault();
                alert('Select at least one file.');
            }
        });
    }

    updateSelectionUi();

    document.querySelectorAll('.apply-suggest-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = btn.closest('form');
            if (!form) return;
            var raw = form.getAttribute('data-suggest');
            if (!raw) return;
            try {
                var s = JSON.parse(raw);
            } catch (e) {
                return;
            }
            if (s.proposed_dir) form.querySelector('[name="proposed_dir"]').value = s.proposed_dir;
            if (s.proposed_filename) form.querySelector('[name="proposed_filename"]').value = s.proposed_filename;
            if (s.show_id) form.querySelector('[name="show_id"]').value = String(s.show_id);
            if (s.media_type_id) form.querySelector('[name="media_type_id"]').value = String(s.media_type_id);
            if (s.file_date) form.querySelector('[name="file_date"]').value = s.file_date;
            if (s.file_time) form.querySelector('[name="file_time"]').value = s.file_time;
        });
    });

    var previewModal = document.getElementById('media-preview-modal');
    if (!previewModal) return;

    var modal = bootstrap.Modal.getOrCreateInstance(previewModal);
    var img = document.getElementById('media-preview-image');
    var stage = document.getElementById('media-preview-stage');
    var videoWrap = document.getElementById('media-preview-video-wrap');
    var video = document.getElementById('media-preview-video');
    var loading = document.getElementById('media-preview-loading');
    var title = document.getElementById('media-preview-title');
    var metaSummary = document.getElementById('media-meta-summary');
    var ffprobeRaw = document.getElementById('media-ffprobe-raw');
    var ffprobeLoading = document.getElementById('media-ffprobe-loading');
    var ffprobeLoadBtn = document.getElementById('media-ffprobe-load-btn');
    var ffprobeRawWrap = document.getElementById('media-ffprobe-raw-wrap');
    var currentFileId = null;
    var ffprobeLoadedFor = null;

    function renderMetaSummary(meta) {
        if (!metaSummary) return;
        var rows = [
            ['Duration', meta.duration_label || '—'],
            ['Resolution', meta.resolution || '—'],
            ['Video', meta.codec_video ? meta.codec_video.toUpperCase() : '—'],
            ['Audio', meta.codec_audio ? meta.codec_audio.toUpperCase() : '—'],
            ['Frame rate', meta.framerate ? meta.framerate + ' fps' : '—'],
            ['Container', meta.container ? meta.container.toUpperCase() : '—'],
            ['File size', meta.filesize_label || '—'],
            ['At scan', meta.metadata_extracted ? 'Yes' : 'No']
        ];
        metaSummary.innerHTML = rows.map(function (row) {
            return '<dt class="col-sm-4 path-text">' + row[0] + '</dt>'
                + '<dd class="col-sm-8 mb-1">' + row[1] + '</dd>';
        }).join('');
    }

    function loadFfprobeReport(fileId, force) {
        if (!fileId || (!force && ffprobeLoadedFor === fileId)) return;
        ffprobeLoadedFor = fileId;
        if (ffprobeLoading) ffprobeLoading.classList.remove('d-none');
        if (ffprobeRaw) ffprobeRaw.textContent = '';

        fetch('/queue/ffprobe/' + fileId + '?_=' + Date.now())
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (ffprobeLoading) ffprobeLoading.classList.add('d-none');
                var summary = data.live_summary || data.stored_summary || {};
                if (summary.duration !== undefined && summary.duration !== null) {
                    summary.duration_label = formatDuration(summary.duration);
                }
                if (summary.filesize_bytes !== undefined && summary.filesize_bytes !== null) {
                    summary.filesize_label = formatBytes(summary.filesize_bytes);
                }
                if (data.live_summary) {
                    summary.metadata_extracted = true;
                    renderMetaSummary(summary);
                }
                if (ffprobeRaw) {
                    if (data.error && !data.raw) {
                        ffprobeRaw.textContent = data.error;
                    } else if (data.raw) {
                        ffprobeRaw.textContent = JSON.stringify(data.raw, null, 2);
                    } else {
                        ffprobeRaw.textContent = 'No FFprobe data available.';
                    }
                }
            })
            .catch(function () {
                if (ffprobeLoading) ffprobeLoading.classList.add('d-none');
                if (ffprobeRaw) ffprobeRaw.textContent = 'Could not load FFprobe report.';
            });
    }

    function formatDuration(seconds) {
        seconds = Math.floor(Number(seconds) || 0);
        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = seconds % 60;
        return h > 0
            ? h + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0')
            : m + ':' + String(s).padStart(2, '0');
    }

    function formatBytes(bytes) {
        bytes = Number(bytes) || 0;
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
        return (bytes / 1073741824).toFixed(2) + ' GB';
    }

    function resetPreview() {
        currentFileId = null;
        ffprobeLoadedFor = null;
        stage.classList.remove('d-none');
        videoWrap.classList.add('d-none');
        loading.classList.add('d-none');
        video.pause();
        video.removeAttribute('src');
        video.load();
        if (metaSummary) metaSummary.innerHTML = '';
        if (ffprobeRaw) ffprobeRaw.textContent = '';
        if (ffprobeRawWrap) {
            var collapse = bootstrap.Collapse.getInstance(ffprobeRawWrap);
            if (collapse) collapse.hide();
        }
    }

    previewModal.addEventListener('hidden.bs.modal', resetPreview);

    if (ffprobeLoadBtn) {
        ffprobeLoadBtn.addEventListener('click', function () {
            if (currentFileId) loadFfprobeReport(currentFileId, true);
        });
    }

    if (ffprobeRawWrap) {
        ffprobeRawWrap.addEventListener('show.bs.collapse', function () {
            if (currentFileId && ffprobeLoadedFor !== currentFileId) {
                loadFfprobeReport(currentFileId, false);
            }
        });
    }

    document.querySelectorAll('.queue-thumb-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var fileId = btn.getAttribute('data-file-id');
            var filename = btn.getAttribute('data-filename') || 'Preview';
            var metaRaw = btn.getAttribute('data-meta') || '{}';
            if (!fileId) return;
            currentFileId = fileId;
            ffprobeLoadedFor = null;
            resetPreview();
            currentFileId = fileId;
            title.textContent = filename;
            try {
                renderMetaSummary(JSON.parse(metaRaw));
            } catch (e) {
                renderMetaSummary({});
            }
            img.src = '/queue/thumbnail/' + fileId + '?size=large&_=' + Date.now();
            modal.show();
        });
    });

    img.addEventListener('click', function () {
        if (!currentFileId) return;
        stage.classList.add('d-none');
        loading.classList.remove('d-none');
        videoWrap.classList.add('d-none');

        var previewUrl = '/queue/preview/' + currentFileId + '?_=' + Date.now();
        video.onloadeddata = function () {
            loading.classList.add('d-none');
            videoWrap.classList.remove('d-none');
        };
        video.onerror = function () {
            loading.classList.add('d-none');
            stage.classList.remove('d-none');
            alert('Preview could not be generated. The source file may be unavailable.');
        };
        video.src = previewUrl;
        video.load();
    });
})();

function confirmSplitSelected(form) {
    var ids = document.querySelectorAll('.row-check:checked');
    if (ids.length === 0) {
        alert('Select at least one file.');
        return false;
    }
    ids.forEach(function (cb) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'ids[]';
        input.value = cb.value;
        form.appendChild(input);
    });
    return confirm('Add ' + ids.length + ' file(s) to split queue?');
}
</script>
