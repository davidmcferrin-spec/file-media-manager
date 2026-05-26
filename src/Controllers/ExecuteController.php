<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Repositories\FileRepository;
use MediaManager\Services\ExecutorService;
use MediaManager\Services\RollbackService;

Auth::requireAdmin();

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$files = new FileRepository();

if ($method === 'POST' && $uri === '/execute') {
    $csrf = $_POST['_csrf'] ?? '';
    if (!Session::validateCsrf($csrf)) {
        Session::flash('error', 'Invalid request.');
        header('Location: /execute');
        exit;
    }

    $ids = [];
    if (isset($_POST['ids']) && is_array($_POST['ids'])) {
        foreach ($_POST['ids'] as $rawId) {
            $id = (int) $rawId;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }

    $user = Auth::user();
    $result = (new ExecutorService())->executeApproved(
        $ids !== [] ? $ids : null,
        (int) Auth::id(),
        $user['email'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? ''
    );

    if ($result['failed'] === 0) {
        Session::flash('success', $result['succeeded'] . ' file(s) executed on disk.');
    } else {
        Session::flash('error', sprintf(
            '%d succeeded, %d failed. %s',
            $result['succeeded'],
            $result['failed'],
            implode(' | ', array_slice($result['errors'], 0, 3))
        ));
    }
    header('Location: /execute');
    exit;
}

if ($method === 'POST' && $uri === '/rollback') {
    $csrf = $_POST['_csrf'] ?? '';
    if (!Session::validateCsrf($csrf)) {
        Session::flash('error', 'Invalid request.');
        header('Location: /execute');
        exit;
    }

    $ids = [];
    if (isset($_POST['ids']) && is_array($_POST['ids'])) {
        foreach ($_POST['ids'] as $rawId) {
            $id = (int) $rawId;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }

    if ($ids === []) {
        Session::flash('error', 'Select file(s) to rollback.');
        header('Location: /execute');
        exit;
    }

    $user = Auth::user();
    $result = (new RollbackService())->rollback(
        $ids,
        (int) Auth::id(),
        $user['email'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? ''
    );

    if ($result['failed'] === 0) {
        Session::flash('success', $result['succeeded'] . ' file(s) rolled back.');
    } else {
        Session::flash('error', sprintf(
            '%d succeeded, %d failed. %s',
            $result['succeeded'],
            $result['failed'],
            implode(' | ', array_slice($result['errors'], 0, 3))
        ));
    }
    header('Location: /execute');
    exit;
}

$approvedFiles  = $files->allApproved(200);
$executedFiles  = $files->recentlyExecuted(30);
$approvedCount  = count($approvedFiles);

$title = 'Execute — Media Manager';
require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/execute/index.php';
require dirname(__DIR__) . '/Views/layouts/footer.php';
