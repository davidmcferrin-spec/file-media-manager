<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var array<string, mixed> $ldapSettings */
/** @var list<array<string, mixed>> $groupRoles */
?>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">LDAP Connection</div>
      <div class="card-body">
        <p class="mb-3" style="color:var(--text-soft);font-size:0.82rem;">
          When enabled, LDAP users authenticate against Active Directory.
          Prefer pre-creating them under <a href="/settings/users">Users</a> with Auth = LDAP and an app role.
          Local accounts remain available as password fallback.
        </p>

        <form method="post" action="/settings/ldap">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">

          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="enabled" id="ldap-enabled"
                   <?php echo !empty($ldapSettings['enabled']) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="ldap-enabled">Enable LDAP authentication</label>
          </div>

          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Host</label>
              <input type="text" name="host" class="form-control"
                     value="<?php echo View::e($ldapSettings['host'] ?? ''); ?>"
                     placeholder="ldap.corp.example.com">
            </div>
            <div class="col-md-4">
              <label class="form-label">Port</label>
              <input type="number" name="port" class="form-control"
                     value="<?php echo (int) ($ldapSettings['port'] ?? 389); ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Bind DN Pattern</label>
              <input type="text" name="bind_dn_pattern" class="form-control"
                     value="<?php echo View::e($ldapSettings['bind_dn_pattern'] ?? ''); ?>"
                     placeholder="CORP\{username}">
              <div class="form-text" style="color:var(--text-soft)">
                Use <code>{username}</code> for the login name entered on the sign-in form.
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">Search Base DN</label>
              <input type="text" name="search_base_dn" class="form-control"
                     value="<?php echo View::e($ldapSettings['search_base_dn'] ?? ''); ?>"
                     placeholder="DC=corp,DC=example,DC=com">
            </div>
            <div class="col-12">
              <label class="form-label">User Search Filter</label>
              <input type="text" name="user_search_filter" class="form-control"
                     value="<?php echo View::e($ldapSettings['user_search_filter'] ?? '(sAMAccountName={username})'); ?>">
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-sm mt-3">Save LDAP Settings</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">Group → Role Mappings</div>
      <div class="card-body">
        <p class="mb-3" style="color:var(--text-soft);font-size:0.82rem;">
          Used only when someone signs in via LDAP and is <strong>not</strong> already in Users.
          Group CN or partial match sets their first-login role; unmapped users get <strong>editor</strong>.
          Pre-created LDAP users keep the role you assigned in Users.
        </p>

        <?php if ($groupRoles !== []): ?>
        <table class="table table-sm mb-3">
          <thead>
            <tr>
              <th>LDAP Group</th>
              <th>Role</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($groupRoles as $mapping): ?>
            <tr>
              <td><code><?php echo View::e($mapping['ldap_group']); ?></code></td>
              <td><?php echo View::e($mapping['role']); ?></td>
              <td class="text-end">
                <form method="post" action="/settings/ldap/groups/delete" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int) $mapping['id']; ?>">
                  <button type="submit" class="btn btn-outline-danger btn-xs">Remove</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>

        <form method="post" action="/settings/ldap/groups/add">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <div class="row g-2">
            <div class="col-7">
              <input type="text" name="ldap_group" class="form-control form-control-sm"
                     placeholder="MediaManager-Admins" required>
            </div>
            <div class="col-3">
              <select name="role" class="form-select form-select-sm">
                <option value="admin">admin</option>
                <option value="editor" selected>editor</option>
              </select>
            </div>
            <div class="col-2">
              <button type="submit" class="btn btn-primary btn-sm w-100">Add</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
