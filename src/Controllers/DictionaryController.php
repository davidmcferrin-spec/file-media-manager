<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\ProgramScheduleRepository;
use MediaManager\Repositories\ShowRepository;
use PDOException;

Auth::requireAdmin();

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$showRepo = new ShowRepository();
$scheduleRepo = new ProgramScheduleRepository();
$audit    = new AuditRepository();

/** @param list<string> $aliases */
function dictionary_audit(
    AuditRepository $audit,
    string $action,
    ?int $entityId,
    array $details = []
): void {
    $user = Auth::user();
    $audit->record(
        Auth::id(),
        $user['email'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
        $action,
        'show',
        $entityId,
        null,
        null,
        $details
    );
}

/** @return list<string> */
function parse_alias_input(string $input): array
{
    $parts = preg_split('/[\r\n,]+/', $input) ?: [];
    $aliases = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $aliases[] = $part;
        }
    }

    return array_values(array_unique($aliases));
}

if ($method === 'POST') {
    $csrf = $_POST['_csrf'] ?? '';
    if (!Session::validateCsrf($csrf)) {
        Session::flash('error', 'Invalid request. Please try again.');
        header('Location: /dictionary');
        exit;
    }

    if ($uri === '/dictionary/create') {
        $canonical = trim($_POST['canonical_name'] ?? '');
        $abbrev    = trim($_POST['abbreviation'] ?? '');
        $aliases   = parse_alias_input($_POST['aliases'] ?? '');
        $notes     = trim($_POST['notes'] ?? '');

        if ($canonical === '' || $abbrev === '') {
            Session::flash('error', 'Canonical name and abbreviation are required.');
            header('Location: /dictionary');
            exit;
        }

        try {
            $id = $showRepo->create($canonical, $abbrev, $aliases, $notes);
            dictionary_audit($audit, 'SHOW_CREATED', $id, [
                'canonical_name' => $canonical,
                'abbreviation'   => strtoupper($abbrev),
            ]);
            Session::flash('success', 'Show added to dictionary.');
        } catch (PDOException $e) {
            $msg = $showRepo->isUniqueViolation($e)
                ? 'That abbreviation is already in use.'
                : 'Could not create show.';
            Session::flash('error', $msg);
        }

        header('Location: /dictionary');
        exit;
    }

    if ($uri === '/dictionary/update') {
        $id        = (int) ($_POST['id'] ?? 0);
        $canonical = trim($_POST['canonical_name'] ?? '');
        $abbrev    = trim($_POST['abbreviation'] ?? '');
        $aliases   = parse_alias_input($_POST['aliases'] ?? '');
        $notes     = trim($_POST['notes'] ?? '');
        $active    = isset($_POST['active']);

        if ($id <= 0 || $canonical === '' || $abbrev === '') {
            Session::flash('error', 'Invalid show data.');
            header('Location: /dictionary');
            exit;
        }

        try {
            $showRepo->update($id, $canonical, $abbrev, $aliases, $notes, $active);
            dictionary_audit($audit, 'SHOW_UPDATED', $id, [
                'canonical_name' => $canonical,
                'abbreviation'   => strtoupper($abbrev),
                'active'         => $active,
            ]);
            Session::flash('success', 'Show updated.');
        } catch (PDOException $e) {
            $msg = $showRepo->isUniqueViolation($e)
                ? 'That abbreviation is already in use.'
                : 'Could not update show.';
            Session::flash('error', $msg);
        }

        header('Location: /dictionary');
        exit;
    }

    if ($uri === '/dictionary/delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            Session::flash('error', 'Invalid show.');
            header('Location: /dictionary');
            exit;
        }

        $show = $showRepo->findById($id);
        if ($show === null) {
            Session::flash('error', 'Show not found.');
            header('Location: /dictionary');
            exit;
        }

        try {
            $showRepo->delete($id);
            dictionary_audit($audit, 'SHOW_DELETED', $id, [
                'canonical_name' => $show['canonical_name'] ?? '',
                'abbreviation'   => $show['abbreviation'] ?? '',
            ]);
            Session::flash('success', 'Show deleted.');
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            header('Location: /dictionary?edit=' . $id);
            exit;
        } catch (PDOException $e) {
            Session::flash('error', 'Could not delete show (it may still be referenced).');
            header('Location: /dictionary?edit=' . $id);
            exit;
        }

        header('Location: /dictionary');
        exit;
    }

    if ($uri === '/dictionary/merge') {
        $canonicalId = (int) ($_POST['canonical_id'] ?? 0);
        $absorbedRaw = $_POST['absorbed_ids'] ?? [];
        if (!is_array($absorbedRaw)) {
            $absorbedRaw = [$absorbedRaw];
        }
        $absorbedIds = array_values(array_filter(array_map('intval', $absorbedRaw)));

        if ($canonicalId <= 0 || $absorbedIds === []) {
            Session::flash('error', 'Select a canonical show and at least one show to merge.');
            header('Location: /dictionary');
            exit;
        }

        try {
            $counts = $showRepo->mergeInto($canonicalId, $absorbedIds);
            dictionary_audit($audit, 'SHOWS_MERGED', $canonicalId, [
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

        header('Location: /dictionary');
        exit;
    }

    http_response_code(404);
    exit;
}

$shows = $showRepo->all();
$scheduleCounts = $scheduleRepo->countByShow();
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editShow = $editId > 0 ? $showRepo->findById($editId) : null;
$showsTab = 'dictionary';

$title = 'Shows — Media Manager';
require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/dictionary/index.php';
require dirname(__DIR__) . '/Views/layouts/footer.php';
