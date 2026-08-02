<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\BroadcastEraRepository;
use MediaManager\Repositories\ProgramScheduleRepository;
use MediaManager\Repositories\ShowRepository;
use MediaManager\Repositories\SystemRepository;
use MediaManager\Support\ScheduleEntryParser;
use PDOException;

Auth::requireAdmin();

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$showRepo     = new ShowRepository();
$scheduleRepo = new ProgramScheduleRepository();
$eraRepo      = new BroadcastEraRepository();
$systemRepo   = new SystemRepository();
$audit        = new AuditRepository();

/** @param list<string> $aliases */
function shows_parse_aliases(string $input): array
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

function shows_audit(AuditRepository $audit, string $action, ?int $entityId, array $details = []): void
{
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

function shows_clear_timeline_ready(SystemRepository $system): void
{
    $system->set('timeline_ready_for_scan', 'false');
    $system->set('timeline_ready_at', '');
}

/** @return array{0: int, 1: string}|null */
function shows_match_show_path(string $uri): ?array
{
    if (preg_match('#^/shows/(\d+)(/.*)?$#', $uri, $m) !== 1) {
        return null;
    }

    return [(int) $m[1], $m[2] ?? ''];
}

if ($method === 'POST') {
    $csrf = $_POST['_csrf'] ?? '';
    if (!Session::validateCsrf($csrf)) {
        Session::flash('error', 'Invalid request. Please try again.');
        header('Location: /shows');
        exit;
    }

    if ($uri === '/shows/create') {
        $canonical = trim((string) ($_POST['canonical_name'] ?? ''));
        $abbrev    = trim((string) ($_POST['abbreviation'] ?? ''));
        $aliases   = shows_parse_aliases((string) ($_POST['aliases'] ?? ''));
        $notes     = trim((string) ($_POST['notes'] ?? ''));
        if ($canonical === '' || $abbrev === '') {
            Session::flash('error', 'Canonical name and abbreviation are required.');
            header('Location: /shows');
            exit;
        }
        try {
            $id = $showRepo->create($canonical, $abbrev, $aliases, $notes);
            shows_audit($audit, 'SHOW_CREATED', $id, [
                'canonical_name' => $canonical,
                'abbreviation'   => strtoupper($abbrev),
            ]);
            Session::flash('success', 'Show created — add schedule slots below.');
            header('Location: /shows/' . $id);
        } catch (PDOException $e) {
            Session::flash('error', $showRepo->isUniqueViolation($e)
                ? 'That abbreviation is already in use.'
                : 'Could not create show.');
            header('Location: /shows');
        }
        exit;
    }

    if ($uri === '/shows/update') {
        $id = (int) ($_POST['id'] ?? 0);
        $canonical = trim((string) ($_POST['canonical_name'] ?? ''));
        $abbrev    = trim((string) ($_POST['abbreviation'] ?? ''));
        $aliases   = shows_parse_aliases((string) ($_POST['aliases'] ?? ''));
        $notes     = trim((string) ($_POST['notes'] ?? ''));
        $active    = isset($_POST['active']);
        if ($id <= 0 || $canonical === '' || $abbrev === '') {
            Session::flash('error', 'Invalid show data.');
            header('Location: /shows');
            exit;
        }
        try {
            $showRepo->update($id, $canonical, $abbrev, $aliases, $notes, $active);
            shows_audit($audit, 'SHOW_UPDATED', $id, [
                'canonical_name' => $canonical,
                'abbreviation'   => strtoupper($abbrev),
            ]);
            Session::flash('success', 'Show identity saved.');
        } catch (PDOException $e) {
            Session::flash('error', $showRepo->isUniqueViolation($e)
                ? 'That abbreviation is already in use.'
                : 'Could not update show.');
        }
        header('Location: /shows/' . $id);
        exit;
    }

    if ($uri === '/shows/delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $show = $id > 0 ? $showRepo->findById($id) : null;
        if ($show === null) {
            Session::flash('error', 'Show not found.');
            header('Location: /shows');
            exit;
        }
        try {
            $showRepo->delete($id);
            shows_audit($audit, 'SHOW_DELETED', $id, [
                'canonical_name' => $show['canonical_name'] ?? '',
                'abbreviation'   => $show['abbreviation'] ?? '',
            ]);
            shows_clear_timeline_ready($systemRepo);
            Session::flash('success', 'Show deleted.');
        } catch (\Throwable $e) {
            Session::flash('error', 'Could not delete show (catalog files may still reference it).');
        }
        header('Location: /shows');
        exit;
    }

    if ($uri === '/shows/merge') {
        $canonicalId = (int) ($_POST['canonical_id'] ?? 0);
        $absorbed = $_POST['absorbed_ids'] ?? [];
        if (!is_array($absorbed)) {
            $absorbed = [];
        }
        $absorbedIds = array_values(array_filter(array_map('intval', $absorbed), static fn (int $i): bool => $i > 0));
        if ($canonicalId <= 0 || $absorbedIds === []) {
            Session::flash('error', 'Pick a canonical show and at least one show to absorb.');
            header('Location: /shows/' . max(1, $canonicalId));
            exit;
        }
        try {
            $showRepo->mergeInto($canonicalId, $absorbedIds);
            shows_audit($audit, 'SHOWS_MERGED', $canonicalId, [
                'absorbed_ids' => $absorbedIds,
            ]);
            shows_clear_timeline_ready($systemRepo);
            Session::flash('success', 'Shows merged into canonical entry.');
        } catch (\Throwable $e) {
            Session::flash('error', 'Merge failed: ' . $e->getMessage());
        }
        header('Location: /shows/' . $canonicalId);
        exit;
    }

    $pathMatch = shows_match_show_path($uri);
    if ($pathMatch !== null) {
        [$showId, $suffix] = $pathMatch;
        $show = $showRepo->findById($showId);
        if ($show === null) {
            Session::flash('error', 'Show not found.');
            header('Location: /shows');
            exit;
        }

        if ($suffix === '/slots/create') {
            $data = ScheduleEntryParser::parse($_POST, $showId);
            if ($data === null) {
                Session::flash('error', 'Invalid slot — check title, hours, days, and dates.');
                header('Location: /shows/' . $showId . '#schedule');
                exit;
            }
            if ($data['era_name'] === '' && !empty($data['broadcast_era_id'])) {
                $era = $eraRepo->findById((int) $data['broadcast_era_id']);
                if ($era !== null) {
                    $data['era_name'] = (string) $era['name'];
                }
            }
            $scheduleRepo->insert($data);
            shows_clear_timeline_ready($systemRepo);
            Session::flash('success', 'Schedule slot added.');
            header('Location: /shows/' . $showId . '#schedule');
            exit;
        }

        if ($suffix === '/slots/update') {
            $slotId = (int) ($_POST['id'] ?? 0);
            $entry = $slotId > 0 ? $scheduleRepo->findById($slotId) : null;
            if ($entry === null || (int) $entry['show_id'] !== $showId) {
                Session::flash('error', 'Schedule slot not found for this show.');
                header('Location: /shows/' . $showId . '#schedule');
                exit;
            }
            $data = ScheduleEntryParser::parse($_POST, $showId);
            if ($data === null) {
                Session::flash('error', 'Invalid slot data.');
                header('Location: /shows/' . $showId . '?edit_slot=' . $slotId . '#schedule');
                exit;
            }
            $scheduleRepo->update($slotId, $data);
            shows_clear_timeline_ready($systemRepo);
            Session::flash('success', 'Schedule slot updated.');
            header('Location: /shows/' . $showId . '#schedule');
            exit;
        }

        if ($suffix === '/slots/delete') {
            $slotId = (int) ($_POST['id'] ?? 0);
            $entry = $slotId > 0 ? $scheduleRepo->findById($slotId) : null;
            if ($entry === null || (int) $entry['show_id'] !== $showId) {
                Session::flash('error', 'Schedule slot not found.');
            } else {
                $scheduleRepo->delete($slotId);
                shows_clear_timeline_ready($systemRepo);
                Session::flash('success', 'Schedule slot deleted.');
            }
            header('Location: /shows/' . $showId . '#schedule');
            exit;
        }

        if ($suffix === '/slots/close') {
            $slotId = (int) ($_POST['id'] ?? 0);
            $effectiveTo = trim((string) ($_POST['effective_to'] ?? ''));
            $entry = $slotId > 0 ? $scheduleRepo->findById($slotId) : null;
            if ($entry === null || (int) $entry['show_id'] !== $showId
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveTo)
            ) {
                Session::flash('error', 'Valid slot and end date required.');
            } else {
                $scheduleRepo->setEffectiveTo($slotId, $effectiveTo);
                shows_clear_timeline_ready($systemRepo);
                Session::flash('success', 'Slot closed on ' . $effectiveTo . '.');
            }
            header('Location: /shows/' . $showId . '#schedule');
            exit;
        }
    }

    Session::flash('error', 'Unknown action.');
    header('Location: /shows');
    exit;
}

// GET /shows/{id}
$pathMatch = shows_match_show_path($uri);
if ($pathMatch !== null && ($pathMatch[1] === '' || $pathMatch[1] === '/')) {
    $showId = $pathMatch[0];
    $show = $showRepo->findById($showId);
    if ($show === null) {
        Session::flash('error', 'Show not found.');
        header('Location: /shows');
        exit;
    }

    $slots = $scheduleRepo->list(500, 0, null, $showId);
    $eras = $eraRepo->all(true);
    $allShows = $showRepo->all();
    $editSlotId = isset($_GET['edit_slot']) ? (int) $_GET['edit_slot'] : 0;
    $editSlot = null;
    if ($editSlotId > 0) {
        $candidate = $scheduleRepo->findById($editSlotId);
        if ($candidate !== null && (int) $candidate['show_id'] === $showId) {
            $editSlot = $candidate;
        }
    }
    $aliases = json_decode((string) ($show['aliases'] ?? '[]'), true);
    if (!is_array($aliases)) {
        $aliases = [];
    }
    $showsTab = 'shows';
    $title = (string) $show['abbreviation'] . ' — Show — Media Manager';
    require dirname(__DIR__) . '/Views/layouts/header.php';
    require dirname(__DIR__) . '/Views/shows/show.php';
    require dirname(__DIR__) . '/Views/layouts/footer.php';
    exit;
}

if ($uri !== '/shows') {
    http_response_code(404);
    echo 'Not found';
    exit;
}

// GET /shows list
$shows = $showRepo->all();
$slotCounts = $scheduleRepo->countByShow();
$showsTab = 'shows';
$title = 'Shows — Media Manager';
require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/shows/index.php';
require dirname(__DIR__) . '/Views/layouts/footer.php';
