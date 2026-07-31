<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\FileRepository;
use MediaManager\Repositories\LegacyRenameMapRepository;
use MediaManager\Repositories\ScanJobRepository;

final class LegacyMapApplyService
{
    public function __construct(
        private readonly FileRepository $files = new FileRepository(),
        private readonly LegacyRenameMapRepository $map = new LegacyRenameMapRepository(),
        private readonly ScanJobRepository $scanJobs = new ScanJobRepository(),
        private readonly ProposalReconciler $reconciler = new ProposalReconciler(),
    ) {
    }

    /** @return array{matched: int, template: int, conflict: int, unchanged: int, source_skipped: int} */
    public function applyToScanJob(int $scanJobId): array
    {
        $job = $this->scanJobs->findById($scanJobId);
        if ($job === null) {
            throw new \InvalidArgumentException('Scan job not found.');
        }

        $sourceCode = $this->resolveSourceCode($job);
        if ($sourceCode === null) {
            throw new \RuntimeException('Source has no source_code (NY/CHI). Set it in Settings → Sources.');
        }

        $stats = [
            'matched'        => 0,
            'template'       => 0,
            'conflict'       => 0,
            'unchanged'      => 0,
            'source_skipped' => 0,
        ];

        $fileList = $this->files->byScanJob($scanJobId, 50000);
        foreach ($fileList as $file) {
            if (!in_array((string) ($file['status'] ?? ''), ['PENDING', 'FLAGGED'], true)) {
                continue;
            }

            $mapRow = $this->map->findByFullPath($sourceCode, (string) $file['original_path']);
            if ($mapRow === null) {
                $stats['unchanged']++;
                $this->ensureClassifierSnapshot((int) $file['id'], $file);
                continue;
            }

            $file = $this->ensureClassifierSnapshotData($file);
            $result = $this->reconciler->reconcile($file, $mapRow);
            $this->files->updateProposalReconciliation((int) $file['id'], $result);

            $agreement = (string) ($result['proposal_agreement'] ?? '');
            if ($agreement === 'template') {
                $stats['template']++;
            } elseif ($agreement === 'conflict') {
                $stats['conflict']++;
            } else {
                $stats['matched']++;
            }
        }

        return $stats;
    }

    /** @param array<string, mixed> $job */
    private function resolveSourceCode(array $job): ?string
    {
        $code = trim((string) ($job['source_code'] ?? ''));
        if ($code !== '') {
            return strtoupper($code);
        }

        $mount = strtoupper((string) ($job['mount_path'] ?? ''));
        if (str_contains($mount, 'SNSEVO-CHL')) {
            return 'CHI';
        }
        if (str_contains($mount, 'SNSEVO-NYL')) {
            return 'NY';
        }

        return null;
    }

    /** @param array<string, mixed> $file */
    private function ensureClassifierSnapshot(int $fileId, array $file): void
    {
        if (!empty($file['classifier_confidence'])) {
            return;
        }
        $this->files->ensureClassifierSnapshot($fileId);
    }

    /** @param array<string, mixed> $file @return array<string, mixed> */
    private function ensureClassifierSnapshotData(array $file): array
    {
        if (empty($file['classifier_confidence'])) {
            $file['classifier_confidence'] = $file['confidence'] ?? 'UNEVALUATED';
            $file['classifier_proposed_dir'] = $file['proposed_dir'] ?? null;
            $file['classifier_proposed_filename'] = $file['proposed_filename'] ?? null;
        }

        return $file;
    }
}
