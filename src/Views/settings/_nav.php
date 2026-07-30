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

<ul class="nav nav-pills mb-4 gap-1 flex-wrap">
  <li class="nav-item">
    <a class="nav-link<?php echo settings_tab('sources', $settingsTab); ?>" href="/settings/sources">
      NAS Sources
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link<?php echo settings_tab('processing', $settingsTab); ?>" href="/settings/processing">
      Processing
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link<?php echo settings_tab('conversions', $settingsTab); ?>" href="/settings/conversions">
      Conversions
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link<?php echo settings_tab('media-types', $settingsTab); ?>" href="/settings/media-types">
      Media Types
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link<?php echo settings_tab('ignore-paths', $settingsTab); ?>" href="/settings/ignore-paths">
      Ignore Paths
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link<?php echo settings_tab('ldap', $settingsTab); ?>" href="/settings/ldap">
      LDAP
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link<?php echo settings_tab('users', $settingsTab); ?>" href="/settings/users">
      Users
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link<?php echo settings_tab('danger', $settingsTab); ?>" href="/settings/danger">
      Danger Zone
    </a>
  </li>
</ul>

<input type="hidden" id="csrf-token" value="<?php echo View::e(Session::csrfToken()); ?>">
