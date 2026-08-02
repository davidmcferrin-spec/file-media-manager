<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var string $settingsTab */
/** @var string $currentPath */

function settings_tab(string $tab, string $currentTab): string
{
    return $tab === $currentTab ? ' active' : '';
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Settings</h1>
    <p class="mb-0" style="color:var(--text-soft);font-size:0.8rem;">
      NAS sources, processing thresholds, conversion rules, media types, LDAP, users — and Danger Zone.
    </p>
  </div>
</div>

<ul class="nav page-tabs mb-4 flex-wrap" role="tablist">
  <li class="nav-item">
    <a class="page-tab<?php echo settings_tab('sources', $settingsTab); ?>" href="/settings/sources"
       <?php echo $settingsTab === 'sources' ? 'aria-current="page"' : ''; ?>>
      NAS Sources
    </a>
  </li>
  <li class="nav-item">
    <a class="page-tab<?php echo settings_tab('processing', $settingsTab); ?>" href="/settings/processing"
       <?php echo $settingsTab === 'processing' ? 'aria-current="page"' : ''; ?>>
      Processing
    </a>
  </li>
  <li class="nav-item">
    <a class="page-tab<?php echo settings_tab('conversions', $settingsTab); ?>" href="/settings/conversions"
       <?php echo $settingsTab === 'conversions' ? 'aria-current="page"' : ''; ?>>
      Conversions
    </a>
  </li>
  <li class="nav-item">
    <a class="page-tab<?php echo settings_tab('media-types', $settingsTab); ?>" href="/settings/media-types"
       <?php echo $settingsTab === 'media-types' ? 'aria-current="page"' : ''; ?>>
      Media Types
    </a>
  </li>
  <li class="nav-item">
    <a class="page-tab<?php echo settings_tab('ignore-paths', $settingsTab); ?>" href="/settings/ignore-paths"
       <?php echo $settingsTab === 'ignore-paths' ? 'aria-current="page"' : ''; ?>>
      Ignore Paths
    </a>
  </li>
  <li class="nav-item">
    <a class="page-tab<?php echo settings_tab('ldap', $settingsTab); ?>" href="/settings/ldap"
       <?php echo $settingsTab === 'ldap' ? 'aria-current="page"' : ''; ?>>
      LDAP
    </a>
  </li>
  <li class="nav-item">
    <a class="page-tab<?php echo settings_tab('users', $settingsTab); ?>" href="/settings/users"
       <?php echo $settingsTab === 'users' ? 'aria-current="page"' : ''; ?>>
      Users
    </a>
  </li>
  <li class="nav-item">
    <a class="page-tab<?php echo settings_tab('danger', $settingsTab); ?>" href="/settings/danger"
       <?php echo $settingsTab === 'danger' ? 'aria-current="page"' : ''; ?>>
      Danger Zone
    </a>
  </li>
</ul>

<input type="hidden" id="csrf-token" value="<?php echo View::e(Session::csrfToken()); ?>">
