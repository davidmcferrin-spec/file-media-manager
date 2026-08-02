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
/** @var int $perPage */

$returnQuery = http_build_query(array_filter([
    'status'      => $filters['status'] ?? '',
    'confidence'  => $filters['confidence'] ?? '',
    'scan_job_id' => $filters['scan_job_id'] ?? '',
    'show_id'     => $filters['show_id'] ?? '',
    'needs_split' => !empty($filters['needs_split']) ? '1' : '',
    'needs_glue'  => !empty($filters['needs_glue']) ? '1' : '',
    'glue_group'  => $filters['glue_group_key'] ?? '',
    'q'           => $filters['search'] ?? '',
    'per_page'    => $perPage !== 50 ? (string) $perPage : '',
    'page'        => $page > 1 ? (string) $page : '',
]));
$returnUrl = '/queue' . ($returnQuery !== '' ? '?' . $returnQuery : '');
$paginationQuery = [
    'status'      => $filters['status'] ?? '',
    'confidence'  => $filters['confidence'] ?? '',
    'scan_job_id' => $filters['scan_job_id'] ?? '',
    'show_id'     => $filters['show_id'] ?? '',
    'needs_split' => !empty($filters['needs_split']) ? '1' : '',
    'needs_glue'  => !empty($filters['needs_glue']) ? '1' : '',
    'glue_group'  => $filters['glue_group_key'] ?? '',
    'q'           => $filters['search'] ?? '',
    'per_page'    => $perPage !== 50 ? (string) $perPage : '',
];
$paginationBasePath = '/queue';
$previewWidth       = (int) env('PREVIEW_WIDTH', 420);
$previewHeight      = (int) env('PREVIEW_HEIGHT', 236);
$previewDurationMin = (int) round(((int) env('PREVIEW_DURATION_SECONDS', 180)) / 60);

$workflowStepId = 'catalog';
require dirname(__DIR__) . '/partials/workflow_step.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Catalog</h1>
    <p class="mb-0" style="color:var(--text-soft);font-size:0.8rem;">
      <?php echo number_format($total); ?> files matching filters.
      Match show, date, and type; approve rename proposals.
      <span id="queue-approved-hint"><?php if (Auth::isAdmin() && ($statusCounts['APPROVED'] ?? 0) > 0): ?>
      <a href="/execute" class="ms-1"><span id="queue-approved-count"><?php echo (int) $statusCounts['APPROVED']; ?></span> approved — ready to execute</a>
      <?php endif; ?></span>
    </p>
    <p class="mb-0 mt-1 path-text" style="font-size:0.72rem">
      CC legend:
      <span class="badge" style="font-size:0.65rem;background:rgba(148,163,184,0.45);color:var(--text-main)">CC?</span> not probed
      · <span class="badge" style="font-size:0.65rem;background:#e67e22;color:#fff">CC</span> captions found
      · <span class="badge" style="font-size:0.65rem;background:#198754;color:#fff">CC</span> SRT extracted
      · <span class="badge" style="font-size:0.65rem;background:rgba(148,163,184,0.28);color:var(--text-soft);text-decoration:line-through">CC</span> probed, none
      <?php if (Auth::isAdmin()): ?>
      · <a href="/captions">Probe / extract</a>
      <?php endif; ?>
    </p>
  </div>
</div>

<!-- Status pills -->
<div class="d-flex flex-wrap gap-2 mb-3" id="queue-status-pills">
  <?php
  $statusFilter = $statusFilter ?? ($filters['status'] ?? 'PENDING');
  $statuses = ['PENDING', 'APPROVED', 'FLAGGED', 'REJECTED', 'EXECUTED', 'ALL'];
  foreach ($statuses as $st):
      $active = $statusFilter === $st;
      $cnt = $st === 'ALL' ? array_sum($statusCounts) : ($statusCounts[$st] ?? 0);
  ?>
  <a href="/queue?status=<?php echo urlencode($st); ?>"
     class="btn btn-sm <?php echo $active ? 'btn-primary' : 'btn-outline-secondary'; ?>"
     data-queue-status-pill="<?php echo View::e($st); ?>">
    <?php echo View::e($st); ?> <span class="opacity-75">(<span class="queue-status-cnt"><?php echo $cnt; ?></span>)</span>
  </a>
  <?php endforeach; ?>
</div>

<!-- Filters -->
<form method="get" action="/queue" class="card mb-4">
  <div class="card-body py-3">
    <div class="row g-2 align-items-end">
      <input type="hidden" name="status" value="<?php echo View::e($statusFilter ?? ($filters['status'] ?? 'PENDING')); ?>">
      <div class="col-md-2">
        <label class="form-label">Confidence</label>
        <select name="confidence" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach (['UNEVALUATED', 'LOW', 'MEDIUM', 'HIGH'] as $c): ?>
          <option value="<?php echo $c; ?>" <?php echo ($filters['confidence'] ?? '') === $c ? 'selected' : ''; ?>>
            <?php echo $c === 'UNEVALUATED' ? 'Unevaluated' : $c; ?>
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
      <div class="col-md-2">
        <label class="form-label">Search</label>
        <input type="text" name="q" class="form-control form-control-sm"
               value="<?php echo View::e($filters['search'] ?? ''); ?>" placeholder="Filename or path">
      </div>
      <div class="col-md-1">
        <label class="form-label">Per page</label>
        <select name="per_page" class="form-select form-select-sm">
          <?php foreach ([50, 100, 200] as $pp): ?>
          <option value="<?php echo $pp; ?>" <?php echo $perPage === $pp ? 'selected' : ''; ?>>
            <?php echo $pp; ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1">
        <div class="form-check mb-1">
          <input class="form-check-input" type="checkbox" name="needs_split" id="needs-split"
                 <?php echo !empty($filters['needs_split']) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="needs-split" style="font-size:0.78rem">Split</label>
        </div>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="needs_glue" id="needs-glue"
                 <?php echo !empty($filters['needs_glue']) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="needs-glue" style="font-size:0.78rem">Glue</label>
        </div>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
      </div>
    </div>
  </div>
</form>

<?php
$queueStatus = (string) ($filters['status'] ?? '');
$queueBatchable = Auth::isEditor() && (
    in_array($queueStatus, ['PENDING', 'APPROVED', 'FLAGGED', 'REJECTED'], true)
    || !empty($filters['needs_glue'])
);
?>
<?php if ($queueBatchable): ?>
<form method="post" action="/queue/batch" id="batch-form">
  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
  <input type="hidden" name="return" value="<?php echo View::e($returnUrl); ?>">
  <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
    <?php if ($queueStatus === 'PENDING'): ?>
    <button type="button" class="btn btn-outline-primary btn-sm" id="bulk-edit-open"
            data-bs-toggle="modal" data-bs-target="#bulk-edit-modal">Edit Selected</button>
    <button type="submit" name="action" value="approve" class="btn btn-success btn-sm">Approve Selected</button>
    <button type="submit" name="action" value="reject" class="btn btn-outline-secondary btn-sm">Reject Selected</button>
    <button type="submit" name="action" value="flag" class="btn btn-outline-warning btn-sm">Flag Selected</button>
    <button type="submit" formaction="/queue/mark-glue" class="btn btn-outline-primary btn-sm"
            onclick="return confirm('Mark selected files as one glue group (multipart concat)?');">Mark as Glue Group</button>
    <?php if (Auth::isAdmin()): ?>
    <button type="submit" formaction="/queue/add-split" class="btn btn-outline-info btn-sm">Add to Split Queue</button>
    <?php endif; ?>
    <?php elseif ($queueStatus === 'FLAGGED'): ?>
    <button type="button" class="btn btn-outline-primary btn-sm" id="bulk-edit-open"
            data-bs-toggle="modal" data-bs-target="#bulk-edit-modal">Edit Selected</button>
    <button type="submit" name="action" value="reject" class="btn btn-outline-secondary btn-sm">Reject Selected</button>
    <button type="submit" formaction="/queue/mark-glue" class="btn btn-outline-primary btn-sm"
            onclick="return confirm('Mark selected files as one glue group (multipart concat)?');">Mark as Glue Group</button>
    <?php elseif (!empty($filters['needs_glue']) && !in_array($queueStatus, ['PENDING', 'FLAGGED', 'APPROVED'], true)): ?>
    <button type="submit" formaction="/queue/mark-glue" class="btn btn-outline-primary btn-sm"
            onclick="return confirm('Mark selected files as one glue group (multipart concat)?');">Mark as Glue Group</button>
    <button type="submit" formaction="/queue/clear-glue" class="btn btn-outline-warning btn-sm"
            onclick="return confirm('Clear glue flags on selected files?');">Clear Glue</button>
    <?php endif; ?>
    <?php if ($queueStatus === 'APPROVED'): ?>
    <button type="submit" formaction="/queue/mark-glue" class="btn btn-outline-primary btn-sm"
            onclick="return confirm('Mark selected files as one glue group (multipart concat)?');">Mark as Glue Group</button>
    <button type="submit" name="action" value="unapprove" class="btn btn-outline-warning btn-sm"
            onclick="return confirm('Return selected approved files to pending?');">Unapprove Selected</button>
    <?php endif; ?>
    <?php if (in_array($queueStatus, ['PENDING', 'APPROVED', 'FLAGGED', 'REJECTED'], true)): ?>
    <button type="submit" formaction="/queue/remove" class="btn btn-outline-danger btn-sm"
            onclick="return confirm('Permanently remove selected files from the queue? This does not delete files on disk.');">Remove Selected</button>
    <?php endif; ?>
    <?php if (!empty($filters['needs_glue']) && in_array($queueStatus, ['PENDING', 'FLAGGED', 'APPROVED'], true)): ?>
    <button type="submit" formaction="/queue/clear-glue" class="btn btn-outline-warning btn-sm"
            onclick="return confirm('Clear glue flags on selected files?');">Clear Glue</button>
    <?php endif; ?>
    <?php if (Auth::isAdmin() && in_array($queueStatus, ['PENDING', 'FLAGGED', 'APPROVED', 'REJECTED'], true)): ?>
    <button type="submit" formaction="/queue/extract-captions" class="btn btn-outline-secondary btn-sm"
            onclick="return confirm('Extract CC for selected clips (starts a job, or moves them to the top of the active Captions cue)?');">Extract CC</button>
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
          <?php
          $showChecks = Auth::isEditor() && (
              in_array(($filters['status'] ?? ''), ['PENDING', 'APPROVED', 'FLAGGED', 'REJECTED'], true)
              || !empty($filters['needs_split'])
              || !empty($filters['needs_glue'])
          );
          ?>
          <?php if ($showChecks): ?>
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
        $showChecks = Auth::isEditor() && (
            in_array(($filters['status'] ?? ''), ['PENDING', 'APPROVED', 'FLAGGED', 'REJECTED'], true)
            || !empty($filters['needs_split'])
            || !empty($filters['needs_glue'])
        );
        $colspan = $showChecks ? 6 : 5;
        if ($queueItems === []):
        ?>
        <tr>
          <td colspan="<?php echo $colspan; ?>" class="text-center py-5" style="color:var(--text-soft)">No files in queue.</td>
        </tr>
        <?php else: ?>
        <?php foreach ($queueItems as $item): ?>
        <tr class="queue-select-row">
          <?php if ($showChecks): ?>
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
                    title="Preview thumbnail, video proxy, and FFprobe details">
              <img src="/queue/thumbnail/<?php echo (int) $item['id']; ?>"
                   alt="" width="80" height="45" class="rounded queue-thumb-img"
                   style="object-fit:cover;background:var(--form-bg);cursor:pointer"
                   loading="lazy"
                   onerror="this.classList.add('d-none');var f=this.parentElement.querySelector('.queue-thumb-fallback');if(f)f.classList.remove('d-none');">
              <span class="queue-thumb-fallback d-none rounded d-inline-flex align-items-center justify-content-center"
                    style="width:80px;height:45px;background:var(--form-bg);cursor:pointer;font-size:0.68rem;color:var(--text-soft);border:1px solid var(--border-color)">
                Preview
              </span>
            </button>
          </td>
          <td>
            <div class="path-filename"><?php echo View::e($item['original_filename']); ?></div>
            <div class="path-text"><?php echo View::e(dirname(\MediaManager\Repositories\FileRepository::displayPath($item))); ?></div>
            <?php echo View::assetIdBlock($item); ?>
            <?php if (!empty($item['needs_split'])): ?>
            <span class="badge bg-warning text-dark mt-1" style="font-size:0.68rem">Needs split</span>
            <?php endif; ?>
            <?php echo View::captionBadge($item); ?>
            <?php if (!empty($item['srt_path'])): ?>
            <button type="button" class="btn btn-link btn-xs p-0 ms-1 queue-open-captions"
                    data-file-id="<?php echo (int) $item['id']; ?>"
                    data-filename="<?php echo View::e((string) $item['original_filename']); ?>"
                    style="font-size:0.68rem;vertical-align:baseline">View SRT</button>
            <?php endif; ?>
            <?php if (!empty($item['needs_glue'])): ?>
            <span class="badge bg-primary mt-1" style="font-size:0.68rem"
                  title="<?php echo View::e((string) ($item['glue_notes'] ?? 'Multipart glue group')); ?>">
              Needs glue
              <?php if ($item['glue_part_index'] !== null && $item['glue_part_index'] !== ''): ?>
              · <?php echo View::e((string) $item['glue_part_index']); ?>
              <?php endif; ?>
            </span>
            <?php if (!empty($item['glue_group_key'])): ?>
            <div class="mt-1">
              <a href="/queue?status=ALL&amp;glue_group=<?php echo View::e(rawurlencode((string) $item['glue_group_key'])); ?>"
                 class="path-text" style="font-size:0.68rem">View group</a>
            </div>
            <?php endif; ?>
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
            <?php
            $confLabel = (string) ($item['confidence'] ?? 'UNEVALUATED');
            $confDisplay = $confLabel === 'UNEVALUATED' ? 'Unevaluated' : $confLabel;
            $parsedDt = View::formatParsedDateTime($item['file_date'] ?? null, $item['file_time'] ?? null);
            ?>
            <span class="badge badge-confidence-<?php echo View::e($confLabel); ?>">
              <?php echo View::e($confDisplay); ?>
            </span>
            <div class="path-text mt-1">
              <?php echo View::e($item['show_abbr'] ?? '—'); ?>
              · <?php echo View::e($item['media_type_name'] ?? '—'); ?>
            </div>
            <div class="path-text" title="Parsed air date/time (ET)">
              <?php echo $parsedDt !== '' ? View::e($parsedDt) : '—'; ?>
            </div>
            <div class="path-text"><?php echo View::duration($item['duration_seconds'] ?? null); ?></div>
            <div class="path-text" style="font-size:0.72rem" title="Scan-time FFprobe summary">
              <?php echo View::e(View::mediaTechSummary($item)); ?>
            </div>
            <button type="button" class="btn btn-link btn-sm p-0 queue-open-preview"
                    style="font-size:0.72rem;text-decoration:none"
                    title="Open preview modal with FFprobe details">
              Preview / FFprobe
            </button>
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
            <?php if (Auth::isEditor() && ($item['status'] ?? '') === 'APPROVED'): ?>
            <form method="post" action="/queue/unapprove" class="d-inline"
                  onsubmit="return confirm('Return this file to pending?');">
              <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
              <input type="hidden" name="return" value="<?php echo View::e($returnUrl); ?>">
              <input type="hidden" name="ids[]" value="<?php echo (int) $item['id']; ?>">
              <button type="submit" class="btn btn-outline-warning btn-xs">Unapprove</button>
            </form>
            <?php endif; ?>
            <?php if (Auth::isEditor() && in_array($item['status'] ?? '', ['PENDING', 'FLAGGED', 'REJECTED', 'APPROVED'], true)): ?>
            <form method="post" action="/queue/remove" class="d-inline"
                  onsubmit="return confirm('Remove this file from the queue? Disk file is not deleted.');">
              <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
              <input type="hidden" name="return" value="<?php echo View::e($returnUrl); ?>">
              <input type="hidden" name="ids[]" value="<?php echo (int) $item['id']; ?>">
              <button type="submit" class="btn btn-outline-danger btn-xs">Remove</button>
            </form>
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
            <?php if (Auth::isEditor() && !empty($item['needs_glue'])
                && in_array($item['status'], ['PENDING', 'FLAGGED', 'APPROVED', 'REJECTED'], true)): ?>
            <form method="post" action="/queue/clear-glue" class="d-inline"
                  onsubmit="return confirm('Clear glue flag for this file?');">
              <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
              <input type="hidden" name="return" value="<?php echo View::e($returnUrl); ?>">
              <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
              <button type="submit" class="btn btn-outline-warning btn-xs">Clear glue</button>
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
            <?php if (Auth::isAdmin()
                && empty($item['srt_path'])
                && in_array($item['status'] ?? '', ['PENDING', 'FLAGGED', 'APPROVED', 'REJECTED', 'EXECUTED'], true)): ?>
            <form method="post" action="/queue/extract-captions" class="d-inline"
                  onsubmit="return confirm('Extract CC: start a job, or move this clip to the top of the active Captions cue?');">
              <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
              <input type="hidden" name="return" value="<?php echo View::e($returnUrl); ?>">
              <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
              <button type="submit" class="btn btn-outline-secondary btn-xs">Extract CC</button>
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

<?php if (Auth::isEditor() && in_array(($filters['status'] ?? ''), ['PENDING', 'APPROVED', 'FLAGGED', 'REJECTED'], true)): ?>
</form>
<?php endif; ?>

<!-- Pagination -->
<?php require dirname(__DIR__) . '/partials/pagination.php'; ?>

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
          <?php echo View::assetIdBlock($item); ?>
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

<?php if (Auth::isEditor() && in_array(($filters['status'] ?? ''), ['PENDING', 'FLAGGED'], true)): ?>
<!-- Bulk edit modal -->
<div class="modal fade" id="bulk-edit-modal" tabindex="-1" aria-labelledby="bulk-edit-title">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-secondary">
      <form method="post" action="/queue/bulk-edit" id="bulk-edit-form">
        <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
        <input type="hidden" name="return" value="<?php echo View::e($returnUrl); ?>">
        <div id="bulk-edit-ids"></div>
        <div class="modal-header border-secondary">
          <h5 class="modal-title" id="bulk-edit-title">Edit selected</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="path-text mb-3" style="font-size:0.82rem">
            Empty fields are left unchanged. Proposed names update from the new values;
            each file keeps its current time.
          </p>
          <div class="mb-3">
            <label class="form-label" for="bulk-edit-show">Show</label>
            <select name="show_id" id="bulk-edit-show" class="form-select">
              <option value="">— leave unchanged —</option>
              <?php foreach ($showList as $show): ?>
              <option value="<?php echo (int) $show['id']; ?>">
                <?php echo View::e($show['abbreviation']); ?>
                <?php if (!empty($show['canonical_name'])): ?>
                — <?php echo View::e($show['canonical_name']); ?>
                <?php endif; ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label" for="bulk-edit-type">Clean / Program</label>
            <select name="media_type_id" id="bulk-edit-type" class="form-select">
              <option value="">— leave unchanged —</option>
              <?php foreach ($mediaTypeList as $mt): ?>
              <option value="<?php echo (int) $mt['id']; ?>">
                <?php echo View::e($mt['name']); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-0">
            <label class="form-label" for="bulk-edit-date">Date</label>
            <input type="date" name="file_date" id="bulk-edit-date" class="form-control">
          </div>
        </div>
        <div class="modal-footer border-secondary">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm" id="bulk-edit-apply" disabled>Apply</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Captions (SRT) viewer modal -->
<div class="modal fade" id="captions-modal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content" style="background:var(--panel);border-color:var(--border-color)">
      <div class="modal-header border-secondary py-2">
        <h6 class="modal-title fs-6 mb-0" id="captions-modal-title">Captions</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="path-text mb-2" id="captions-modal-meta" style="font-size:0.78rem"></p>
        <div id="captions-modal-loading" class="d-none py-4 text-center">
          <div class="spinner-border spinner-border-sm text-secondary" role="status"></div>
          <span class="ms-2 path-text">Loading captions…</span>
        </div>
        <div id="captions-modal-error" class="alert alert-warning d-none py-2" style="font-size:0.82rem"></div>
        <div id="captions-modal-list" class="captions-cue-list"></div>
      </div>
    </div>
  </div>
</div>

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
            Click image to load <?php echo $previewDurationMin; ?>-minute preview (with audio).
            <span style="color:var(--text-soft)">First run may take 45–60 seconds to generate.</span>
          </p>
        </div>
        <div id="media-preview-video-wrap" class="d-none">
          <video id="media-preview-video" controls autoplay
                 style="width:100%;max-width:<?php echo $previewWidth; ?>px;max-height:<?php echo (int) round($previewHeight * 1.2); ?>px;background:#000;border-radius:6px">
          </video>
          <p class="path-text mt-2 mb-0" style="font-size:0.75rem">
            <?php echo $previewDurationMin; ?>-minute proxy · <?php echo $previewWidth; ?>×<?php echo $previewHeight; ?> · with audio
          </p>
        </div>
        <div id="media-preview-loading" class="d-none py-5">
          <div class="spinner-border spinner-border-sm text-secondary" role="status"></div>
          <span class="ms-2 path-text">Generating preview…</span>
          <p class="path-text mt-2 mb-0" style="font-size:0.72rem;color:var(--text-soft)">
            First-time generation often takes 45–60 seconds. Cached previews load immediately.
          </p>
        </div>

        <div id="media-meta-panel" class="text-start mt-3 pt-3" style="border-top:1px solid var(--border-color)">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span style="font-size:0.74rem;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:var(--text-soft)">
              FFprobe / Technical Metadata
            </span>
            <button type="button" class="btn btn-outline-secondary btn-xs" id="media-ffprobe-load-btn">
              Refresh FFprobe
            </button>
          </div>
          <p class="path-text mb-2" style="font-size:0.72rem">
            Scan-time summary below. Expand for full JSON or click Refresh to re-run ffprobe on the source file.
          </p>
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
    background: var(--info-soft);
}
#queue-table .queue-select-row:not(.queue-row-selected):hover > td {
    background: var(--hover-bg);
}
#queue-table .queue-check-cell {
    cursor: default;
}
#queue-table .queue-select-row:has(.row-check) {
    cursor: pointer;
}
.captions-cue-list {
    max-height: 60vh;
    overflow: auto;
    font-size: 0.84rem;
}
.captions-cue-row {
    display: grid;
    grid-template-columns: 7.5rem 1fr;
    gap: 0.5rem 0.75rem;
    padding: 0.45rem 0;
    border-bottom: 1px solid var(--border-color);
}
.captions-cue-time {
    font-variant-numeric: tabular-nums;
    color: var(--text-soft);
    font-size: 0.72rem;
    line-height: 1.35;
}
.captions-cue-text {
    white-space: pre-wrap;
    line-height: 1.4;
}
</style>

<?php ob_start(); ?>
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
                'a, button, input, form, select, textarea, label, [data-bs-toggle], .queue-thumb-btn, .queue-open-preview'
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

    var bulkForm = document.getElementById('bulk-edit-form');
    var bulkIds = document.getElementById('bulk-edit-ids');
    var bulkApply = document.getElementById('bulk-edit-apply');
    var bulkShow = document.getElementById('bulk-edit-show');
    var bulkType = document.getElementById('bulk-edit-type');
    var bulkDate = document.getElementById('bulk-edit-date');
    var bulkOpen = document.getElementById('bulk-edit-open');

    function bulkFieldFilled() {
        return (bulkShow && bulkShow.value !== '')
            || (bulkType && bulkType.value !== '')
            || (bulkDate && bulkDate.value !== '');
    }

    function syncBulkEditUi() {
        if (!bulkApply) {
            return;
        }
        var n = rowChecks().filter(function (cb) { return cb.checked; }).length;
        bulkApply.disabled = n === 0 || !bulkFieldFilled();
        if (bulkOpen) {
            bulkOpen.disabled = n === 0;
        }
    }

    function syncBulkIds() {
        if (!bulkIds) {
            return 0;
        }
        bulkIds.innerHTML = '';
        var checked = rowChecks().filter(function (cb) { return cb.checked; });
        checked.forEach(function (cb) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ids[]';
            input.value = cb.value;
            bulkIds.appendChild(input);
        });
        return checked.length;
    }

    if (bulkForm) {
        [bulkShow, bulkType, bulkDate].forEach(function (el) {
            if (el) {
                el.addEventListener('change', syncBulkEditUi);
                el.addEventListener('input', syncBulkEditUi);
            }
        });

        var bulkModal = document.getElementById('bulk-edit-modal');
        if (bulkModal) {
            bulkModal.addEventListener('show.bs.modal', function (e) {
                var n = syncBulkIds();
                if (n === 0) {
                    e.preventDefault();
                    alert('Select at least one file.');
                    return;
                }
                syncBulkEditUi();
            });
        }

        bulkForm.addEventListener('submit', function (e) {
            var n = syncBulkIds();
            if (n === 0) {
                e.preventDefault();
                alert('Select at least one file.');
                return;
            }
            if (!bulkFieldFilled()) {
                e.preventDefault();
                alert('Set at least one of show, type, or date.');
                return;
            }
            if (!confirm('Apply to ' + n + ' selected file(s)?')) {
                e.preventDefault();
            }
        });
    }

    var _updateSelectionUi = updateSelectionUi;
    updateSelectionUi = function () {
        _updateSelectionUi();
        syncBulkEditUi();
    };

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
})();

(function () {
    function initPreviewModal(retryCount) {
        var bs = window.bootstrap;
        if (!bs) {
            if (retryCount < 80) {
                setTimeout(function () { initPreviewModal(retryCount + 1); }, 50);
                return;
            }
            console.error('Bootstrap did not load — preview modal unavailable. Check /vendor/bootstrap/js/bootstrap.bundle.min.js (run ./setup.sh).');
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
                ['At scan', meta.metadata_extracted ? 'Yes' : 'No'],
                ['Asset ID', meta.public_id || '—'],
                ['Cache path', meta.media_cache_path || '—']
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
            if (video.dataset.objectUrl) {
                URL.revokeObjectURL(video.dataset.objectUrl);
                delete video.dataset.objectUrl;
            }
            video.removeAttribute('src');
            video.load();
            if (metaSummary) metaSummary.innerHTML = '';
            if (ffprobeRaw) ffprobeRaw.textContent = '';
            if (ffprobeRawWrap) {
                var collapse = bs.Collapse.getInstance(ffprobeRawWrap);
                if (collapse) collapse.hide();
            }
        }

        function openPreviewFromButton(btn) {
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
            img.classList.remove('d-none');
            modal.show();
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

        document.addEventListener('click', function (e) {
            var openLink = e.target.closest('.queue-open-preview');
            if (openLink) {
                e.preventDefault();
                e.stopPropagation();
                var row = openLink.closest('tr');
                var thumbBtn = row ? row.querySelector('.queue-thumb-btn') : null;
                if (thumbBtn) {
                    openPreviewFromButton(thumbBtn);
                }
                return;
            }

            var thumbBtn = e.target.closest('.queue-thumb-btn');
            if (thumbBtn) {
                e.preventDefault();
                e.stopPropagation();
                openPreviewFromButton(thumbBtn);
            }
        });

        img.addEventListener('click', function () {
            if (!currentFileId) return;
            stage.classList.add('d-none');
            loading.classList.remove('d-none');
            videoWrap.classList.add('d-none');
            video.pause();
            video.removeAttribute('src');
            if (video.dataset.objectUrl) {
                URL.revokeObjectURL(video.dataset.objectUrl);
                delete video.dataset.objectUrl;
            }

            var previewUrl = '/queue/preview/' + currentFileId + '?_=' + Date.now();
            fetch(previewUrl)
                .then(function (response) {
                    if (!response.ok) {
                        return response.text().then(function (text) {
                            var detail = (text || 'Preview unavailable').trim();
                            throw new Error(detail);
                        });
                    }
                    return response.blob();
                })
                .then(function (blob) {
                    if (!blob || blob.size === 0) {
                        throw new Error('Preview file was empty.');
                    }
                    var objectUrl = URL.createObjectURL(blob);
                    video.dataset.objectUrl = objectUrl;
                    video.onloadeddata = function () {
                        loading.classList.add('d-none');
                        videoWrap.classList.remove('d-none');
                    };
                    video.onerror = function () {
                        loading.classList.add('d-none');
                        stage.classList.remove('d-none');
                        alert('Preview could not be played in this browser.');
                    };
                    video.src = objectUrl;
                    video.load();
                })
                .catch(function (err) {
                    loading.classList.add('d-none');
                    stage.classList.remove('d-none');
                    alert(err && err.message ? err.message : 'Preview could not be generated.');
                });
        });
    }

    initPreviewModal(0);

    (function initCaptionsModal() {
        var modalEl = document.getElementById('captions-modal');
        if (!modalEl || typeof bootstrap === 'undefined') return;
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        var titleEl = document.getElementById('captions-modal-title');
        var metaEl = document.getElementById('captions-modal-meta');
        var listEl = document.getElementById('captions-modal-list');
        var loadingEl = document.getElementById('captions-modal-loading');
        var errorEl = document.getElementById('captions-modal-error');

        function openCaptions(fileId, filename) {
            titleEl.textContent = 'Captions — ' + (filename || ('#' + fileId));
            metaEl.textContent = '';
            listEl.innerHTML = '';
            errorEl.classList.add('d-none');
            errorEl.textContent = '';
            loadingEl.classList.remove('d-none');
            modal.show();

            fetch('/queue/captions/' + fileId, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    loadingEl.classList.add('d-none');
                    if (data.error) {
                        errorEl.textContent = data.error;
                        errorEl.classList.remove('d-none');
                        return;
                    }
                    metaEl.textContent = (data.cue_count || 0) + ' cues'
                        + (data.srt_path ? ' · ' + data.srt_path : '');
                    var html = '';
                    (data.cues || []).forEach(function (cue) {
                        html += '<div class="captions-cue-row">'
                            + '<div class="captions-cue-time">'
                            + escapeHtml(cue.start_label) + '<br>→ ' + escapeHtml(cue.end_label)
                            + '</div>'
                            + '<div class="captions-cue-text">' + escapeHtml(cue.text) + '</div>'
                            + '</div>';
                    });
                    listEl.innerHTML = html || '<p class="path-text mb-0">No cues.</p>';
                })
                .catch(function () {
                    loadingEl.classList.add('d-none');
                    errorEl.textContent = 'Could not load captions.';
                    errorEl.classList.remove('d-none');
                });
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.queue-open-captions');
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            openCaptions(btn.getAttribute('data-file-id'), btn.getAttribute('data-filename'));
        });
    })();
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

(function () {
  function selectionBusy() {
    return !!(document.querySelector('.row-check:checked')
      || document.querySelector('.modal.show')
      || (document.activeElement && (
        document.activeElement.tagName === 'INPUT'
        || document.activeElement.tagName === 'SELECT'
        || document.activeElement.tagName === 'TEXTAREA'
      )));
  }
  function pollCounts() {
    if (document.hidden || selectionBusy()) return;
    fetch('/queue/list-status', {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      cache: 'no-store'
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.status_counts) return;
        var counts = data.status_counts;
        var all = 0;
        Object.keys(counts).forEach(function (k) { all += Number(counts[k] || 0); });
        document.querySelectorAll('[data-queue-status-pill]').forEach(function (pill) {
          var key = pill.getAttribute('data-queue-status-pill');
          var el = pill.querySelector('.queue-status-cnt');
          if (!el) return;
          el.textContent = String(key === 'ALL' ? all : (counts[key] || 0));
        });
        var hint = document.getElementById('queue-approved-hint');
        var approved = Number(data.approved_count || 0);
        if (hint) {
          if (approved > 0) {
            hint.innerHTML = ' <a href="/execute" class="ms-1"><span id="queue-approved-count">'
              + approved + '</span> approved — ready to execute</a>';
          } else {
            hint.innerHTML = '';
          }
        }
      })
      .catch(function () {});
  }
  setInterval(pollCounts, 8000);
})();
</script>
<?php
$extraScripts = ($extraScripts ?? '') . ob_get_clean();
