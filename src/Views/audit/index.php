<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var array<string, mixed> $filters */
/** @var list<array<string, mixed>> $entries */
/** @var list<string> $actions */
/** @var int $total */
/** @var int $page */
/** @var int $totalPages */
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Audit Log</h1>
    <p class="mb-0" style="color:var(--text-soft);font-size:0.8rem;">
      <?php echo number_format($total); ?> entries — scans, approvals, executions, rollbacks, and settings changes.
    </p>
  </div>
</div>

<form method="get" action="/audit" class="card mb-4">
  <div class="card-body py-3">
    <div class="row g-2 align-items-end">
      <div class="col-md-2">
        <label class="form-label">Action</label>
        <select name="action" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach ($actions as $act): ?>
          <option value="<?php echo View::e($act); ?>"
            <?php echo ($filters['action'] ?? '') === $act ? 'selected' : ''; ?>>
            <?php echo View::e($act); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Entity Type</label>
        <select name="entity_type" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach (['file', 'show', 'source', 'conversion_rule', 'split_queue', 'user'] as $et): ?>
          <option value="<?php echo $et; ?>"
            <?php echo ($filters['entity_type'] ?? '') === $et ? 'selected' : ''; ?>>
            <?php echo View::e($et); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">User Email</label>
        <input type="text" name="user_email" class="form-control form-control-sm"
               value="<?php echo View::e($filters['user_email'] ?? ''); ?>" placeholder="admin@…">
      </div>
      <div class="col-md-3">
        <label class="form-label">Search Paths</label>
        <input type="text" name="q" class="form-control form-control-sm"
               value="<?php echo View::e($filters['search'] ?? ''); ?>" placeholder="Path or action">
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
      </div>
    </div>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle" style="font-size:0.82rem">
      <thead>
        <tr>
          <th style="width:160px">When</th>
          <th style="width:140px">Action</th>
          <th style="width:180px">User</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($entries === []): ?>
        <tr>
          <td colspan="4" class="text-center py-5" style="color:var(--text-soft)">No audit entries found.</td>
        </tr>
        <?php else: ?>
        <?php foreach ($entries as $entry): ?>
        <?php
        $details = json_decode((string) ($entry['details'] ?? '{}'), true);
        if (!is_array($details)) {
            $details = [];
        }
        ?>
        <tr>
          <td class="text-nowrap" style="color:var(--text-soft)">
            <?php echo View::e(substr((string) $entry['created_at'], 0, 19)); ?>
          </td>
          <td>
            <span class="badge bg-secondary" style="font-size:0.68rem;font-weight:500">
              <?php echo View::e($entry['action']); ?>
            </span>
            <?php if (!empty($entry['entity_type'])): ?>
            <div class="path-text mt-1"><?php echo View::e($entry['entity_type']); ?>
              <?php if (!empty($entry['entity_id'])): ?>#<?php echo (int) $entry['entity_id']; ?><?php endif; ?>
            </div>
            <?php endif; ?>
          </td>
          <td>
            <div><?php echo View::e($entry['user_email'] ?: '—'); ?></div>
            <div class="path-text"><?php echo View::e($entry['ip_address']); ?></div>
          </td>
          <td>
            <?php if (!empty($entry['original_path'])): ?>
            <div class="path-text"><span style="color:var(--text-soft)">from</span>
              <?php echo View::e($entry['original_path']); ?></div>
            <?php endif; ?>
            <?php if (!empty($entry['new_path'])): ?>
            <div class="path-text proposed"><span style="color:var(--text-soft)">to</span>
              <?php echo View::e($entry['new_path']); ?></div>
            <?php endif; ?>
            <?php if ($details !== []): ?>
            <details class="mt-1">
              <summary class="path-text" style="cursor:pointer">JSON details</summary>
              <pre class="mb-0 mt-1 p-2 rounded" style="font-size:0.72rem;background:var(--form-bg);max-height:120px;overflow:auto"><?php
                echo View::e(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
              ?></pre>
            </details>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3">
  <ul class="pagination pagination-sm mb-0">
    <?php for ($p = 1; $p <= min($totalPages, 20); $p++): ?>
    <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
      <a class="page-link" href="/audit?<?php echo http_build_query(array_merge(
          array_filter([
              'action'      => $filters['action'] ?? '',
              'entity_type' => $filters['entity_type'] ?? '',
              'user_email'  => $filters['user_email'] ?? '',
              'q'           => $filters['search'] ?? '',
          ]),
          ['page' => $p]
      )); ?>"><?php echo $p; ?></a>
    </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>
