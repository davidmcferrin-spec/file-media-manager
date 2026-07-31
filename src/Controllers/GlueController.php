<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Repositories\FileRepository;

Auth::requireLogin();

$files  = new FileRepository();
$groups = $files->listGlueGroups(500);
$total  = $files->countNeedsGlue();

$title = 'Glue — Media Manager';
require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/glue/index.php';
require dirname(__DIR__) . '/Views/layouts/footer.php';
