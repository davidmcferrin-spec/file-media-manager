<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\LegacyRenameMapRepository;
use MediaManager\Repositories\ScanJobRepository;
use MediaManager\Services\LegacyMapApplyService;
use MediaManager\Services\LegacyRenameMapImporter;

Auth::requireAdmin();

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$mapRepo   = new LegacyRenameMapRepository();
$scanJobs  = new ScanJobRepository();
$audit     = new AuditRepository();

/** @var string $projectRoot */
$projectRoot = dirname(__DIR__, 2);

function legacy_map_audit(AuditRepository $audit, string $action, array $details = []): void
{
    $user = Auth::user();
    $audit->record(
        Auth::id(),
        $user['email'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
        $action,
        'legacy_rename_map',
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
        header('Location: /legacy-map');
        exit;
    }

    if ($uri === '/legacy-map/import') {
        $replace = isset($_POST['replace_existing']);
        $path    = $projectRoot . '/example_file_trees/NN_Legacy_Rename_Map.xlsx';

        if (!empty($_FILES['map_file']['tmp_name']) && is_uploaded_file($_FILES['map_file']['tmp_name'])) {
            $path = $_FILES['map_file']['tmp_name'];
        }

        try {
            $result = (new LegacyRenameMapImporter())->importFile($path, $replace);
            legacy_map_audit($audit, 'LEGACY_MAP_IMPORTED', $result);
            $msg = sprintf('Imported %d legacy rename map row(s).', $result['imported']);
            if ($result['skipped'] !== []) {
                $msg .= ' ' . count($result['skipped']) . ' row(s) skipped.';
            }
            Session::flash('success', $msg);
            Session::flash('legacy_map_import_log', [
                'skipped'  => $result['skipped'],
                'warnings' => $result['warnings'],
            ]);
        } catch (\Throwable $e) {
            Session::flash('error', 'Import failed: ' . $e->getMessage());
        }

        header('Location: /legacy-map');
        exit;
    }

    if ($uri === '/legacy-map/apply' || $uri === '/scan/apply-map') {
        $jobId = (int) ($_POST['scan_job_id'] ?? $_POST['id'] ?? 0);
        if ($jobId <= 0) {
            Session::flash('error', 'Select a scan job.');
            header('Location: ' . ($uri === '/scan/apply-map' ? '/scan' : '/legacy-map'));
            exit;
        }

        try {
            $stats = (new LegacyMapApplyService())->applyToScanJob($jobId);
            legacy_map_audit($audit, 'LEGACY_MAP_APPLIED', ['scan_job_id' => $jobId, 'stats' => $stats]);
            Session::flash(
                'success',
                sprintf(
                    'Applied legacy map to scan #%d: %d matched, %d template, %d conflict(s), %d unchanged.',
                    $jobId,
                    $stats['matched'],
                    $stats['template'],
                    $stats['conflict'],
                    $stats['unchanged']
                )
            );
        } catch (\Throwable $e) {
            Session::flash('error', 'Apply failed: ' . $e->getMessage());
        }

        header('Location: ' . ($uri === '/scan/apply-map' ? '/scan/' . $jobId : '/legacy-map'));
        exit;
    }

    http_response_code(404);
    exit;
}

$entries   = $mapRepo->list(100, 0);
$total     = $mapRepo->count();
$recentJobs = $scanJobs->recent(20);
/** @var array{skipped?: list<string>, warnings?: list<string>}|null $importLog */
$importLog = Session::getFlash('legacy_map_import_log');
$showsTab  = 'legacy-map';

$title = 'Legacy Rename Map — Media Manager';
require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/legacy_map/index.php';
require dirname(__DIR__) . '/Views/layouts/footer.php';
