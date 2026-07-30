<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Support\AppVersion;

$version  = AppVersion::current();
$entries  = AppVersion::changelogEntries(50);
$title    = 'Versions — Media Manager';

require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/versions/index.php';
require dirname(__DIR__) . '/Views/layouts/footer.php';
