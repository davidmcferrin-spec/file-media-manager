<?php

declare(strict_types=1);

use MediaManager\Support\View;

/** @var list<array{label: string, count: int}> $extensions */
/** @var list<array{label: string, count: int}> $resolutions */
/** @var list<array{label: string, count: int}> $codecs */
/** @var array{total_seconds: float, files_with_duration: int, total_files: int} $duration */
/** @var array{undated_files: int, undated_seconds: float} $excluded */
/** @var string $timelineView 'year'|'month' */
/** @var list<array{label: string, total_seconds: float, file_count: int, href: string}> $timelineYears */
/** @var list<array{label: string, ym: string, total_seconds: float, file_count: int}> $timelineMonths */
/** @var string|null $timelineFrom */
/** @var string|null $timelinePrevUrl */
/** @var string|null $timelineNextUrl */
/** @var string $timelineTitle */

$extTotal  = array_sum(array_column($extensions, 'count'));
$resTotal  = array_sum(array_column($resolutions, 'count'));
$codecTotal = array_sum(array_column($codecs, 'count'));
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-2 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Library Analytics</h1>
    <p class="mb-0" style="color:var(--text-soft);font-size:0.8rem;">
      Inventory metrics for archive planning — how much content you have, in what formats, and when it aired.
      Use alongside Gaps to judge completeness; hours here are media duration, not file counts.
    </p>
  </div>
</div>

<?php require __DIR__ . '/_nav.php'; ?>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label">Total Content Hours</div>
        <div class="stat-value"><?php echo View::e(View::formatHours($duration['total_seconds'])); ?></div>
        <div class="stat-sub">
          Sum of FFprobe duration ÷ 3600 —
          <?php echo number_format($duration['files_with_duration']); ?>
          of <?php echo number_format($duration['total_files']); ?> files have duration metadata
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label">Files in Library</div>
        <div class="stat-value"><?php echo number_format($duration['total_files']); ?></div>
        <div class="stat-sub">All statuses included</div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card stat-card h-100">
      <div class="card-body py-3 px-3">
        <div class="stat-label">Avg Duration</div>
        <?php
        $avgSeconds = $duration['files_with_duration'] > 0
            ? $duration['total_seconds'] / $duration['files_with_duration']
            : 0;
        ?>
        <div class="stat-value" style="font-size:1.6rem"><?php echo View::e(View::duration($avgSeconds)); ?></div>
        <div class="stat-sub">Per file with FFprobe duration</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>File Extensions</span>
        <span class="path-text" style="font-size:0.72rem"><?php echo number_format($extTotal); ?> files</span>
      </div>
      <div class="card-body">
        <?php echo View::pieChartHtml($extensions); ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Resolution</span>
        <span class="path-text" style="font-size:0.72rem"><?php echo number_format($resTotal); ?> files</span>
      </div>
      <div class="card-body">
        <?php echo View::pieChartHtml($resolutions); ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Video Codecs</span>
        <span class="path-text" style="font-size:0.72rem"><?php echo number_format($codecTotal); ?> files</span>
      </div>
      <div class="card-body">
        <?php echo View::pieChartHtml($codecs); ?>
      </div>
    </div>
  </div>
</div>

<p class="path-text mt-3 mb-4" style="font-size:0.72rem;color:var(--text-soft)">
  Extension / resolution / codec charts show the mix of material on disk (top categories; rest grouped as “Other”).
  Useful for storage planning and codec migration. Duration totals require scan-time FFprobe metadata.
</p>

<div class="card mb-0">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <span><?php echo View::e($timelineTitle); ?></span>
      <?php if ($timelineView === 'month'): ?>
        <span class="path-text d-block" style="font-size:0.72rem">
          Bar height = hours of media (sum of FFprobe duration by <code>file_date</code>) · 13-month window
        </span>
      <?php else: ?>
        <span class="path-text d-block" style="font-size:0.72rem">
          Bar height = hours of media (sum of FFprobe duration by year of <code>file_date</code>) · click a year for months
        </span>
      <?php endif; ?>
    </div>
    <div class="d-flex align-items-center gap-2">
      <?php if ($timelineView === 'month' && $timelinePrevUrl !== null && $timelineNextUrl !== null): ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?php echo View::e($timelinePrevUrl); ?>"
           title="Shift window back one month">&larr; Prev</a>
        <a class="btn btn-sm btn-outline-secondary" href="/dashboard/library">All years</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?php echo View::e($timelineNextUrl); ?>"
           title="Shift window forward one month">Next &rarr;</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body py-3">
    <?php
    $timelineBars = $timelineView === 'year' ? $timelineYears : $timelineMonths;
    echo View::hoursBarChartHtml($timelineBars);
    ?>
    <?php if ($excluded['undated_files'] > 0): ?>
      <p class="path-text mb-0 mt-2" style="font-size:0.72rem;color:var(--text-soft)">
        <?php echo number_format($excluded['undated_files']); ?> files
        (<?php echo View::e(View::formatHours($excluded['undated_seconds'])); ?> hours)
        excluded — missing or invalid <code>file_date</code>.
      </p>
    <?php endif; ?>
  </div>
</div>
