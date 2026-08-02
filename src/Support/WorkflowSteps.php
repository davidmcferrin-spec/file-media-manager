<?php

declare(strict_types=1);

namespace MediaManager\Support;

use MediaManager\Auth\Auth;
use MediaManager\Database;

/**
 * Primary workflow spine: Setup → Ingest → Review loop → Commit.
 */
final class WorkflowSteps
{
    /**
     * @return list<array{
     *   id: string,
     *   number: int,
     *   phase: string,
     *   phase_label: string,
     *   label: string,
     *   title: string,
     *   purpose: string,
     *   done_when: string,
     *   href: string,
     *   admin_only: bool,
     *   prev: ?string,
     *   next: ?string
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'id'          => 'shows',
                'number'      => 1,
                'phase'       => 'setup',
                'phase_label' => 'Setup',
                'label'       => 'Shows',
                'title'       => 'Define shows',
                'purpose'     => 'Create each show with abbreviations, aliases, and schedule slots (or adopt from a broadcast era).',
                'done_when'   => 'Each program has a show entry with a clear abbreviation, useful aliases, and dated time slots.',
                'href'        => '/shows',
                'admin_only'  => true,
                'prev'        => null,
                'next'        => 'timeline',
            ],
            [
                'id'          => 'timeline',
                'number'      => 2,
                'phase'       => 'setup',
                'phase_label' => 'Setup',
                'label'       => 'Timeline',
                'title'       => 'Eras, import & hygiene',
                'purpose'     => 'Define broadcast eras (network on-air windows), import the schedule spreadsheet, and mark Timeline ready for Scan.',
                'done_when'   => 'Eras cover growth of the network day; open-ended current shows kept; marked ready for Scan.',
                'href'        => '/eras',
                'admin_only'  => true,
                'prev'        => 'shows',
                'next'        => 'scan',
            ],
            [
                'id'          => 'scan',
                'number'      => 3,
                'phase'       => 'ingest',
                'phase_label' => 'Ingest',
                'label'       => 'Scan',
                'title'       => 'Scan libraries',
                'purpose'     => 'Walk NAS mounts (or a path) to discover media and generate rename proposals after Timeline hygiene.',
                'done_when'   => 'Files from the target path are in the catalog queue for review.',
                'href'        => '/scan',
                'admin_only'  => true,
                'prev'        => 'timeline',
                'next'        => 'catalog',
            ],
            [
                'id'          => 'catalog',
                'number'      => 4,
                'phase'       => 'review',
                'phase_label' => 'Review',
                'label'       => 'Catalog',
                'title'       => 'Catalog and correct',
                'purpose'     => 'Match files to shows, dates, and types; edit proposals; approve or reject renames.',
                'done_when'   => 'Pending items are reviewed; approved files are ready to execute.',
                'href'        => '/queue',
                'admin_only'  => false,
                'prev'        => 'scan',
                'next'        => 'gaps',
            ],
            [
                'id'          => 'gaps',
                'number'      => 5,
                'phase'       => 'review',
                'phase_label' => 'Review',
                'label'       => 'Gaps',
                'title'       => 'Find content gaps',
                'purpose'     => 'Browse the Gaps calendar (year → day) against the Timeline — fix matches in Catalog, or accept intentional absences.',
                'done_when'   => 'Unexpected gaps are explained, accepted, or queued for follow-up.',
                'href'        => '/show-audit',
                'admin_only'  => false,
                'prev'        => 'catalog',
                'next'        => 'execute',
            ],
            [
                'id'          => 'execute',
                'number'      => 6,
                'phase'       => 'commit',
                'phase_label' => 'Commit',
                'label'       => 'Execute',
                'title'       => 'Execute on disk',
                'purpose'     => 'Apply approved moves and renames on the NAS. Nothing moves without this step.',
                'done_when'   => 'Approved backlog is executed (or intentionally held).',
                'href'        => '/execute',
                'admin_only'  => true,
                'prev'        => 'gaps',
                'next'        => null,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function byId(string $id): ?array
    {
        foreach (self::all() as $step) {
            if ($step['id'] === $id) {
                return $step;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public static function visibleForCurrentUser(): array
    {
        $admin = Auth::isAdmin();
        $out   = [];
        foreach (self::all() as $step) {
            if ($step['admin_only'] && !$admin) {
                continue;
            }
            $out[] = $step;
        }

        return $out;
    }

    /**
     * Cheap readiness counts for Home chips.
     *
     * @return array{
     *   shows: int,
     *   timeline: int,
     *   pending: int,
     *   approved: int,
     *   scans_running: int,
     *   scans_total: int,
     *   documented_gaps: int,
     *   needs_split: int
     * }
     */
    public static function readiness(): array
    {
        $pdo = Database::connection();

        $shows = (int) $pdo->query('SELECT COUNT(*) FROM shows WHERE active IS TRUE')->fetchColumn();
        $timeline = (int) $pdo->query(
            'SELECT COUNT(*) FROM program_schedule_entries WHERE active IS TRUE'
        )->fetchColumn();

        $fileRow = $pdo->query("
            SELECT
                SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN needs_split IS TRUE THEN 1 ELSE 0 END) AS needs_split
            FROM files
        ")->fetch();

        $scansRunning = (int) $pdo->query(
            "SELECT COUNT(*) FROM scan_jobs WHERE status IN ('PENDING', 'RUNNING', 'PAUSED')"
        )->fetchColumn();
        $scansTotal = (int) $pdo->query('SELECT COUNT(*) FROM scan_jobs')->fetchColumn();

        $documentedGaps = 0;
        try {
            $documentedGaps = (int) $pdo->query(
                'SELECT COUNT(*) FROM schedule_expected_gaps'
            )->fetchColumn();
        } catch (\Throwable) {
            $documentedGaps = 0;
        }

        return [
            'shows'            => $shows,
            'timeline'         => $timeline,
            'pending'          => (int) ($fileRow['pending'] ?? 0),
            'approved'         => (int) ($fileRow['approved'] ?? 0),
            'scans_running'    => $scansRunning,
            'scans_total'      => $scansTotal,
            'documented_gaps'  => $documentedGaps,
            'needs_split'      => (int) ($fileRow['needs_split'] ?? 0),
        ];
    }

    /**
     * @param array<string, int> $readiness
     * @return 'ready'|'attention'|'blocked'|'idle'
     */
    public static function stepStatus(string $stepId, array $readiness): string
    {
        return match ($stepId) {
            'shows' => $readiness['shows'] > 0 ? 'ready' : 'blocked',
            'timeline' => $readiness['timeline'] > 0
                ? 'ready'
                : ($readiness['shows'] > 0 ? 'attention' : 'blocked'),
            'scan' => $readiness['scans_running'] > 0
                ? 'attention'
                : ($readiness['scans_total'] > 0 ? 'ready' : ($readiness['timeline'] > 0 || $readiness['shows'] > 0 ? 'attention' : 'blocked')),
            'catalog' => $readiness['pending'] > 0 ? 'attention' : ($readiness['scans_total'] > 0 ? 'ready' : 'idle'),
            // Gaps needs timeline; nudge when catalog still has pending work to reconcile.
            'gaps' => $readiness['timeline'] <= 0
                ? 'idle'
                : (($readiness['pending'] > 0 || $readiness['documented_gaps'] > 0) ? 'attention' : 'ready'),
            'execute' => $readiness['approved'] > 0 ? 'attention' : 'idle',
            default => 'idle',
        };
    }
}
