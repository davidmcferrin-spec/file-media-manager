<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\ProgramScheduleRepository;
use MediaManager\Repositories\ShowRepository;
use MediaManager\Services\ScheduleCsvImporter;

Auth::requireAdmin();

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$scheduleRepo = new ProgramScheduleRepository();
$showRepo     = new ShowRepository();
$audit        = new AuditRepository();

/** @var string $projectRoot */
$projectRoot = dirname(__DIR__, 2);

function schedule_audit(AuditRepository $audit, string $action, array $details = []): void
{
    $user = Auth::user();
    $audit->record(
        Auth::id(),
        $user['email'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
        $action,
        'program_schedule',
        null,
        null,
        null,
        $details
    );
}

if ($method === 'POST') {
    $csrf = $_POST['_csrf'] ?? '';
    if (!Session::validateCsrf($csrf)) {
        Session::flash('error', 'Invalid request.');
        header('Location: /schedule');
        exit;
    }

    if ($uri === '/schedule/import') {
        $replace = isset($_POST['replace_existing']);
        $path    = $projectRoot . '/example_file_trees/newsnation_schedule.csv';

        if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            $path = $_FILES['csv_file']['tmp_name'];
        }

        try {
            $result = (new ScheduleCsvImporter())->importFile($path, $replace);
            schedule_audit($audit, 'SCHEDULE_IMPORTED', $result);
            $msg = sprintf(
                'Imported %d hourly schedule block(s), created %d show(s).',
                $result['imported'],
                $result['shows_created']
            );
            if ($result['skipped'] !== []) {
                $msg .= ' ' . count($result['skipped']) . ' row(s) skipped — see import log.';
            }
            Session::flash('success', $msg);
            Session::flash('schedule_import_log', [
                'skipped'  => $result['skipped'],
                'warnings' => $result['warnings'],
            ]);
        } catch (\Throwable $e) {
            Session::flash('error', 'Import failed: ' . $e->getMessage());
        }

        header('Location: /schedule');
        exit;
    }

    if ($uri === '/schedule/merge') {
        $canonicalId = (int) ($_POST['canonical_id'] ?? 0);
        $absorbedRaw = $_POST['absorbed_ids'] ?? [];
        if (!is_array($absorbedRaw)) {
            $absorbedRaw = [$absorbedRaw];
        }
        $absorbedIds = array_values(array_filter(array_map('intval', $absorbedRaw)));

        if ($canonicalId <= 0 || $absorbedIds === []) {
            Session::flash('error', 'Select a canonical show and at least one show to merge.');
            header('Location: /schedule');
            exit;
        }

        try {
            $counts = $showRepo->mergeInto($canonicalId, $absorbedIds);
            schedule_audit($audit, 'SHOWS_MERGED', [
                'canonical_id' => $canonicalId,
                'absorbed_ids' => $absorbedIds,
                'counts'       => $counts,
            ]);
            Session::flash(
                'success',
                sprintf(
                    'Merged %d show(s): %d schedule row(s), %d file(s), %d rule(s) updated.',
                    $counts['deleted'],
                    $counts['schedule'],
                    $counts['files'],
                    $counts['rules']
                )
            );
        } catch (\Throwable $e) {
            Session::flash('error', 'Merge failed: ' . $e->getMessage());
        }

        header('Location: /schedule');
        exit;
    }

    http_response_code(404);
    exit;
}

$search   = trim($_GET['q'] ?? '');
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 100;
$offset   = ($page - 1) * $perPage;
$entries  = $scheduleRepo->list($perPage, $offset, $search !== '' ? $search : null);
$total    = $scheduleRepo->count();
$shows    = $showRepo->all();
/** @var array{skipped?: list<string>, warnings?: list<string>}|null $importLog */
$importLog = Session::getFlash('schedule_import_log');

$title = 'Program Schedule — Media Manager';
require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/schedule/index.php';
require dirname(__DIR__) . '/Views/layouts/footer.php';
