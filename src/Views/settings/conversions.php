<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var list<array<string, mixed>> $rules */
/** @var list<array<string, mixed>> $shows */
/** @var list<array<string, mixed>> $mediaTypes */
?>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">Add Conversion Rule</div>
      <div class="card-body">
        <p class="mb-3" style="color:var(--text-soft);font-size:0.82rem;">
          Map messy tokens from legacy filenames to a canonical show or media type.
        </p>
        <form method="post" action="/settings/conversions/create" id="conversion-create-form">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">

          <div class="mb-3">
            <label class="form-label">Category</label>
            <select name="category" class="form-select" id="conv-category">
              <option value="media_type">Media Type</option>
              <option value="show">Show</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Alias</label>
            <input type="text" name="alias" class="form-control" required
                   placeholder="live clean">
            <div class="form-text" style="color:var(--text-soft)">Matched case-insensitively.</div>
          </div>

          <div class="mb-3" id="conv-show-wrap" style="display:none;">
            <label class="form-label">Target Show</label>
            <select name="show_id" class="form-select">
              <option value="">— Select —</option>
              <?php foreach ($shows as $show): ?>
              <option value="<?php echo (int) $show['id']; ?>">
                <?php echo View::e($show['canonical_name']); ?> (<?php echo View::e($show['abbreviation']); ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3" id="conv-media-wrap">
            <label class="form-label">Target Media Type</label>
            <select name="media_type_id" class="form-select">
              <option value="">— Select —</option>
              <?php foreach ($mediaTypes as $mt): ?>
              <option value="<?php echo (int) $mt['id']; ?>">
                <?php echo View::e($mt['name']); ?> (<?php echo View::e($mt['abbreviation']); ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control" placeholder="Optional">
          </div>

          <button type="submit" class="btn btn-primary btn-sm">Add Rule</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">Conversion Rules (<?php echo count($rules); ?>)</div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Alias</th>
              <th>Category</th>
              <th>Maps To</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($rules === []): ?>
            <tr>
              <td colspan="5" class="text-center py-4" style="color:var(--text-soft)">No rules yet.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($rules as $rule): ?>
            <tr>
              <td><code><?php echo View::e($rule['alias']); ?></code></td>
              <td><?php echo View::e($rule['category']); ?></td>
              <td>
                <?php if ($rule['category'] === 'show'): ?>
                <?php echo View::e($rule['show_abbreviation'] ?? '—'); ?>
                <?php else: ?>
                <?php echo View::e($rule['media_type_name'] ?? '—'); ?>
                <?php endif; ?>
                <?php if (!empty($rule['notes'])): ?>
                <div class="path-text"><?php echo View::e($rule['notes']); ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($rule['active'])): ?>
                <span class="badge bg-success">Active</span>
                <?php else: ?>
                <span class="badge bg-secondary">Inactive</span>
                <?php endif; ?>
              </td>
              <td class="text-end text-nowrap">
                <button type="button" class="btn btn-outline-secondary btn-xs"
                        data-bs-toggle="collapse"
                        data-bs-target="#edit-rule-<?php echo (int) $rule['id']; ?>">
                  Edit
                </button>
                <form method="post" action="/settings/conversions/delete" class="d-inline"
                      onsubmit="return confirm('Delete this conversion rule?');">
                  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int) $rule['id']; ?>">
                  <button type="submit" class="btn btn-outline-danger btn-xs">Delete</button>
                </form>
              </td>
            </tr>
            <tr class="collapse" id="edit-rule-<?php echo (int) $rule['id']; ?>">
              <td colspan="5" class="p-3" style="background:var(--form-bg);">
                <form method="post" action="/settings/conversions/update">
                  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int) $rule['id']; ?>">
                  <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                      <label class="form-label">Alias</label>
                      <input type="text" name="alias" class="form-control" required
                             value="<?php echo View::e($rule['alias']); ?>">
                    </div>
                    <?php if ($rule['category'] === 'show'): ?>
                    <div class="col-md-4">
                      <label class="form-label">Show</label>
                      <select name="show_id" class="form-select">
                        <?php foreach ($shows as $show): ?>
                        <option value="<?php echo (int) $show['id']; ?>"
                          <?php echo (int) $rule['show_id'] === (int) $show['id'] ? 'selected' : ''; ?>>
                          <?php echo View::e($show['abbreviation']); ?>
                        </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <?php else: ?>
                    <div class="col-md-4">
                      <label class="form-label">Media Type</label>
                      <select name="media_type_id" class="form-select">
                        <?php foreach ($mediaTypes as $mt): ?>
                        <option value="<?php echo (int) $mt['id']; ?>"
                          <?php echo (int) $rule['media_type_id'] === (int) $mt['id'] ? 'selected' : ''; ?>>
                          <?php echo View::e($mt['name']); ?>
                        </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-3">
                      <label class="form-label">Notes</label>
                      <input type="text" name="notes" class="form-control"
                             value="<?php echo View::e($rule['notes']); ?>">
                    </div>
                    <div class="col-md-2">
                      <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="active"
                               id="rule-active-<?php echo (int) $rule['id']; ?>"
                               <?php echo !empty($rule['active']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="rule-active-<?php echo (int) $rule['id']; ?>">Active</label>
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

<script>
(function () {
    var category = document.getElementById('conv-category');
    var showWrap = document.getElementById('conv-show-wrap');
    var mediaWrap = document.getElementById('conv-media-wrap');
    if (!category) return;
    function sync() {
        var isShow = category.value === 'show';
        showWrap.style.display = isShow ? '' : 'none';
        mediaWrap.style.display = isShow ? 'none' : '';
    }
    category.addEventListener('change', sync);
    sync();
})();
</script>
