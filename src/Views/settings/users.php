<?php

declare(strict_types=1);

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var list<array<string, mixed>> $users */
/** @var int|null $editUserId */
/** @var array<string, mixed>|null $editUser */
?>

<div class="card mb-4">
  <div class="card-header">Create Local User</div>
  <div class="card-body">
    <form method="post" action="/settings/users/create" class="row g-3">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <div class="col-md-4">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Display Name</label>
        <input type="text" name="display_name" class="form-control" required>
      </div>
      <div class="col-md-2">
        <label class="form-label">Role</label>
        <select name="role" class="form-select">
          <option value="editor">Editor</option>
          <option value="admin">Admin</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required minlength="8">
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-primary btn-sm">Create User</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">Users</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th>Email</th>
          <th>Name</th>
          <th>Role</th>
          <th>Auth</th>
          <th>Active</th>
          <th>Last Login</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $user): ?>
        <tr>
          <td><?php echo View::e($user['email']); ?></td>
          <td><?php echo View::e($user['display_name']); ?></td>
          <td><span class="badge bg-secondary"><?php echo View::e($user['role']); ?></span></td>
          <td style="font-size:0.78rem"><?php echo View::e($user['auth_source'] ?? 'local'); ?></td>
          <td><?php echo !empty($user['active']) ? 'Yes' : 'No'; ?></td>
          <td class="path-text"><?php echo View::e(substr((string) ($user['last_login_at'] ?? ''), 0, 19) ?: '—'); ?></td>
          <td class="text-end">
            <a href="/settings/users?edit=<?php echo (int) $user['id']; ?>"
               class="btn btn-outline-secondary btn-xs">Edit</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($editUser !== null): ?>
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,0.5)">
  <div class="modal-dialog">
    <div class="modal-content" style="background:var(--panel);border-color:var(--border-color)">
      <form method="post" action="/settings/users/update">
        <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
        <input type="hidden" name="id" value="<?php echo (int) $editUser['id']; ?>">
        <div class="modal-header border-secondary">
          <h5 class="modal-title fs-6">Edit User</h5>
          <a href="/settings/users" class="btn-close"></a>
        </div>
        <div class="modal-body">
          <p class="path-text mb-3"><?php echo View::e($editUser['email']); ?>
            · <?php echo View::e($editUser['auth_source'] ?? 'local'); ?></p>
          <div class="mb-3">
            <label class="form-label">Display Name</label>
            <input type="text" name="display_name" class="form-control" required
                   value="<?php echo View::e($editUser['display_name']); ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Role</label>
            <select name="role" class="form-select">
              <option value="editor" <?php echo ($editUser['role'] ?? '') === 'editor' ? 'selected' : ''; ?>>Editor</option>
              <option value="admin" <?php echo ($editUser['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Admin</option>
            </select>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="active" id="user-active"
                   <?php echo !empty($editUser['active']) ? 'checked' : ''; ?>
                   <?php echo (int) $editUser['id'] === (int) Auth::id() ? 'disabled' : ''; ?>>
            <label class="form-check-label" for="user-active">Active</label>
            <?php if ((int) $editUser['id'] === (int) Auth::id()): ?>
            <input type="hidden" name="active" value="1">
            <div class="path-text">You cannot deactivate your own account.</div>
            <?php endif; ?>
          </div>
          <?php if (($editUser['auth_source'] ?? 'local') === 'local'): ?>
          <div class="mb-0">
            <label class="form-label">New Password <span class="path-text">(leave blank to keep)</span></label>
            <input type="password" name="password" class="form-control" minlength="8">
          </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer border-secondary">
          <a href="/settings/users" class="btn btn-outline-secondary btn-sm">Cancel</a>
          <button type="submit" class="btn btn-primary btn-sm">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
