<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var list<array<string, mixed>> $mediaTypes */
?>

<div class="card">
  <div class="card-header">Media Types</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Name</th>
          <th>Abbreviation</th>
          <th>Description</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($mediaTypes as $mt): ?>
        <tr>
          <td colspan="5" class="p-0">
            <form method="post" action="/settings/media-types" class="p-3">
              <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
              <input type="hidden" name="id" value="<?php echo (int) $mt['id']; ?>">
              <div class="row g-2 align-items-center">
                <div class="col-md-2">
                  <input type="text" name="name" class="form-control form-control-sm" required
                         value="<?php echo View::e($mt['name']); ?>">
                </div>
                <div class="col-md-2">
                  <input type="text" name="abbreviation" class="form-control form-control-sm" required
                         value="<?php echo View::e($mt['abbreviation']); ?>">
                </div>
                <div class="col-md-4">
                  <input type="text" name="description" class="form-control form-control-sm"
                         value="<?php echo View::e($mt['description']); ?>">
                </div>
                <div class="col-md-2">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="active"
                           id="mt-active-<?php echo (int) $mt['id']; ?>"
                           <?php echo !empty($mt['active']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="mt-active-<?php echo (int) $mt['id']; ?>">Active</label>
                  </div>
                </div>
                <div class="col-md-2 text-end">
                  <button type="submit" class="btn btn-primary btn-xs">Save</button>
                </div>
              </div>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<p class="mt-3" style="color:var(--text-soft);font-size:0.82rem;">
  ISO and GISO files are stored under the <strong>ISO</strong> folder per naming policy.
  Conversion rules map legacy tokens (e.g. "live clean", "pretape") to these types.
</p>
