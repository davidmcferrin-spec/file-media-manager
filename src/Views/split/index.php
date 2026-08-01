<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var list<array<string, mixed>> $items */
/** @var list<array<string, mixed>> $splittable */
/** @var array<string, int> $statusCounts */
/** @var string $statusFilter */
/** @var int $total */
/** @var int $page */
/** @var int $totalPages */
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Split Queue</h1>
    <p class="mb-0" style="color:var(--text-soft);font-size:0.8rem;">
      Long files flagged for segmentation. Mark the show itself in the workbench — export will add ±5&nbsp;min handles later.
    </p>
  </div>
  <a href="/queue?needs_split=1" class="btn btn-outline-secondary btn-sm">View in Queue</a>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
  <?php
  $statuses = ['', 'PENDING', 'IN_PROGRESS', 'DONE', 'FAILED'];
  foreach ($statuses as $st):
      $active = $statusFilter === $st;
      $label  = $st === '' ? 'All' : $st;
      $cnt    = $st === '' ? array_sum($statusCounts) : ($statusCounts[$st] ?? 0);
  ?>
  <a href="/split<?php echo $st !== '' ? '?status=' . urlencode($st) : ''; ?>"
     class="btn btn-sm <?php echo $active ? 'btn-primary' : 'btn-outline-secondary'; ?>">
    <?php echo View::e($label); ?> <span class="opacity-75">(<?php echo $cnt; ?>)</span>
  </a>
  <?php endforeach; ?>
</div>

<div class="row g-4">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">Split Jobs (<?php echo number_format($total); ?>)</div>
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead>
            <tr>
              <th>File</th>
              <th>Duration</th>
              <th>Status</th>
              <th>Segments</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($items === []): ?>
            <tr>
              <td colspan="5" class="text-center py-4" style="color:var(--text-soft)">No split jobs.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($items as $item): ?>
            <?php
            $segs = json_decode((string) ($item['segments'] ?? '[]'), true);
            $segCount = is_array($segs) ? count($segs) : 0;
            ?>
            <tr>
              <td>
                <div class="path-filename"><?php echo View::e($item['original_filename']); ?></div>
                <div class="path-text"><?php echo View::e($item['original_path']); ?></div>
                <?php echo View::assetIdBlock($item); ?>
                <?php if (!empty($item['split_notes'])): ?>
                <div class="path-text mt-1"><?php echo View::e($item['split_notes']); ?></div>
                <?php endif; ?>
              </td>
              <td class="text-nowrap"><?php echo View::duration($item['duration_seconds'] ?? null); ?></td>
              <td><?php echo View::statusBadge((string) $item['status']); ?></td>
              <td><?php echo $segCount; ?></td>
              <td class="text-end">
                <a href="/split/<?php echo (int) $item['id']; ?><?php echo $statusFilter !== '' ? '?status=' . urlencode($statusFilter) : ''; ?>"
                   class="btn btn-outline-secondary btn-xs">Open</a>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php
    $paginationBasePath = '/split';
    $paginationQuery = [
        'status' => $statusFilter,
    ];
    require dirname(__DIR__) . '/partials/pagination.php';
    ?>
  </div>

  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">Add from Queue</div>
      <div class="card-body p-0">
        <?php if ($splittable === []): ?>
        <div class="p-4 text-center" style="color:var(--text-soft);font-size:0.85rem">
          No flagged files waiting for split queue.
        </div>
        <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($splittable as $file): ?>
          <li class="list-group-item" style="background:transparent;border-color:var(--border-color)">
            <div class="path-filename" style="font-size:0.82rem"><?php echo View::e($file['original_filename']); ?></div>
            <div class="path-text"><?php echo View::duration($file['duration_seconds'] ?? null); ?>
              · <?php echo View::e($file['show_abbr'] ?? '—'); ?></div>
            <form method="post" action="/split/create" class="mt-2">
              <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
              <input type="hidden" name="file_id" value="<?php echo (int) $file['id']; ?>">
              <button type="submit" class="btn btn-primary btn-xs">Add to Split Queue</button>
            </form>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
