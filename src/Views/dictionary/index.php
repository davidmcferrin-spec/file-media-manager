<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var list<array<string, mixed>> $shows */
/** @var array<int, int> $scheduleCounts */
/** @var array<string, mixed>|null $editShow */
?>

<?php
$workflowStepId = 'shows';
require dirname(__DIR__) . '/partials/workflow_step.php';
require dirname(__DIR__) . '/shows/_nav.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Shows</h1>
    <p class="mb-0" style="color:var(--text-soft);font-size:0.8rem;">
      Canonical show names, abbreviations, and path/filename aliases used by the classifier.
    </p>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><?php echo $editShow ? 'Edit Show' : 'Add Show'; ?></div>
      <div class="card-body">
        <form method="post" action="<?php echo $editShow ? '/dictionary/update' : '/dictionary/create'; ?>">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <?php if ($editShow): ?>
          <input type="hidden" name="id" value="<?php echo (int) $editShow['id']; ?>">
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Canonical Name</label>
            <input type="text" name="canonical_name" class="form-control" required
                   value="<?php echo View::e($editShow['canonical_name'] ?? ''); ?>"
                   placeholder="Cuomo">
          </div>

          <div class="mb-3">
            <label class="form-label">Abbreviation</label>
            <input type="text" name="abbreviation" class="form-control" required
                   value="<?php echo View::e($editShow['abbreviation'] ?? ''); ?>"
                   placeholder="CUOMO" style="text-transform:uppercase;">
            <div class="form-text" style="color:var(--text-soft)">Used in folder and filename policy.</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Aliases</label>
            <?php
            $aliasText = '';
            if ($editShow && !empty($editShow['aliases'])) {
                $decoded = json_decode((string) $editShow['aliases'], true);
                if (is_array($decoded)) {
                    $aliasText = implode("\n", $decoded);
                }
            }
            ?>
            <textarea name="aliases" class="form-control" rows="4"
                      placeholder="cuomo&#10;endgoal&#10;eg"><?php echo View::e($aliasText); ?></textarea>
            <div class="form-text" style="color:var(--text-soft)">One per line — matched against paths and filenames.</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2"><?php echo View::e($editShow['notes'] ?? ''); ?></textarea>
          </div>

          <?php if ($editShow): ?>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="active" id="show-active"
                   <?php echo !empty($editShow['active']) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="show-active">Active</label>
          </div>
          <?php
          $editScheduleCount = $scheduleCounts[(int) $editShow['id']] ?? 0;
          ?>
          <div class="mb-3 p-2 rounded" style="background:var(--hover-bg);font-size:0.78rem">
            Program schedule:
            <a href="/schedule?show_id=<?php echo (int) $editShow['id']; ?>">
              <?php echo $editScheduleCount; ?> hourly block<?php echo $editScheduleCount === 1 ? '' : 's'; ?>
            </a>
            · <a href="/schedule?show_id=<?php echo (int) $editShow['id']; ?>&amp;add=1">Add block</a>
          </div>
          <?php endif; ?>

          <div class="d-flex gap-2 flex-wrap">
            <button type="submit" class="btn btn-primary btn-sm">
              <?php echo $editShow ? 'Save Changes' : 'Add Show'; ?>
            </button>
            <?php if ($editShow): ?>
            <a href="/dictionary" class="btn btn-outline-secondary btn-sm">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
        <?php if ($editShow): ?>
        <form method="post" action="/dictionary/delete" class="mt-3"
              onsubmit="return confirm('Delete this show? Timeline blocks and gap markers for it will be removed. Catalog files that still reference it will block deletion.');">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <input type="hidden" name="id" value="<?php echo (int) $editShow['id']; ?>">
          <button type="submit" class="btn btn-outline-danger btn-sm">Delete Show</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">Shows (<?php echo count($shows); ?>)</div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Name</th>
              <th>Abbr</th>
              <th>Schedule</th>
              <th>Aliases</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($shows === []): ?>
            <tr>
              <td colspan="6" class="text-center py-4" style="color:var(--text-soft)">
                No shows in dictionary yet.
              </td>
            </tr>
            <?php else: ?>
            <?php foreach ($shows as $show): ?>
            <?php
            $aliases = json_decode((string) ($show['aliases'] ?? '[]'), true);
            $aliasDisplay = is_array($aliases) ? implode(', ', $aliases) : '';
            $schedCount = $scheduleCounts[(int) $show['id']] ?? 0;
            ?>
            <tr>
              <td>
                <strong>
                  <a href="/schedule?show_id=<?php echo (int) $show['id']; ?>">
                    <?php echo View::e($show['canonical_name']); ?>
                  </a>
                </strong>
                <?php if (!empty($show['notes'])): ?>
                <div class="path-text"><?php echo View::e($show['notes']); ?></div>
                <?php endif; ?>
              </td>
              <td><code><?php echo View::e($show['abbreviation']); ?></code></td>
              <td class="text-nowrap">
                <?php if ($schedCount > 0): ?>
                <a href="/schedule?show_id=<?php echo (int) $show['id']; ?>" class="badge bg-info text-decoration-none">
                  <?php echo $schedCount; ?> block<?php echo $schedCount === 1 ? '' : 's'; ?>
                </a>
                <?php else: ?>
                <a href="/schedule?show_id=<?php echo (int) $show['id']; ?>&amp;add=1" class="path-text">Add schedule</a>
                <?php endif; ?>
              </td>
              <td class="path-text"><?php echo View::e($aliasDisplay ?: '—'); ?></td>
              <td>
                <?php if (!empty($show['active'])): ?>
                <span class="badge bg-success">Active</span>
                <?php else: ?>
                <span class="badge bg-secondary">Inactive</span>
                <?php endif; ?>
              </td>
              <td class="text-end text-nowrap">
                <a href="/dictionary?edit=<?php echo (int) $show['id']; ?>"
                   class="btn btn-outline-secondary btn-xs">Edit</a>
                <form method="post" action="/dictionary/delete" class="d-inline"
                      onsubmit="return confirm('Delete this show? Timeline blocks for it will be removed.');">
                  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int) $show['id']; ?>">
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
  </div>
</div>

<div class="card mt-4">
  <div class="card-header">Merge Shows</div>
  <div class="card-body">
    <form method="post" action="/dictionary/merge"
          onsubmit="return confirm('Merge selected shows into the canonical entry? Schedule rows, queue files, and conversion rules will be rewired.');">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <p class="path-text mb-3" style="font-size:0.78rem">
        Consolidate duplicate or auto-generated shows into one dictionary entry
        (e.g. merge schedule-created duplicates into the canonical abbreviation).
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
