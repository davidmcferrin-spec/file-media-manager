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

/**
 * @param array<string, scalar|null> $params
 */
function show_audit_redirect(array $params = []): string
{
    $base = [
        'tab'     => $_POST['return_tab'] ?? $_GET['tab'] ?? '',
        'view'    => $_POST['return_view'] ?? $_GET['view'] ?? '',
        'year'    => $_POST['return_year'] ?? $_GET['year'] ?? '',
        'month'   => $_POST['return_month'] ?? $_GET['month'] ?? '',
        'date'    => $_POST['return_date'] ?? $_GET['date'] ?? '',
        'from'    => $_POST['return_from'] ?? $_GET['from'] ?? '',
        'to'      => $_POST['return_to'] ?? $_GET['to'] ?? '',
        'mode'    => $_POST['return_mode'] ?? $_GET['mode'] ?? '',
        'show_id' => $_POST['return_show_id'] ?? $_GET['show_id'] ?? '',
        'status'  => $_POST['return_status'] ?? $_GET['status'] ?? '',
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

/**
 * @return list<string> HH:MM:SS hour starts to flag
 */
function show_audit_gap_hours(string $hourStart, string $hourEnd): array
{
    $parse = static function (string $hour): ?int {
        if (preg_match('/^(\d{1,2}):(\d{2})/', $hour, $m) === 1) {
            return ((int) $m[1]) * 60 + (int) $m[2];
        }
        return DateNormalizer::timeToMinutes($hour);
    };

    $startMin = $parse($hourStart);
    if ($startMin === null) {
        return [];
    }
    $startHour = (int) (floor($startMin / 60) * 60);

    $endMin = $hourEnd !== '' ? $parse($hourEnd) : null;
    if ($endMin === null) {
        return [ScheduleTimeParser::minutesToTime($startHour)];
    }
    $endHour = (int) (floor($endMin / 60) * 60);
    if ($endHour < $startHour) {
        $endHour = $startHour;
    }

    $hours = [];
    for ($h = $startHour; $h <= $endHour; $h += 60) {
        if ($h >= 24 * 60) {
            break;
        }
        $hours[] = ScheduleTimeParser::minutesToTime($h);
    }

    return $hours;
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
        $hourEnd = trim((string) ($_POST['hour_end_et'] ?? ''));
        $lane = strtolower(trim((string) ($_POST['media_lane'] ?? 'both')));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if (!in_array($lane, ['program', 'clean', 'both'], true)) {
            $lane = 'both';
        }

        $hourList = show_audit_gap_hours($hour, $hourEnd);

        if ($showId <= 0 || $reason === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $airDate) || $hourList === []) {
            Session::flash('error', 'Show, date, hour, and reason are required to accept an expected gap.');
            header('Location: ' . show_audit_redirect(['tab' => 'calendar']));
            exit;
        }

        $ids = [];
        foreach ($hourList as $hourNorm) {
            $ids[] = $gapRepo->upsert([
                'show_id'       => $showId,
                'air_date'      => $airDate,
                'hour_start_et' => $hourNorm,
                'media_lane'    => $lane,
                'reason'        => $reason,
                'notes'         => $notes,
                'created_by'    => Auth::id(),
            ]);
        }

        $user = Auth::user();
        $audit->record(
            Auth::id(),
            $user['email'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
            'EXPECTED_GAP_FLAGGED',
            'schedule_expected_gap',
            $ids[0] ?? 0,
            null,
            null,
            [
                'show_id' => $showId,
                'air_date'=> $airDate,
                'hours'   => $hourList,
                'lane'    => $lane,
                'reason'  => $reason,
            ]
        );

        $n = count($hourList);
        Session::flash(
            'success',
            $n === 1
                ? 'Expected gap accepted and excluded from unfilled counts.'
                : $n . ' hourly expected gaps accepted and excluded from unfilled counts.'
        );
        header('Location: ' . show_audit_redirect(['tab' => 'calendar']));
        exit;
    }

    if ($uri === '/show-audit/gap/delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $row = $id > 0 ? $gapRepo->findById($id) : null;
        if ($row === null) {
            Session::flash('error', 'Expected gap not found.');
            header('Location: ' . show_audit_redirect(['tab' => 'calendar']));
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
        header('Location: ' . show_audit_redirect(['tab' => 'calendar']));
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

$tab = trim((string) ($_GET['tab'] ?? 'calendar'));
// Legacy tab names
if (in_array($tab, ['overview', 'gaps'], true)) {
    $tab = 'calendar';
}
if (!in_array($tab, ['calendar', 'duplicates', 'unmatched', 'schedule', 'accepted'], true)) {
    $tab = 'calendar';
}

$view = strtolower(trim((string) ($_GET['view'] ?? 'year')));
if (!in_array($view, ['year', 'month', 'week', 'day', 'show'], true)) {
    $view = 'year';
}

$year = (int) ($_GET['year'] ?? 2025);
if ($year < 2000 || $year > 2100) {
    $year = 2025;
}
$month = (int) ($_GET['month'] ?? 1);
if ($month < 1 || $month > 12) {
    $month = 1;
}

$dateRaw = trim((string) ($_GET['date'] ?? ''));
$focusDate = null;
if (in_array($view, ['year', 'show'], true)) {
    // Year selector is authoritative for year / show runway
    $focusDate = \DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-01-01', $year), $tz);
} elseif ($view === 'month') {
    $focusDate = \DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $year, $month), $tz);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) === 1) {
        $fromDate = \DateTimeImmutable::createFromFormat('Y-m-d', $dateRaw, $tz);
        if ($fromDate !== false && (int) $fromDate->format('Y') === $year && (int) $fromDate->format('n') === $month) {
            $focusDate = $fromDate;
        }
    }
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) === 1) {
    $focusDate = \DateTimeImmutable::createFromFormat('Y-m-d', $dateRaw, $tz);
}

if ($focusDate === false || $focusDate === null) {
    $focusDate = \DateTimeImmutable::createFromFormat('Y-m-d', '2025-01-01', $tz);
}
if ($focusDate === false) {
    $focusDate = new \DateTimeImmutable('2025-01-01', $tz);
}

if (!in_array($view, ['year', 'show'], true)) {
    $year = (int) $focusDate->format('Y');
    $month = (int) $focusDate->format('n');
} else {
    $month = 1;
}
$dateIso = $focusDate->format('Y-m-d');

$mode   = strtolower(trim((string) ($_GET['mode'] ?? 'either')));
$showId = isset($_GET['show_id']) ? (int) $_GET['show_id'] : 0;
$status = trim((string) ($_GET['status'] ?? ''));

// Resolve audit range from calendar view
$weekStart = $focusDate;
$dow = (int) $focusDate->format('N');
if ($dow !== 1) {
    $weekStart = $focusDate->modify('-' . ($dow - 1) . ' days');
}

if ($view === 'year' || ($tab === 'calendar' && $view === 'year')) {
    $fromYmd = sprintf('%04d0101', $year);
    $toYmd = sprintf('%04d1231', $year);
} elseif ($view === 'month') {
    $monthStart = \DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $year, $month), $tz);
    $fromYmd = $monthStart->format('Ymd');
    $toYmd = $monthStart->modify('last day of this month')->format('Ymd');
} elseif ($view === 'week') {
    $fromYmd = $weekStart->format('Ymd');
    $toYmd = $weekStart->modify('+6 days')->format('Ymd');
} elseif ($view === 'day') {
    $fromYmd = $focusDate->format('Ymd');
    $toYmd = $fromYmd;
} else { // show runway — full year for selected show
    $fromYmd = sprintf('%04d0101', $year);
    $toYmd = sprintf('%04d1231', $year);
}

// Secondary tabs may still pass from/to
if ($tab !== 'calendar') {
    $fromRaw = trim((string) ($_GET['from'] ?? ''));
    $toRaw = trim((string) ($_GET['to'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw) === 1) {
        $fromYmd = str_replace('-', '', $fromRaw);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw) === 1) {
        $toYmd = str_replace('-', '', $toRaw);
    }
    if ($fromYmd > $toYmd) {
        [$fromYmd, $toYmd] = [$toYmd, $fromYmd];
    }
    $fromDt = \DateTimeImmutable::createFromFormat('Ymd', $fromYmd, $tz);
    $toDt = \DateTimeImmutable::createFromFormat('Ymd', $toYmd, $tz);
    if ($fromDt !== false && $toDt !== false && $fromDt->diff($toDt)->days > 366) {
        $toYmd = $fromDt->modify('+366 days')->format('Ymd');
        Session::flash('error', 'Date range capped at 366 days for performance.');
    }
}

$includeSlots = true;
if ($tab === 'calendar' && ($view === 'year' || ($view === 'show' && $showId <= 0))) {
    $includeSlots = false;
}

if ($tab === 'calendar' && $view === 'year') {
    $report = $service->calendarYear($year, $mode, $showId > 0 ? $showId : null);
} else {
    $report = $service->audit(
        $fromYmd,
        $toYmd,
        $mode,
        'hourly',
        $showId > 0 ? $showId : null,
        $status !== '' ? $status : null,
        $includeSlots
    );
}

$monthGrid = [];
$weekGrid = null;
if ($tab === 'calendar' && $view === 'month') {
    $monthGrid = $service->buildMonthGrid($year, $month, $report['day_rollups']);
}
if ($tab === 'calendar' && $view === 'week') {
    $weekGrid = $service->buildWeekGrid($weekStart->format('Y-m-d'), $report['slots']);
}

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
