<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Repositories\LibraryStatsRepository;

Auth::requireLogin();

function parseTimelineFrom(string $raw): ?string
{
    if (!preg_match('/^\d{4}-\d{2}$/', $raw)) {
        return null;
    }

    try {
        $dt = new \DateTimeImmutable($raw . '-01');
    } catch (\Exception) {
        return null;
    }

    return $dt->format('Y-m');
}

function shiftTimelineFrom(string $fromYm, int $months): string
{
    $dt = new \DateTimeImmutable($fromYm . '-01');
    if ($months >= 0) {
        $dt = $dt->modify('+' . $months . ' month');
    } else {
        $dt = $dt->modify((string) $months . ' month');
    }

    return $dt->format('Y-m');
}

function formatTimelineWindowTitle(string $fromYm, int $monthCount): string
{
    try {
        $start = new \DateTimeImmutable($fromYm . '-01');
        $end   = $start->modify('+' . ($monthCount - 1) . ' month');
    } catch (\Exception) {
        return 'Content hours by month';
    }

    return sprintf(
        '%s – %s',
        $start->format('M Y'),
        $end->format('M Y')
    );
}

function timelineMonthUrl(string $fromYm): string
{
    return '/dashboard/library?view=month&from=' . rawurlencode($fromYm);
}

$stats = new LibraryStatsRepository();

$extensions  = $stats->extensionBreakdown();
$resolutions = $stats->resolutionBreakdown();
$codecs      = $stats->codecBreakdown();
$duration    = $stats->durationSummary();
$excluded    = $stats->timelineExcludedSummary();

$timelineView = ($_GET['view'] ?? 'year') === 'month' ? 'month' : 'year';
$timelineYears     = [];
$timelineMonths    = [];
$timelineFrom      = null;
$timelinePrevUrl   = null;
$timelineNextUrl   = null;
$timelineTitle     = 'Content hours by year';

if ($timelineView === 'year') {
    $timelineYears = $stats->hoursByYear();
} else {
    $timelineFrom = parseTimelineFrom($_GET['from'] ?? '');
    if ($timelineFrom === null) {
        $years = $stats->hoursByYear();
        if ($years !== []) {
            $timelineFrom = (string) $years[array_key_last($years)]['label'] . '-01';
        } else {
            $timelineFrom = date('Y') . '-01';
        }
    }

    $timelineMonths  = $stats->hoursByMonthWindow($timelineFrom, 13);
    $timelineTitle   = formatTimelineWindowTitle($timelineFrom, 13);
    $timelinePrevUrl = timelineMonthUrl(shiftTimelineFrom($timelineFrom, -1));
    $timelineNextUrl = timelineMonthUrl(shiftTimelineFrom($timelineFrom, 1));
}

$dashboardTab = 'library';
$title        = 'Library Analytics — Media Manager';

require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/dashboard/library.php';
require dirname(__DIR__) . '/Views/layouts/footer.php';