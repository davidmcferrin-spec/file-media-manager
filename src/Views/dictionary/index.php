<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var list<array<string, mixed>> $shows */
/** @var array<string, mixed>|null $editShow */
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Show Dictionary</h1>
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
          <?php endif; ?>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm">
              <?php echo $editShow ? 'Save Changes' : 'Add Show'; ?>
            </button>
            <?php if ($editShow): ?>
            <a href="/dictionary" class="btn btn-outline-secondary btn-sm">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
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
              <th>Aliases</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($shows === []): ?>
            <tr>
              <td colspan="5" class="text-center py-4" style="color:var(--text-soft)">
                No shows in dictionary yet.
              </td>
            </tr>
            <?php else: ?>
            <?php foreach ($shows as $show): ?>
            <?php
            $aliases = json_decode((string) ($show['aliases'] ?? '[]'), true);
            $aliasDisplay = is_array($aliases) ? implode(', ', $aliases) : '';
            ?>
            <tr>
              <td>
                <strong><?php echo View::e($show['canonical_name']); ?></strong>
                <?php if (!empty($show['notes'])): ?>
                <div class="path-text"><?php echo View::e($show['notes']); ?></div>
                <?php endif; ?>
              </td>
              <td><code><?php echo View::e($show['abbreviation']); ?></code></td>
              <td class="path-text"><?php echo View::e($aliasDisplay ?: '—'); ?></td>
              <td>
                <?php if (!empty($show['active'])): ?>
                <span class="badge bg-success">Active</span>
                <?php else: ?>
                <span class="badge bg-secondary">Inactive</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <a href="/dictionary?edit=<?php echo (int) $show['id']; ?>"
                   class="btn btn-outline-secondary btn-xs">Edit</a>
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
