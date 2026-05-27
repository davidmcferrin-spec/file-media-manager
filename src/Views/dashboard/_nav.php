<?php

declare(strict_types=1);

/** @var string $dashboardTab 'operations'|'library' */
$dashboardTab = $dashboardTab ?? 'operations';
?>
<ul class="nav nav-pills mb-4 gap-1">
  <li class="nav-item">
    <a class="nav-link<?php echo $dashboardTab === 'operations' ? ' active' : ''; ?>"
       href="/dashboard">Operations</a>
  </li>
  <li class="nav-item">
    <a class="nav-link<?php echo $dashboardTab === 'library' ? ' active' : ''; ?>"
       href="/dashboard/library">Library Analytics</a>
  </li>
</ul>
