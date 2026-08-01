<?php

declare(strict_types=1);

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var array<string, mixed> $item */
/** @var list<array<string, mixed>> $sourceFiles */
/** @var array<string, mixed>|null $outputFile */
/** @var bool $durationOk */
/** @var float|null $expected */
/** @var float|null $actual */

$status = (string) ($item['status'] ?? '');
$jobId = (int) $item['id'];
$isAdmin = Auth::isAdmin();
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Glue Job #<?php echo $jobId; ?></h1>
    <p class="mb-0 path-text" style="font-size:0.78rem"><?php echo View::e((string) $item['glue_group_key']); ?></p>
  </div>
  <a href="/glue" class="btn btn-outline-secondary btn-sm">Back to Glue</a>
</div>

<div class="row g-4 mb-4">
  <div class="col-md-4">
    <div class="card">
      <div class="card-body">
        <div class="mb-2">
          <span style="color:var(--text-soft)">Status</span>
          <?php
          $badge = match ($status) {
              'READY_FOR_QC' => 'bg-warning text-dark',
              'APPROVED'     => 'bg-info text-dark',
              'DONE'         => 'bg-success',
              'FAILED'       => 'bg-danger',
              'RUNNING'      => 'bg-primary',
              default        => 'bg-secondary',
          };
          ?>
          <span class="badge <?php echo $badge; ?> ms-2"><?php echo View::e($status); ?></span>
        </div>
        <div class="mb-2">
          <span style="color:var(--text-soft)">Created</span>
          <span class="ms-2 path-text" style="font-size:0.8rem">
            <?php echo View::e((string) ($item['created_by_email'] ?? '')); ?>
            · <?php echo View::e((string) ($item['created_at'] ?? '')); ?>
          </span>
        </div>
        <?php if (!empty($item['qc_at'])): ?>
        <div class="mb-2">
          <span style="color:var(--text-soft)">QC</span>
          <span class="ms-2 path-text" style="font-size:0.8rem">
            <?php echo View::e((string) ($item['qc_by_email'] ?? '')); ?>
            · <?php echo View::e((string) $item['qc_at']); ?>
          </span>
        </div>
        <?php endif; ?>
        <div class="mb-2">
          <span style="color:var(--text-soft)">Expected duration</span>
          <strong class="ms-2"><?php echo View::duration($expected); ?></strong>
        </div>
        <div class="mb-2">
          <span style="color:var(--text-soft)">Output duration</span>
          <strong class="ms-2"><?php echo View::duration($actual); ?></strong>
          <?php if ($status === 'READY_FOR_QC' || $status === 'APPROVED' || $status === 'DONE'): ?>
            <?php if ($durationOk): ?>
            <span class="badge bg-success ms-1">OK</span>
            <?php else: ?>
            <span class="badge bg-danger ms-1">Mismatch</span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php if (!empty($item['output_filesize_bytes'])): ?>
        <div class="mb-2">
          <span style="color:var(--text-soft)">Output size</span>
          <strong class="ms-2"><?php echo View::e(number_format((int) $item['output_filesize_bytes'])); ?> B</strong>
        </div>
        <?php endif; ?>
        <?php if (!empty($item['error_message'])): ?>
        <div class="alert alert-danger py-2 mt-3 mb-0" style="font-size:0.78rem">
          <?php echo View::e((string) $item['error_message']); ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($item['notes'])): ?>
        <div class="path-text mt-3" style="font-size:0.78rem"><?php echo View::e((string) $item['notes']); ?></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($isAdmin): ?>
    <div class="card mt-3">
      <div class="card-header">Actions</div>
      <div class="card-body d-flex flex-column gap-2">
        <?php if (in_array($status, ['PENDING', 'FAILED'], true)): ?>
        <form method="post" action="/glue/run"
              onsubmit="return confirm('Run ffmpeg concat now? This may take several minutes.');">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <input type="hidden" name="id" value="<?php echo $jobId; ?>">
          <button type="submit" class="btn btn-primary btn-sm w-100">Run concat</button>
        </form>
        <?php endif; ?>

        <?php if ($status === 'READY_FOR_QC'): ?>
        <form method="post" action="/glue/qc-approve"
              onsubmit="return confirm('Approve glued output for source deletion?');">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <input type="hidden" name="id" value="<?php echo $jobId; ?>">
          <button type="submit" class="btn btn-success btn-sm w-100">Approve QC</button>
        </form>
        <form method="post" action="/glue/qc-reject"
              onsubmit="return confirm('Reject QC and discard the glued output file?');">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <input type="hidden" name="id" value="<?php echo $jobId; ?>">
          <button type="submit" class="btn btn-outline-danger btn-sm w-100">Reject QC</button>
        </form>
        <?php endif; ?>

        <?php if ($status === 'APPROVED'): ?>
        <form method="post" action="/glue/delete-sources"
              onsubmit="return confirm('Permanently delete all source part files from disk and catalog? This cannot be undone.');">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <input type="hidden" name="id" value="<?php echo $jobId; ?>">
          <button type="submit" class="btn btn-danger btn-sm w-100">Delete source parts</button>
        </form>
        <?php endif; ?>

        <?php if (in_array($status, ['FAILED', 'CANCELLED'], true)): ?>
        <form method="post" action="/glue/retry"
              onsubmit="return confirm('Reset this job to PENDING?');">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <input type="hidden" name="id" value="<?php echo $jobId; ?>">
          <button type="submit" class="btn btn-outline-secondary btn-sm w-100">Reset to PENDING</button>
        </form>
        <?php endif; ?>

        <?php if (in_array($status, ['PENDING', 'FAILED', 'READY_FOR_QC'], true)): ?>
        <form method="post" action="/glue/cancel"
              onsubmit="return confirm('Cancel this glue job?');">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <input type="hidden" name="id" value="<?php echo $jobId; ?>">
          <button type="submit" class="btn btn-outline-warning btn-sm w-100">Cancel job</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-md-8">
    <div class="card mb-3">
      <div class="card-header">Source parts (<?php echo count($sourceFiles); ?>)</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle" style="font-size:0.78rem">
          <thead>
            <tr>
              <th>Part</th>
              <th>File</th>
              <th>Duration</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($sourceFiles === []): ?>
            <tr><td colspan="5" class="path-text text-center py-3">Source parts no longer in catalog.</td></tr>
            <?php else: ?>
            <?php foreach ($sourceFiles as $f): ?>
            <tr>
              <td><code><?php echo View::e((string) ($f['glue_part_index'] ?? '—')); ?></code></td>
              <td>
                <div class="path-filename"><?php echo View::e((string) ($f['original_filename'] ?? '')); ?></div>
                <div class="path-text" style="font-size:0.68rem"><?php echo View::e((string) ($f['original_path'] ?? '')); ?></div>
                <?php echo View::assetIdBlock($f); ?>
              </td>
              <td><?php echo View::duration($f['duration_seconds'] ?? null); ?></td>
              <td><span class="badge bg-secondary"><?php echo View::e((string) ($f['status'] ?? '')); ?></span></td>
              <td class="text-end">
                <a href="/queue?status=ALL&amp;file_id=<?php echo (int) $f['id']; ?>"
                   class="btn btn-outline-secondary btn-xs">Catalog</a>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Glued output</div>
      <div class="card-body">
        <?php if ($outputFile !== null): ?>
        <div class="path-filename mb-1"><?php echo View::e((string) ($outputFile['original_filename'] ?? '')); ?></div>
        <div class="path-text mb-2" style="font-size:0.78rem"><?php echo View::e((string) ($outputFile['original_path'] ?? '')); ?></div>
        <?php echo View::assetIdBlock($outputFile); ?>
        <div class="mb-2">
          <span class="badge bg-secondary"><?php echo View::e((string) ($outputFile['status'] ?? '')); ?></span>
          <span class="ms-2 path-text" style="font-size:0.78rem">
            <?php echo View::duration($outputFile['duration_seconds'] ?? null); ?>
            · <?php echo View::e((string) ($outputFile['resolution'] ?? '—')); ?>
            · <?php echo View::e((string) ($outputFile['codec_video'] ?? '—')); ?>
          </span>
        </div>
        <a href="/queue?status=ALL&amp;file_id=<?php echo (int) $outputFile['id']; ?>"
           class="btn btn-outline-secondary btn-sm">Open in Catalog</a>
        <?php elseif (!empty($item['output_path'])): ?>
        <div class="path-text"><?php echo View::e((string) $item['output_path']); ?></div>
        <div class="path-text mt-2" style="font-size:0.78rem">Catalog row missing — file may still be on disk.</div>
        <?php else: ?>
        <div class="path-text">No output yet. Run concat to create <code>*_GLUED.ext</code> beside part 0.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
