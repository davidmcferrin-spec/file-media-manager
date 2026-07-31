<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\FileRepository;
use MediaManager\Repositories\ScanJobRepository;
use MediaManager\Repositories\SplitQueueRepository;

/**
 * Re-run classifier on eligible files in an existing scan job (in place).
 */
final class ReclassifyService
{
    public function __construct(
        private readonly FileRepository $files = new FileRepository(),
        private readonly ScanJobRepository $scanJobs = new ScanJobRepository(),
        private readonly SplitQueueRepository $splitQueue = new SplitQueueRepository(),
        private readonly Classifier $classifier = new Classifier(),
        private readonly ContinuityCheckService $continuity = new ContinuityCheckService(),
    ) {
    }

    /**
     * @return array{reclassified: int, skipped: int, failed: int, protected: int}
     */
    public function reclassifyScanJob(int $scanJobId): array
    {
        $job = $this->scanJobs->findById($scanJobId);
        if ($job === null) {
            throw new \InvalidArgumentException('Scan job not found.');
        }

        $status = (string) ($job['status'] ?? '');
        if (!in_array($status, ['COMPLETED', 'CANCELLED', 'PAUSED', 'FAILED'], true)) {
            throw new \RuntimeException('Scan must be finished (or paused/failed) before reclassify.');
        }

        $mount = (string) ($job['mount_path'] ?? '');
        $stats = [
            'reclassified' => 0,
            'skipped'      => 0,
            'failed'       => 0,
            'protected'    => $this->files->countProtectedByScanJob($scanJobId),
        ];

        $fileList = $this->files->byScanJob($scanJobId, 50000);
        $this->continuity->warmEngine();

        $concurrency = ContinuityCheckService::concurrency();
        /** @var list<array<string, mixed>> $pending */
        $pending = [];

        $flush = function () use (&$pending, &$stats): void {
            if ($pending === []) {
                return;
            }
            $batch = [];
            foreach ($pending as $item) {
                $batch[] = [
                    'result'            => $item['result'],
                    'original_path'     => $item['path'],
                    'original_filename' => $item['filename'],
                    'file_id'           => $item['id'],
                ];
            }
            $refined = $this->continuity->refineBatch($batch);
            foreach ($pending as $i => $item) {
                $result = $refined[$i] ?? $item['result'];
                $id = (int) $item['id'];
                $wasSplit = !empty($item['was_split']);
                $ok = $this->files->updateClassification($id, [
                    'proposed_dir'      => $result->proposedDir,
                    'proposed_filename' => $result->proposedFilename,
                    'show_id'           => $result->showId,
                    'media_type_id'     => $result->mediaTypeId,
                    'file_date'         => $result->fileDate,
                    'file_time'         => $result->fileTime,
                    'confidence'        => $result->confidence,
                    'classifier_notes'  => $result->classifierNotesJson(),
                    'needs_split'       => $result->needsSplit,
                    'split_notes'       => $result->splitNotes,
                ]);

                if (!$ok) {
                    $stats['skipped']++;
                    continue;
                }

                if ($wasSplit && !$result->needsSplit) {
                    $this->splitQueue->deleteActiveForFile($id);
                }

                $stats['reclassified']++;
            }
            $pending = [];
        };

        foreach ($fileList as $file) {
            $fileStatus = (string) ($file['status'] ?? '');
            if (!in_array($fileStatus, ['PENDING', 'FLAGGED', 'REJECTED'], true)) {
                $stats['skipped']++;
                continue;
            }

            $id = (int) $file['id'];
            $path = (string) $file['original_path'];

            $sidecarPaths = [];
            foreach (FileRepository::parseSidecars($file['classifier_notes'] ?? null) as $sidecar) {
                $sidecarPath = (string) ($sidecar['original_path'] ?? '');
                if ($sidecarPath !== '') {
                    $sidecarPaths[] = $sidecarPath;
                }
            }

            $probe = null;
            if (!empty($file['duration_seconds']) || !empty($file['codec_video'])) {
                $probe = [
                    'duration'      => $file['duration_seconds'] ?? null,
                    'creation_time' => null,
                ];
            }

            try {
                $result = $this->classifier->classify($path, $mount, $probe, $sidecarPaths);
            } catch (\Throwable) {
                $stats['failed']++;
                continue;
            }

            $pending[] = [
                'id'        => $id,
                'path'      => $path,
                'filename'  => (string) ($file['original_filename'] ?? basename($path)),
                'result'    => $result,
                'was_split' => !empty($file['needs_split']),
            ];
            if (count($pending) >= $concurrency) {
                $flush();
            }
        }

        $flush();

        (new GlueGroupService($this->files))->applyForScanJob($scanJobId);

        return $stats;
    }
}
