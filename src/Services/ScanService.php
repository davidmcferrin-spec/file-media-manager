<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\FileRepository;
use MediaManager\Repositories\ScanJobRepository;
use RuntimeException;

final class ScanService
{
    public function __construct(
        private readonly ScanJobRepository $scanJobs = new ScanJobRepository(),
        private readonly FileRepository $files = new FileRepository(),
        private readonly Classifier $classifier = new Classifier(),
        private readonly FFprobeService $ffprobe = new FFprobeService(),
        private readonly AuditRepository $audit = new AuditRepository(),
    ) {
    }

    /**
     * Claim and run the oldest pending/failed job. Returns job ID or null when idle.
     */
    public function runNextPending(): ?int
    {
        $jobId = $this->scanJobs->claimNextPending();
        if ($jobId === null) {
            return null;
        }

        $this->executeJob($jobId);

        return $jobId;
    }

    public function runJob(int $jobId): void
    {
        $job = $this->scanJobs->findById($jobId);
        if ($job === null) {
            throw new RuntimeException("Scan job {$jobId} not found.");
        }

        $status = (string) ($job['status'] ?? '');
        if ($status === 'COMPLETED') {
            return;
        }

        if ($status === 'PENDING' || $status === 'FAILED') {
            if (!$this->scanJobs->tryClaim($jobId)) {
                $job = $this->scanJobs->findById($jobId);
                $status = (string) ($job['status'] ?? '');
                if ($status === 'COMPLETED') {
                    return;
                }
                if ($status !== 'RUNNING') {
                    throw new RuntimeException(
                        "Scan job {$jobId} could not be claimed (status: {$status})."
                    );
                }
            }
        } elseif ($status !== 'RUNNING') {
            throw new RuntimeException("Scan job {$jobId} is not runnable (status: {$status}).");
        }

        $this->executeJob($jobId);
    }

    private function executeJob(int $jobId): void
    {
        $job = $this->scanJobs->findById($jobId);
        if ($job === null) {
            throw new RuntimeException("Scan job {$jobId} not found.");
        }

        $mountPath = rtrim((string) $job['mount_path'], '/');
        $subpath   = trim((string) ($job['subpath'] ?? ''), '/');
        $scanRoot  = $subpath !== '' ? $mountPath . '/' . $subpath : $mountPath;
        $extract   = (bool) ($job['extract_metadata'] ?? true);
        $devList   = $job['dev_file_list'] ?? null;
        $ignore    = ScanIgnore::fromRepository();

        if ($extract && !$this->ffprobe->isAvailable()) {
            error_log('[scan] Job ' . $jobId . ': FFprobe unavailable; skipping metadata extraction.');
            $extract = false;
        }

        $this->scanJobs->resetProgress($jobId);

        $skipped = 0;
        $queued  = 0;

        try {
            $mediaFiles = $devList !== null && $devList !== ''
                ? $this->collectFromDevList((string) $devList, $mountPath, $subpath, $ignore)
                : $this->collectFromFilesystem($scanRoot, $mountPath, $ignore);

            $this->scanJobs->setTotalFiles($jobId, count($mediaFiles));

            foreach ($mediaFiles as $entry) {
                $outcome = $this->processFile($job, $entry, $extract);
                if ($outcome === 'queued') {
                    $queued++;
                } elseif ($outcome === 'skipped') {
                    $skipped++;
                }
                $this->scanJobs->incrementProcessed($jobId);
            }

            $this->scanJobs->markCompleted($jobId);

            $this->audit->record(
                (int) $job['created_by'],
                (string) ($job['created_by_email'] ?? ''),
                '127.0.0.1',
                'SCAN_COMPLETED',
                'scan_job',
                $jobId,
                null,
                null,
                [
                    'total_files' => count($mediaFiles),
                    'queued'      => $queued,
                    'skipped'     => $skipped,
                    'subpath'     => $subpath,
                ]
            );

            error_log(sprintf(
                '[scan] Job %d completed: %d discovered, %d queued, %d skipped.',
                $jobId,
                count($mediaFiles),
                $queued,
                $skipped
            ));
        } catch (\Throwable $e) {
            $this->scanJobs->markFailed($jobId, $e->getMessage());
            error_log('[scan] Job ' . $jobId . ' failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return list<array{path: string, sidecars: list<string>}>
     */
    private function collectFromFilesystem(string $scanRoot, string $sourceMount, ScanIgnore $ignore): array
    {
        if (!is_dir($scanRoot)) {
            throw new RuntimeException("Scan root not found or not mounted: {$scanRoot}");
        }

        /** @var array<string, list<string>> $dirSidecars */
        $dirSidecars = [];
        /** @var list<string> $mediaPaths */
        $mediaPaths = [];

        $directory = new \RecursiveDirectoryIterator($scanRoot, \FilesystemIterator::SKIP_DOTS);
        $filtered  = new \RecursiveCallbackFilterIterator(
            $directory,
            function (\SplFileInfo $current) use ($ignore, $sourceMount): bool {
                $path = str_replace('\\', '/', $current->getPathname());
                if ($current->isDir() && $ignore->shouldIgnoreDirectory($path, $sourceMount)) {
                    return false;
                }

                return true;
            }
        );
        $iterator = new \RecursiveIteratorIterator($filtered);

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            $path = str_replace('\\', '/', $fileInfo->getPathname());
            if ($ignore->shouldIgnore($path, $sourceMount)) {
                continue;
            }

            if (MediaExtensions::isSidecar($path)) {
                $dir = str_replace('\\', '/', $fileInfo->getPath());
                $stem = pathinfo($path, PATHINFO_FILENAME);
                $dirSidecars[$dir . '|' . strtolower($stem)][] = $path;
                continue;
            }

            if (MediaExtensions::isMedia($path)) {
                $mediaPaths[] = $path;
            }
        }

        sort($mediaPaths);

        $entries = [];
        foreach ($mediaPaths as $path) {
            $dir  = dirname($path);
            $stem = pathinfo($path, PATHINFO_FILENAME);
            $key  = $dir . '|' . strtolower($stem);
            $entries[] = [
                'path'     => $path,
                'sidecars' => $dirSidecars[$key] ?? [],
            ];
        }

        return $entries;
    }

    /**
     * Dev mode: read paths from example_file_trees listing.
     *
     * @return list<array{path: string, sidecars: list<string>}>
     */
    private function collectFromDevList(string $listPath, string $mountPath, string $subpath, ScanIgnore $ignore): array
    {
        if (!is_readable($listPath)) {
            throw new RuntimeException("Dev file list not readable: {$listPath}");
        }

        $lines = file($listPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException("Cannot read dev file list: {$listPath}");
        }

        /** @var array<string, list<string>> $dirSidecars */
        $dirSidecars = [];
        /** @var list<string> $mediaPaths */
        $mediaPaths = [];

        $prefix = rtrim($mountPath, '/');
        if ($subpath !== '') {
            $prefix .= '/' . trim($subpath, '/');
        }

        foreach ($lines as $line) {
            $path = trim($line);
            if ($path === '' || !str_starts_with($path, $prefix)) {
                continue;
            }
            if ($ignore->shouldIgnore($path, $mountPath)) {
                continue;
            }

            if (MediaExtensions::isSidecar($path)) {
                $dir  = dirname($path);
                $stem = pathinfo($path, PATHINFO_FILENAME);
                $dirSidecars[$dir . '|' . strtolower($stem)][] = $path;
                continue;
            }

            if (MediaExtensions::isMedia($path)) {
                $mediaPaths[] = $path;
            }
        }

        sort($mediaPaths);

        $entries = [];
        foreach ($mediaPaths as $path) {
            $dir  = dirname($path);
            $stem = pathinfo($path, PATHINFO_FILENAME);
            $key  = $dir . '|' . strtolower($stem);
            $entries[] = [
                'path'     => $path,
                'sidecars' => $dirSidecars[$key] ?? [],
            ];
        }

        return $entries;
    }

    /**
     * @param array{path: string, sidecars: list<string>} $entry
     * @return 'queued'|'skipped'|'duplicate'
     */
    private function processFile(array $job, array $entry, bool $extractMetadata): string
    {
        $path = $entry['path'];

        if (!is_file($path) || !is_readable($path)) {
            error_log('[scan] Skipping unavailable file: ' . $path);
            return 'skipped';
        }

        if ($this->files->existsByOriginalPath($path)) {
            return 'duplicate';
        }

        try {
            $probe = null;
            $filesize = @filesize($path);
            if ($extractMetadata) {
                $probe = $this->ffprobe->probe($path);
            }

            $result = $this->classifier->classify(
                $path,
                (string) $job['mount_path'],
                $probe,
                $entry['sidecars']
            );

            $meta = is_array($probe) ? $probe : [];

            $this->files->insert([
                'scan_job_id'        => (int) $job['id'],
                'source_id'          => (int) $job['source_id'],
                'original_path'      => $path,
                'original_dir'       => dirname($path),
                'original_filename'  => basename($path),
                'proposed_dir'       => $result->proposedDir,
                'proposed_filename'  => $result->proposedFilename,
                'show_id'            => $result->showId,
                'media_type_id'      => $result->mediaTypeId,
                'file_date'          => $result->fileDate,
                'file_time'          => $result->fileTime,
                'confidence'         => $result->confidence,
                'classifier_notes'   => $result->classifierNotesJson(),
                'status'             => 'PENDING',
                'duration_seconds'   => $meta['duration'] ?? null,
                'filesize_bytes'     => $meta['filesize_bytes'] ?? ($filesize !== false ? $filesize : null),
                'container'          => $meta['container'] ?? MediaExtensions::extension($path),
                'codec_video'        => $meta['codec_video'] ?? null,
                'codec_audio'        => $meta['codec_audio'] ?? null,
                'resolution'         => $meta['resolution'] ?? null,
                'framerate'          => $meta['framerate'] ?? null,
                'metadata_extracted' => $probe !== null,
                'needs_split'        => $result->needsSplit,
                'split_notes'        => $result->splitNotes,
            ]);

            return 'queued';
        } catch (\Throwable $e) {
            error_log('[scan] Skipping ' . $path . ': ' . $e->getMessage());
            return 'skipped';
        }
    }
}
