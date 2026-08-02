<?php

declare(strict_types=1);

use MediaManager\Auth\Auth;

/** @var string $showsTab 'shows'|'eras'|'schedule'|'legacy-map'|'show-audit'|'dictionary' */
$showsTab = $showsTab ?? 'shows';
if ($showsTab === 'dictionary') {
    $showsTab = 'shows';
}
?>
<ul class="nav page-tabs mb-4 flex-wrap" role="tablist">
  <li class="nav-item">
    <a class="page-tab<?php echo $showsTab === 'show-audit' ? ' active' : ''; ?>"
       href="/show-audit"
       <?php echo $showsTab === 'show-audit' ? 'aria-current="page"' : ''; ?>>Gaps</a>
  </li>
  <?php if (Auth::isAdmin()): ?>
  <li class="nav-item">
    <a class="page-tab<?php echo $showsTab === 'eras' ? ' active' : ''; ?>"
       href="/eras"
       <?php echo $showsTab === 'eras' ? 'aria-current="page"' : ''; ?>>Eras</a>
  </li>
  <li class="nav-item">
    <a class="page-tab<?php echo $showsTab === 'shows' ? ' active' : ''; ?>"
       href="/shows"
       <?php echo $showsTab === 'shows' ? 'aria-current="page"' : ''; ?>>Shows</a>
  </li>
  <li class="nav-item">
    <a class="page-tab<?php echo $showsTab === 'schedule' ? ' active' : ''; ?>"
       href="/schedule"
       <?php echo $showsTab === 'schedule' ? 'aria-current="page"' : ''; ?>>Timeline</a>
  </li>
  <li class="nav-item">
    <a class="page-tab<?php echo $showsTab === 'legacy-map' ? ' active' : ''; ?>"
       href="/legacy-map"
       <?php echo $showsTab === 'legacy-map' ? 'aria-current="page"' : ''; ?>>Legacy Map</a>
  </li>
  <?php endif; ?>
</ul>
