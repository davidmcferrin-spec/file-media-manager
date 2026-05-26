<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var list<array<string, mixed>> $activeSources */
/** @var list<array<string, mixed>> $recentJobs */
/** @var bool $ffprobeOk */
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">NAS Scanner</h1>
    <p class="mb-0" style="color:var(--text-soft);font-size:0.8rem;">
      Walk a NAS mount, classify files against naming policy, and queue them for review.
    </p>
  </div>
</div>

<?php if (!$ffprobeOk): ?>
<div class="alert alert-warning mb-4" style="font-size:0.84rem;">
  FFprobe not found at configured path — scans will run without duration/codec metadata.
  Uncheck "Extract metadata" or install FFmpeg on the server.
</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">New Scan</div>
      <div class="card-body">
        <form method="post" action="/scan/start">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">

          <div class="mb-3">
            <label class="form-label">NAS Source</label>
            <select name="source_id" class="form-select" required>
              <?php foreach ($activeSources as $source): ?>
              <option value="<?php echo (int) $source['id']; ?>">
                <?php echo View::e($source['name']); ?> — <?php echo View::e($source['mount_path']); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Subpath (optional)</label>
            <input type="text" name="subpath" class="form-control" value="cuomo"
                   placeholder="cuomo">
            <div class="form-text" style="color:var(--text-soft)">
              Limit scan to a folder under the mount, e.g. <code>cuomo</code> for the Cuomo pilot.
            </div>
          </div>

          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="extract_metadata"
                   id="extract-metadata" checked>
            <label class="form-check-label" for="extract-metadata">
              Extract FFprobe metadata (duration, codecs)
            </label>
          </div>

          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="use_dev_list"
                   id="use-dev-list">
            <label class="form-check-label" for="use-dev-list">
              Dev mode — scan from <code>example_file_trees</code> listing (no NAS mount)
            </label>
          </div>

          <button type="submit" class="btn btn-primary btn-sm">Start Scan</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">Recent Scan Jobs</div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>ID</th>
              <th>Source</th>
              <th>Subpath</th>
              <th>Status</th>
              <th>Progress</th>
              <th>Started</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($recentJobs === []): ?>
            <tr>
              <td colspan="7" class="text-center py-4" style="color:var(--text-soft)">No scans yet.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($recentJobs as $job): ?>
            <?php
            $total = (int) ($job['total_files'] ?? 0);
            $done  = (int) ($job['processed_files'] ?? 0);
            $pct   = $total > 0 ? round(($done / $total) * 100) : 0;
            $status = (string) $job['status'];
            $canStopRow = in_array($status, ['PENDING', 'RUNNING'], true);
            $canDeleteRow = $status !== 'RUNNING';
            ?>
            <tr>
              <td><a href="/scan/<?php echo (int) $job['id']; ?>">#<?php echo (int) $job['id']; ?></a></td>
              <td><?php echo View::e($job['source_name']); ?></td>
              <td class="path-text"><?php echo View::e($job['subpath'] ?: '—'); ?></td>
              <td>
                <?php
                $badge  = match ($status) {
                    'COMPLETED' => 'success',
                    'RUNNING'   => 'primary',
                    'FAILED'    => 'danger',
                    'CANCELLED' => 'warning',
                    default     => 'secondary',
                };
                ?>
                <span class="badge bg-<?php echo $badge; ?>"><?php echo View::e($status); ?></span>
              </td>
              <td style="min-width:120px">
                <?php if ($total > 0): ?>
                <div class="progress" style="height:6px">
                  <div class="progress-bar" style="width:<?php echo $pct; ?>%"></div>
                </div>
                <span style="font-size:0.72rem;color:var(--text-soft)"><?php echo $done; ?> / <?php echo $total; ?></span>
                <?php else: ?>
                —
                <?php endif; ?>
              </td>
              <td class="path-text"><?php echo View::e(substr((string) ($job['started_at'] ?? $job['created_at']), 0, 16)); ?></td>
              <td class="text-end text-nowrap">
                <?php if ($canStopRow): ?>
                <form method="post" action="/scan/cancel" class="d-inline"
                      onsubmit="return confirm('Stop scan #<?php echo (int) $job['id']; ?>?');">
                  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
                  <button type="submit" class="btn btn-outline-danger btn-xs">Stop</button>
                </form>
                <?php endif; ?>
                <?php if ($canDeleteRow): ?>
                <form method="post" action="/scan/delete" class="d-inline"
                      onsubmit="return confirm('Delete scan #<?php echo (int) $job['id']; ?> and its queued files?');">
                  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
                  <button type="submit" class="btn btn-outline-secondary btn-xs">Delete</button>
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
  </div>
</div>
