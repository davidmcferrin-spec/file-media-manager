<?php

declare(strict_types=1);

use MediaManager\Auth\Auth;

/** @var string $showsTab 'dictionary'|'schedule'|'legacy-map'|'show-audit' */
$showsTab = $showsTab ?? 'dictionary';
?>
<ul class="nav nav-pills mb-4 gap-1">
  <li class="nav-item">
    <a class="nav-link<?php echo $showsTab === 'show-audit' ? ' active' : ''; ?>"
       href="/show-audit">Show Audit</a>
  </li>
  <?php if (Auth::isAdmin()): ?>
  <li class="nav-item">
    <a class="nav-link<?php echo $showsTab === 'dictionary' ? ' active' : ''; ?>"
       href="/dictionary">Dictionary</a>
  </li>
  <li class="nav-item">
    <a class="nav-link<?php echo $showsTab === 'schedule' ? ' active' : ''; ?>"
       href="/schedule">Program Schedule</a>
  </li>
  <li class="nav-item">
    <a class="nav-link<?php echo $showsTab === 'legacy-map' ? ' active' : ''; ?>"
       href="/legacy-map">Legacy Map</a>
  </li>
  <?php endif; ?>
</ul>
