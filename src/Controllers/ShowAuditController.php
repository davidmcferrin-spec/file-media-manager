<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\CompletenessRepository;
use MediaManager\Repositories\ExpectedGapRepository;
use MediaManager\Repositories\ProgramScheduleRepository;
use MediaManager\Repositories\ShowRepository;
use MediaManager\Repositories\SystemRepository;
use MediaManager\Services\CompletenessService;
use MediaManager\Services\DateNormalizer;
use MediaManager\Services\ScheduleTimeParser;

Auth::requireLogin();

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$service      = new CompletenessService();
$gapRepo      = new ExpectedGapRepository();
$scheduleRepo = new ProgramScheduleRepository();
$showRepo     = new ShowRepository();
$filesRepo    = new CompletenessRepository();
$systemRepo   = new SystemRepository();
$audit        = new AuditRepository();

function show_audit_redirect(array $params = []): string
{
    $base = [
        'from'   => $_POST['return_from'] ?? $_GET['from'] ?? '',
        'to'     => $_POST['return_to'] ?? $_GET['to'] ?? '',
        'mode'   => $_POST['return_mode'] ?? $_GET['mode'] ?? '',
        'grain'  => $_POST['return_grain'] ?? $_GET['grain'] ?? '',
        'show_id'=> $_POST['return_show_id'] ?? $_GET['show_id'] ?? '',
        'status' => $_POST['return_status'] ?? $_GET['status'] ?? '',
        'tab'    => $_POST['return_tab'] ?? $_GET['tab'] ?? '',
    ];
    foreach ($params as $k => $v) {
        $base[$k] = $v;
    }
    $query = [];
    foreach ($base as $k => $v) {
        if ($v !== null && $v !== '' && $v !== '0') {
            $query[$k] = $v;
        }
    }

    return '/show-audit' . ($query !== [] ? '?' . http_build_query($query) : '');
}

if ($method === 'POST') {
    $csrf = $_POST['_csrf'] ?? '';
    if (!Session::validateCsrf($csrf)) {
        Session::flash('error', 'Invalid request.');
        header('Location: /show-audit');
        exit;
    }

    if ($uri === '/show-audit/gap') {
        $showId = (int) ($_POST['show_id'] ?? 0);
        $airDate = trim((string) ($_POST['air_date'] ?? ''));
        $hour = trim((string) ($_POST['hour_start_et'] ?? ''));
        $lane = strtolower(trim((string) ($_POST['media_lane'] ?? 'both')));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if (!in_array($lane, ['program', 'clean', 'both'], true)) {
            $lane = 'both';
        }

        $hourNorm = null;
        if (preg_match('/^(\d{1,2}):(\d{2})/', $hour, $m) === 1) {
            $hourNorm = sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]);
        } elseif (DateNormalizer::timeToMinutes($hour) !== null) {
            $mins = DateNormalizer::timeToMinutes($hour);
            $hourNorm = ScheduleTimeParser::minutesToTime((int) $mins);
        }

        if ($showId <= 0 || $reason === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $airDate) || $hourNorm === null) {
            Session::flash('error', 'Show, date, hour, and reason are required to flag an expected gap.');
            header('Location: ' . show_audit_redirect(['tab' => 'gaps']));
            exit;
        }

        $id = $gapRepo->upsert([
            'show_id'       => $showId,
            'air_date'      => $airDate,
            'hour_start_et' => $hourNorm,
            'media_lane'    => $lane,
            'reason'        => $reason,
            'notes'         => $notes,
            'created_by'    => Auth::id(),
        ]);

        $user = Auth::user();
        $audit->record(
            Auth::id(),
            $user['email'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
            'EXPECTED_GAP_FLAGGED',
            'schedule_expected_gap',
            $id,
            null,
            null,
            [
                'show_id' => $showId,
                'air_date'=> $airDate,
                'hour'    => $hourNorm,
                'lane'    => $lane,
                'reason'  => $reason,
            ]
        );

        Session::flash('success', 'Expected gap flagged and excluded from unfilled counts.');
        header('Location: ' . show_audit_redirect(['tab' => 'gaps']));
        exit;
    }

    if ($uri === '/show-audit/gap/delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $row = $id > 0 ? $gapRepo->findById($id) : null;
        if ($row === null) {
            Session::flash('error', 'Expected gap not found.');
            header('Location: ' . show_audit_redirect(['tab' => 'gaps']));
            exit;
        }
        $gapRepo->delete($id);
        $user = Auth::user();
        $audit->record(
            Auth::id(),
            $user['email'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
            'EXPECTED_GAP_REMOVED',
            'schedule_expected_gap',
            $id,
            null,
            null,
            ['show_id' => (int) $row['show_id'], 'air_date' => $row['air_date']]
        );
        Session::flash('success', 'Expected gap removed — slot will count as unfilled again if still missing.');
        header('Location: ' . show_audit_redirect(['tab' => 'gaps']));
        exit;
    }

    if ($uri === '/show-audit/schedule/close') {
        Auth::requireAdmin();
        $entryId = (int) ($_POST['id'] ?? 0);
        $effectiveTo = trim((string) ($_POST['effective_to'] ?? ''));
        $entry = $entryId > 0 ? $scheduleRepo->findById($entryId) : null;

        if ($entry === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveTo)) {
            Session::flash('error', 'Valid schedule entry and end date are required.');
            header('Location: ' . show_audit_redirect(['tab' => 'schedule']));
            exit;
        }

        $scheduleRepo->setEffectiveTo($entryId, $effectiveTo);
        $systemRepo->set('timeline_ready_for_scan', 'false');
        $user = Auth::user();
        $audit->record(
            Auth::id(),
            $user['email'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
            'SCHEDULE_END_DATE_SET',
            'program_schedule',
            $entryId,
            null,
            null,
            ['effective_to' => $effectiveTo, 'show_id' => (int) $entry['show_id']]
        );
        Session::flash('success', 'Schedule entry end date set to ' . $effectiveTo . '.');
        header('Location: ' . show_audit_redirect(['tab' => 'schedule']));
        exit;
    }

    http_response_code(404);
    exit;
}

// ── GET ──────────────────────────────────────────────────────
$tz = new \DateTimeZone('America/New_York');
$today = new \DateTimeImmutable('now', $tz);
$defaultFrom = $today->modify('-30 days')->format('Ymd');
$defaultTo = $today->format('Ymd');

$fromRaw = trim((string) ($_GET['from'] ?? ''));
$toRaw = trim((string) ($_GET['to'] ?? ''));

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw) === 1) {
    $fromYmd = str_replace('-', '', $fromRaw);
} elseif (DateNormalizer::isValidDate($fromRaw)) {
    $fromYmd = $fromRaw;
} else {
    $fromYmd = $defaultFrom;
}

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw) === 1) {
    $toYmd = str_replace('-', '', $toRaw);
} elseif (DateNormalizer::isValidDate($toRaw)) {
    $toYmd = $toRaw;
} else {
    $toYmd = $defaultTo;
}

if ($fromYmd > $toYmd) {
    [$fromYmd, $toYmd] = [$toYmd, $fromYmd];
}

// Cap range to 92 days to keep audits responsive
$fromDt = \DateTimeImmutable::createFromFormat('Ymd', $fromYmd, $tz);
$toDt = \DateTimeImmutable::createFromFormat('Ymd', $toYmd, $tz);
if ($fromDt !== false && $toDt !== false && $fromDt->diff($toDt)->days > 92) {
    $toYmd = $fromDt->modify('+92 days')->format('Ymd');
    Session::flash('error', 'Date range capped at 92 days for performance.');
}

$mode   = strtolower(trim((string) ($_GET['mode'] ?? 'either')));
$grain  = strtolower(trim((string) ($_GET['grain'] ?? 'hourly')));
$showId = isset($_GET['show_id']) ? (int) $_GET['show_id'] : 0;
$status = trim((string) ($_GET['status'] ?? ''));
$tab    = trim((string) ($_GET['tab'] ?? 'overview'));
if (!in_array($tab, ['overview', 'gaps', 'duplicates', 'unmatched', 'schedule'], true)) {
    $tab = 'overview';
}

$report = $service->audit(
    $fromYmd,
    $toYmd,
    $mode,
    $grain,
    $showId > 0 ? $showId : null,
    $status !== '' ? $status : null
);

$unmatchedSearch = trim((string) ($_GET['uq'] ?? ''));
$unmatchedPage = max(1, (int) ($_GET['upage'] ?? 1));
$unmatchedPerPage = 50;
$unmatchedOffset = ($unmatchedPage - 1) * $unmatchedPerPage;
$unmatchedFiles = $filesRepo->listUnmatched(
    $unmatchedPerPage,
    $unmatchedOffset,
    $unmatchedSearch !== '' ? $unmatchedSearch : null
);
$unmatchedTotal = $filesRepo->countUnmatched($unmatchedSearch !== '' ? $unmatchedSearch : null);
$unmatchedPages = max(1, (int) ceil($unmatchedTotal / $unmatchedPerPage));

$openEnded = [];
$openEndedTotal = 0;
if (Auth::isAdmin()) {
    $openEnded = $scheduleRepo->listOpenEnded(100, 0);
    $openEndedTotal = $scheduleRepo->countOpenEnded();
}

$shows = $showRepo->all(true);
$showsTab = 'show-audit';
$fromIso = substr($fromYmd, 0, 4) . '-' . substr($fromYmd, 4, 2) . '-' . substr($fromYmd, 6, 2);
$toIso = substr($toYmd, 0, 4) . '-' . substr($toYmd, 4, 2) . '-' . substr($toYmd, 6, 2);

$previewWidth       = (int) env('PREVIEW_WIDTH', 420);
$previewHeight      = (int) env('PREVIEW_HEIGHT', 236);
$previewDurationMin = (int) round(((int) env('PREVIEW_DURATION_SECONDS', 180)) / 60);

$title = 'Gaps — Media Manager';
require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/show_audit/index.php';
require dirname(__DIR__) . '/Views/layouts/footer.php';
