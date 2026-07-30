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
        $path    = $projectRoot . '/example_file_trees/newsnation_schedule.xlsx';
        $originalName = null;

        $upload = $_FILES['schedule_file'] ?? $_FILES['csv_file'] ?? null;
        if (is_array($upload)
            && !empty($upload['tmp_name'])
            && is_uploaded_file($upload['tmp_name'])
        ) {
            $path = $upload['tmp_name'];
            $originalName = is_string($upload['name'] ?? null) ? $upload['name'] : null;
        }

        try {
            $result = (new ScheduleCsvImporter())->importFile($path, $replace, $originalName);
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

    if ($uri === '/schedule/create' || $uri === '/schedule/update') {
        $entryId = $uri === '/schedule/update' ? (int) ($_POST['id'] ?? 0) : 0;
        $data    = schedule_parse_entry($_POST);

        if ($data === null) {
            Session::flash('error', 'Invalid schedule entry — check show, title, times, and dates.');
            header('Location: ' . schedule_redirect_url($_POST));
            exit;
        }

        try {
            if ($uri === '/schedule/create') {
                $newId = $scheduleRepo->insert($data);
                schedule_audit($audit, 'SCHEDULE_ENTRY_CREATED', ['id' => $newId, 'show_id' => $data['show_id']]);
                Session::flash('success', 'Schedule entry added.');
            } else {
                if ($entryId <= 0 || $scheduleRepo->findById($entryId) === null) {
                    Session::flash('error', 'Schedule entry not found.');
                    header('Location: /schedule');
                    exit;
                }
                $scheduleRepo->update($entryId, $data);
                schedule_audit($audit, 'SCHEDULE_ENTRY_UPDATED', ['id' => $entryId, 'show_id' => $data['show_id']]);
                Session::flash('success', 'Schedule entry updated.');
            }
        } catch (\Throwable $e) {
            Session::flash('error', 'Could not save schedule entry: ' . $e->getMessage());
        }

        header('Location: ' . schedule_redirect_url($_POST));
        exit;
    }

    if ($uri === '/schedule/delete') {
        $entryId = (int) ($_POST['id'] ?? 0);
        $entry   = $entryId > 0 ? $scheduleRepo->findById($entryId) : null;

        if ($entry === null) {
            Session::flash('error', 'Schedule entry not found.');
            header('Location: /schedule');
            exit;
        }

        $scheduleRepo->delete($entryId);
        schedule_audit($audit, 'SCHEDULE_ENTRY_DELETED', ['id' => $entryId, 'show_id' => (int) $entry['show_id']]);
        Session::flash('success', 'Schedule entry deleted.');
        header('Location: ' . schedule_redirect_url($_POST));
        exit;
    }

    http_response_code(404);
    exit;
}

/** @param array<string, mixed> $post */
function schedule_redirect_url(array $post): string
{
    $params = [];
    if (!empty($post['return_show_id'])) {
        $params['show_id'] = (int) $post['return_show_id'];
    }
    if (!empty($post['return_q'])) {
        $params['q'] = trim((string) $post['return_q']);
    }

    return '/schedule' . ($params !== [] ? '?' . http_build_query($params) : '');
}

/** @param array<string, mixed> $post @return array<string, mixed>|null */
function schedule_parse_entry(array $post): ?array
{
    $showId = (int) ($post['show_id'] ?? 0);
    $title  = trim((string) ($post['title'] ?? ''));
    if ($showId <= 0 || $title === '') {
        return null;
    }

    $hourStart = schedule_normalize_time((string) ($post['hour_start_et'] ?? ''));
    $hourEnd   = schedule_normalize_time((string) ($post['hour_end_et'] ?? ''));
    if ($hourStart === null || $hourEnd === null) {
        return null;
    }

    $daysRaw = $post['days'] ?? [];
    if (!is_array($daysRaw)) {
        $daysRaw = [];
    }
    $dayBits = [1, 2, 4, 8, 16, 32, 64];
    $mask    = 0;
    foreach ($daysRaw as $bit) {
        $bit = (int) $bit;
        if (in_array($bit, $dayBits, true)) {
            $mask |= $bit;
        }
    }
    if ($mask === 0) {
        return null;
    }

    $from = trim((string) ($post['effective_from'] ?? ''));
    $to   = trim((string) ($post['effective_to'] ?? ''));
    if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        return null;
    }
    if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        return null;
    }

    return [
        'show_id'         => $showId,
        'title'           => $title,
        'hour_start_et'   => $hourStart,
        'hour_end_et'     => $hourEnd,
        'days_of_week'    => $mask,
        'effective_from'  => $from,
        'effective_to'    => $to !== '' ? $to : null,
        'era_name'        => trim((string) ($post['era_name'] ?? '')),
        'anchor_names'    => trim((string) ($post['anchor_names'] ?? '')),
        'show_type'       => trim((string) ($post['show_type'] ?? '')),
        'network_brand'   => trim((string) ($post['network_brand'] ?? '')),
        'notes'           => trim((string) ($post['notes'] ?? '')),
        'active'          => isset($post['active']),
    ];
}

function schedule_normalize_time(string $input): ?string
{
    $input = trim($input);
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $input, $m) === 1) {
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
            return sprintf('%02d:%02d:00', $h, $min);
        }
    }

    return null;
}

$search   = trim($_GET['q'] ?? '');
$showId   = isset($_GET['show_id']) ? (int) $_GET['show_id'] : 0;
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 100;
$offset   = ($page - 1) * $perPage;
$filterShowId = $showId > 0 ? $showId : null;
$entries  = $scheduleRepo->list($perPage, $offset, $search !== '' ? $search : null, $filterShowId);
$total    = $scheduleRepo->countFiltered($search !== '' ? $search : null, $filterShowId);
$shows    = $showRepo->all();
$filterShow = $filterShowId !== null ? $showRepo->findById($filterShowId) : null;
$editId   = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editEntry = $editId > 0 ? $scheduleRepo->findById($editId) : null;
/** @var array{skipped?: list<string>, warnings?: list<string>}|null $importLog */
$importLog = Session::getFlash('schedule_import_log');
$showsTab  = 'schedule';

$title = 'Program Schedule — Media Manager';
require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/schedule/index.php';
require dirname(__DIR__) . '/Views/layouts/footer.php';
