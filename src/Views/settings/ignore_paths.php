<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Repositories\IgnorePathRepository;
use MediaManager\Support\View;

/** @var list<array<string, mixed>> $ignorePaths */
/** @var list<array<string, mixed>> $sources */
?>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">Add Ignore Path</div>
      <div class="card-body">
        <p class="mb-3" style="color:var(--text-soft);font-size:0.82rem;">
          Files and folders under these paths are skipped during scans. Use a path relative to the NAS
          mount (e.g. <code>SPECIAL PROGRAMMING</code>) or a full absolute path.
        </p>
        <form method="post" action="/settings/ignore-paths/create">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">

          <div class="mb-3">
            <label class="form-label">NAS Source</label>
            <select name="source_id" class="form-select">
              <option value="">All sources (absolute path)</option>
              <?php foreach ($sources as $source): ?>
              <option value="<?php echo (int) $source['id']; ?>">
                <?php echo View::e($source['name']); ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text" style="color:var(--text-soft)">
              When set, path is relative to this mount.
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Path</label>
            <input type="text" name="path" class="form-control" required
                   placeholder="SPECIAL PROGRAMMING">
          </div>

          <div class="mb-3">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2"
                      placeholder="Why this tree is excluded"></textarea>
          </div>

          <button type="submit" class="btn btn-primary btn-sm">Add Ignore Path</button>
        </form>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header">Built-in Rules</div>
      <div class="card-body" style="font-size:0.82rem;color:var(--text-soft)">
        <p class="mb-2">Always excluded (not editable):</p>
        <ul class="mb-0 ps-3">
          <li><code>.Trash/</code></li>
          <li><code>_ShareBrowserVolumeUID_</code></li>
          <li><code>summary-*.html</code></li>
          <li>Hidden dotfiles</li>
          <li><code>README.txt</code></li>
        </ul>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">Ignore Paths (<?php echo count($ignorePaths); ?>)</div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Resolved Prefix</th>
              <th>Notes</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($ignorePaths === []): ?>
            <tr>
              <td colspan="4" class="text-center py-4" style="color:var(--text-soft)">
                No custom ignore paths configured.
              </td>
            </tr>
            <?php else: ?>
            <?php foreach ($ignorePaths as $row): ?>
            <?php
            $resolved = IgnorePathRepository::resolvePrefix(
                (string) $row['path'],
                $row['source_mount'] ?? null
            );
            ?>
            <tr>
              <td>
                <code style="font-size:0.78rem;word-break:break-all"><?php echo View::e($resolved); ?></code>
                <div class="path-text">
                  <?php if (!empty($row['source_name'])): ?>
                  <?php echo View::e($row['source_name']); ?> /
                  <?php endif; ?>
                  <?php echo View::e($row['path']); ?>
                </div>
              </td>
              <td class="path-text"><?php echo View::e($row['notes'] ?: '—'); ?></td>
              <td>
                <?php if (!empty($row['active'])): ?>
                <span class="badge bg-success">Active</span>
                <?php else: ?>
                <span class="badge bg-secondary">Inactive</span>
                <?php endif; ?>
              </td>
              <td class="text-end text-nowrap">
                <button type="button" class="btn btn-outline-secondary btn-xs"
                        data-bs-toggle="collapse"
                        data-bs-target="#ignore-edit-<?php echo (int) $row['id']; ?>">
                  Edit
                </button>
                <form method="post" action="/settings/ignore-paths/delete" class="d-inline"
                      onsubmit="return confirm('Remove this ignore path?');">
                  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                  <button type="submit" class="btn btn-outline-danger btn-xs">Delete</button>
                </form>
              </td>
            </tr>
            <tr class="collapse" id="ignore-edit-<?php echo (int) $row['id']; ?>">
              <td colspan="4" class="p-3" style="background:var(--form-bg);">
                <form method="post" action="/settings/ignore-paths/update">
                  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                  <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                      <label class="form-label">Source</label>
                      <select name="source_id" class="form-select form-select-sm">
                        <option value="">Absolute path</option>
                        <?php foreach ($sources as $source): ?>
                        <option value="<?php echo (int) $source['id']; ?>"
                          <?php echo (int) ($row['source_id'] ?? 0) === (int) $source['id'] ? 'selected' : ''; ?>>
                          <?php echo View::e($source['name']); ?>
                        </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Path</label>
                      <input type="text" name="path" class="form-control form-control-sm" required
                             value="<?php echo View::e($row['path']); ?>">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Notes</label>
                      <input type="text" name="notes" class="form-control form-control-sm"
                             value="<?php echo View::e($row['notes']); ?>">
                    </div>
                    <div class="col-md-2">
                      <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="active"
                               id="ignore-active-<?php echo (int) $row['id']; ?>"
                               <?php echo !empty($row['active']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="ignore-active-<?php echo (int) $row['id']; ?>">Active</label>
                      </div>
                      <button type="submit" class="btn btn-primary btn-xs w-100">Save</button>
                    </div>
                  </div>
                </form>
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
