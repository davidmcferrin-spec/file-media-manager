<?php

declare(strict_types=1);

/** @var string $dashboardTab 'operations'|'library' */
$dashboardTab = $dashboardTab ?? 'operations';
?>
<ul class="nav dashboard-tabs mb-4 gap-2" role="tablist">
  <li class="nav-item">
    <a class="dashboard-tab<?php echo $dashboardTab === 'operations' ? ' active' : ''; ?>"
       href="/dashboard"
       <?php echo $dashboardTab === 'operations' ? 'aria-current="page"' : ''; ?>>Pipeline</a>
  </li>
  <li class="nav-item">
    <a class="dashboard-tab<?php echo $dashboardTab === 'library' ? ' active' : ''; ?>"
       href="/dashboard/library"
       <?php echo $dashboardTab === 'library' ? 'aria-current="page"' : ''; ?>>Library Analytics</a>
  </li>
</ul>
