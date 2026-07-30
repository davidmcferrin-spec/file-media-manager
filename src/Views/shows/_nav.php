<?php

declare(strict_types=1);

use MediaManager\Auth\Auth;

/** @var string $showsTab 'dictionary'|'schedule'|'legacy-map'|'show-audit' */
$showsTab = $showsTab ?? 'dictionary';
?>
<ul class="nav nav-pills mb-4 gap-1">
  <li class="nav-item">
    <a class="nav-link<?php echo $showsTab === 'show-audit' ? ' active' : ''; ?>"
       href="/show-audit">Gaps</a>
  </li>
  <?php if (Auth::isAdmin()): ?>
  <li class="nav-item">
    <a class="nav-link<?php echo $showsTab === 'dictionary' ? ' active' : ''; ?>"
       href="/dictionary">Shows</a>
  </li>
  <li class="nav-item">
    <a class="nav-link<?php echo $showsTab === 'schedule' ? ' active' : ''; ?>"
       href="/schedule">Timeline</a>
  </li>
  <li class="nav-item">
    <a class="nav-link<?php echo $showsTab === 'legacy-map' ? ' active' : ''; ?>"
       href="/legacy-map">Legacy Map</a>
  </li>
  <?php endif; ?>
</ul>
