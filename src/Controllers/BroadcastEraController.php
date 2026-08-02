<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\BroadcastEraRepository;
use MediaManager\Repositories\BroadcastEraWindowRepository;
use MediaManager\Repositories\SystemRepository;
use MediaManager\Services\BroadcastEraService;
use MediaManager\Support\ScheduleEntryParser;

Auth::requireAdmin();

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$eraRepo    = new BroadcastEraRepository();
$windowRepo = new BroadcastEraWindowRepository();
$eraService = new BroadcastEraService();
$systemRepo = new SystemRepository();
$audit      = new AuditRepository();

function eras_audit(AuditRepository $audit, string $action, ?int $entityId, array $details = []): void
{
    $user = Auth::user();
    $audit->record(
        Auth::id(),
        $user['email'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
        $action,
        'broadcast_era',
        $entityId,
        null,
        null,
        $details
    );
}

function eras_clear_ready(SystemRepository $system): void
{
    $system->set('timeline_ready_for_scan', 'false');
    $system->set('timeline_ready_at', '');
}

function eras_parse_days(array $post): int
{
    $daysRaw = $post['days'] ?? [];
    if (!is_array($daysRaw)) {
        $daysRaw = [];
    }
    $mask = 0;
    foreach ($daysRaw as $bit) {
        $bit = (int) $bit;
        if (in_array($bit, [1, 2, 4, 8, 16, 32, 64], true)) {
            $mask |= $bit;
        }
    }

    return $mask;
}

/** @return array{0: int, 1: string}|null */
function eras_match_path(string $uri): ?array
{
    if (preg_match('#^/eras/(\d+)(/.*)?$#', $uri, $m) !== 1) {
        return null;
    }

    return [(int) $m[1], $m[2] ?? ''];
}

if ($method === 'POST') {
    $csrf = $_POST['_csrf'] ?? '';
    if (!Session::validateCsrf($csrf)) {
        Session::flash('error', 'Invalid request.');
        header('Location: /eras');
        exit;
    }

    if ($uri === '/eras/create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $from = trim((string) ($_POST['effective_from'] ?? ''));
        $to   = trim((string) ($_POST['effective_to'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $sort = (int) ($_POST['sort_order'] ?? 0);
        if ($name === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            Session::flash('error', 'Name and start date are required.');
            header('Location: /eras');
            exit;
        }
        if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            Session::flash('error', 'End date must be YYYY-MM-DD or blank.');
            header('Location: /eras');
            exit;
        }
        $id = $eraRepo->create($name, $from, $to !== '' ? $to : null, $notes, $sort, true);
        eras_audit($audit, 'ERA_CREATED', $id, ['name' => $name]);
        Session::flash('success', 'Era created — add broadcast windows.');
        header('Location: /eras/' . $id);
        exit;
    }

    if ($uri === '/eras/update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $from = trim((string) ($_POST['effective_from'] ?? ''));
        $to   = trim((string) ($_POST['effective_to'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $sort = (int) ($_POST['sort_order'] ?? 0);
        $active = isset($_POST['active']);
        if ($id <= 0 || $name === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            Session::flash('error', 'Invalid era data.');
            header('Location: /eras');
            exit;
        }
        if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            Session::flash('error', 'End date must be YYYY-MM-DD or blank.');
            header('Location: /eras/' . $id);
            exit;
        }
        $eraRepo->update($id, $name, $from, $to !== '' ? $to : null, $notes, $sort, $active);
        eras_audit($audit, 'ERA_UPDATED', $id, ['name' => $name]);
        eras_clear_ready($systemRepo);
        Session::flash('success', 'Era saved.');
        header('Location: /eras/' . $id);
        exit;
    }

    if ($uri === '/eras/delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $eraRepo->delete($id);
            eras_audit($audit, 'ERA_DELETED', $id, []);
            eras_clear_ready($systemRepo);
            Session::flash('success', 'Era deleted.');
        }
        header('Location: /eras');
        exit;
    }

    $pathMatch = eras_match_path($uri);
    if ($pathMatch !== null) {
        [$eraId, $suffix] = $pathMatch;
        $era = $eraRepo->findById($eraId);
        if ($era === null) {
            Session::flash('error', 'Era not found.');
            header('Location: /eras');
            exit;
        }

        if ($suffix === '/windows/create') {
            $start = ScheduleEntryParser::normalizeTime((string) ($_POST['hour_start_et'] ?? ''));
            $end = ScheduleEntryParser::normalizeTime((string) ($_POST['hour_end_et'] ?? ''));
            $mask = eras_parse_days($_POST);
            $notes = trim((string) ($_POST['notes'] ?? ''));
            if ($start === null || $end === null || $mask === 0) {
                Session::flash('error', 'Window needs start/end time and at least one day.');
                header('Location: /eras/' . $eraId . '#windows');
                exit;
            }
            $windowRepo->insert($eraId, $start, $end, $mask, $notes);
            eras_clear_ready($systemRepo);
            Session::flash('success', 'Broadcast window added.');
            header('Location: /eras/' . $eraId . '#windows');
            exit;
        }

        if ($suffix === '/windows/update') {
            $wid = (int) ($_POST['id'] ?? 0);
            $win = $wid > 0 ? $windowRepo->findById($wid) : null;
            if ($win === null || (int) $win['era_id'] !== $eraId) {
                Session::flash('error', 'Window not found.');
                header('Location: /eras/' . $eraId . '#windows');
                exit;
            }
            $start = ScheduleEntryParser::normalizeTime((string) ($_POST['hour_start_et'] ?? ''));
            $end = ScheduleEntryParser::normalizeTime((string) ($_POST['hour_end_et'] ?? ''));
            $mask = eras_parse_days($_POST);
            $notes = trim((string) ($_POST['notes'] ?? ''));
            if ($start === null || $end === null || $mask === 0) {
                Session::flash('error', 'Invalid window data.');
                header('Location: /eras/' . $eraId . '?edit_window=' . $wid . '#windows');
                exit;
            }
            $windowRepo->update($wid, $start, $end, $mask, $notes);
            eras_clear_ready($systemRepo);
            Session::flash('success', 'Window updated.');
            header('Location: /eras/' . $eraId . '#windows');
            exit;
        }

        if ($suffix === '/windows/delete') {
            $wid = (int) ($_POST['id'] ?? 0);
            $win = $wid > 0 ? $windowRepo->findById($wid) : null;
            if ($win !== null && (int) $win['era_id'] === $eraId) {
                $windowRepo->delete($wid);
                eras_clear_ready($systemRepo);
                Session::flash('success', 'Window deleted.');
            }
            header('Location: /eras/' . $eraId . '#windows');
            exit;
        }

        if ($suffix === '/adopt') {
            $showId = (int) ($_POST['show_id'] ?? 0);
            $windowIds = $_POST['window_ids'] ?? null;
            if (is_array($windowIds)) {
                $windowIds = array_values(array_filter(array_map('intval', $windowIds), static fn (int $i): bool => $i > 0));
                if ($windowIds === []) {
                    $windowIds = null; // all windows
                }
            } else {
                $windowIds = null;
            }
            try {
                $n = $eraService->adoptShow($eraId, $showId, $windowIds);
                eras_audit($audit, 'ERA_SHOW_ADOPTED', $eraId, [
                    'show_id' => $showId,
                    'slots'   => $n,
                ]);
                eras_clear_ready($systemRepo);
                Session::flash('success', "Adopted show into era ($n schedule slot(s)).");
            } catch (\Throwable $e) {
                Session::flash('error', $e->getMessage());
            }
            header('Location: /eras/' . $eraId . '#shows');
            exit;
        }
    }

    Session::flash('error', 'Unknown action.');
    header('Location: /eras');
    exit;
}

$pathMatch = eras_match_path($uri);
if ($pathMatch !== null && ($pathMatch[1] === '' || $pathMatch[1] === '/')) {
    $eraId = $pathMatch[0];
    $era = $eraRepo->findById($eraId);
    if ($era === null) {
        Session::flash('error', 'Era not found.');
        header('Location: /eras');
        exit;
    }
    $windows = $windowRepo->listForEra($eraId);
    $membership = $eraService->eraShowMembership($eraId);
    $editWindowId = isset($_GET['edit_window']) ? (int) $_GET['edit_window'] : 0;
    $editWindow = null;
    if ($editWindowId > 0) {
        $candidate = $windowRepo->findById($editWindowId);
        if ($candidate !== null && (int) $candidate['era_id'] === $eraId) {
            $editWindow = $candidate;
        }
    }
    $showsTab = 'eras';
    $title = (string) $era['name'] . ' — Era — Media Manager';
    require dirname(__DIR__) . '/Views/layouts/header.php';
    require dirname(__DIR__) . '/Views/eras/show.php';
    require dirname(__DIR__) . '/Views/layouts/footer.php';
    exit;
}

if ($uri !== '/eras') {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$eras = $eraRepo->all();
$windowCounts = [];
foreach ($eras as $e) {
    $windowCounts[(int) $e['id']] = count($windowRepo->listForEra((int) $e['id']));
}
$showsTab = 'eras';
$title = 'Broadcast Eras — Media Manager';
require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/eras/index.php';
require dirname(__DIR__) . '/Views/layouts/footer.php';
