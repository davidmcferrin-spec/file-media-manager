<?php

declare(strict_types=1);

use MediaManager\Support\View;

/** @var int $page */
/** @var int $totalPages */
/** @var int $total */
/** @var int $perPage */
/** @var string $paginationBasePath */
/** @var array<string, mixed> $paginationQuery */

if ($totalPages <= 1) {
    return;
}

$range      = View::paginationRange($page, $totalPages);
$startItem  = min($total, ($page - 1) * $perPage + 1);
$endItem    = min($total, $page * $perPage);
$prevPage   = max(1, $page - 1);
$nextPage   = min($totalPages, $page + 1);
?>

<nav class="mt-3" aria-label="Pagination">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div class="path-text" style="font-size:0.78rem">
      Page <?php echo number_format($page); ?> of <?php echo number_format($totalPages); ?>
      · <?php echo number_format($startItem); ?>–<?php echo number_format($endItem); ?>
      of <?php echo number_format($total); ?>
    </div>

    <ul class="pagination pagination-sm mb-0">
      <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
        <a class="page-link" href="<?php echo View::e(View::paginationUrl($paginationBasePath, $paginationQuery, 1)); ?>"
           aria-label="First page">&laquo;</a>
      </li>
      <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
        <a class="page-link" href="<?php echo View::e(View::paginationUrl($paginationBasePath, $paginationQuery, $prevPage)); ?>"
           aria-label="Previous page">&lsaquo;</a>
      </li>

      <?php foreach ($range as $p): ?>
      <?php if ($p === '…'): ?>
      <li class="page-item disabled"><span class="page-link">…</span></li>
      <?php else: ?>
      <li class="page-item <?php echo (int) $p === $page ? 'active' : ''; ?>">
        <a class="page-link" href="<?php echo View::e(View::paginationUrl($paginationBasePath, $paginationQuery, (int) $p)); ?>">
          <?php echo number_format((int) $p); ?>
        </a>
      </li>
      <?php endif; ?>
      <?php endforeach; ?>

      <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
        <a class="page-link" href="<?php echo View::e(View::paginationUrl($paginationBasePath, $paginationQuery, $nextPage)); ?>"
           aria-label="Next page">&rsaquo;</a>
      </li>
      <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
        <a class="page-link" href="<?php echo View::e(View::paginationUrl($paginationBasePath, $paginationQuery, $totalPages)); ?>"
           aria-label="Last page">&raquo;</a>
      </li>
    </ul>

    <form method="get" action="<?php echo View::e($paginationBasePath); ?>"
          class="d-flex align-items-center gap-2" style="font-size:0.78rem">
      <?php foreach ($paginationQuery as $key => $value): ?>
      <?php if ($key === 'page' || $value === null || $value === '' || $value === false) continue; ?>
      <input type="hidden" name="<?php echo View::e((string) $key); ?>"
             value="<?php echo View::e((string) $value); ?>">
      <?php endforeach; ?>
      <label class="path-text mb-0" for="pagination-jump">Go to</label>
      <input type="number" name="page" id="pagination-jump" class="form-control form-control-sm"
             style="width:5rem" min="1" max="<?php echo (int) $totalPages; ?>"
             value="<?php echo (int) $page; ?>" aria-label="Page number">
      <button type="submit" class="btn btn-outline-secondary btn-sm">Go</button>
    </form>
  </div>
</nav>
