<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\FileRepository;
use MediaManager\Repositories\ScanJobRepository;
use MediaManager\Repositories\SplitQueueRepository;
use RuntimeException;

final class ScanService
{
    private bool $rescanMode = false;

    /** @param ?\Closure(string, array<string, mixed>): void $onProgress */
    public function __construct(
        private readonly ScanJobRepository $scanJobs = new ScanJobRepository(),
        private readonly FileRepository $files = new FileRepository(),
        private readonly Classifier $classifier = new Classifier(),
        private readonly FFprobeService $ffprobe = new FFprobeService(),
        private readonly AuditRepository $audit = new AuditRepository(),
        private readonly SplitQueueRepository $splitQueue = new SplitQueueRepository(),
        private readonly ContinuityCheckService $continuity = new ContinuityCheckService(),
        private readonly ?\Closure $onProgress = null,
    ) {
    }

    /**
     * Claim and run the oldest pending/paused/failed job. Returns job ID or null when idle.
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

    public function runJob(int $jobId, bool $rescan = false): void
    {
        $this->rescanMode = $rescan;

        $job = $this->scanJobs->findById($jobId);
        if ($job === null) {
            throw new RuntimeException("Scan job {$jobId} not found.");
        }

        $status = (string) ($job['status'] ?? '');
        if (!$rescan && ($status === 'COMPLETED' || $status === 'CANCELLED')) {
            return;
        }

        if ($status === 'PENDING' || $status === 'PAUSED' || $status === 'FAILED') {
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
            $this->progress('warning', ['message' => 'FFprobe unavailable; skipping metadata extraction.']);
            $extract = false;
        }

        $this->scanJobs->resetProgress($jobId);

        $skipped      = 0;
        $queued       = 0;
        $reclassified = 0;
        $duplicates   = 0;

        $this->scanJobs->setWorkerPid($jobId, getmypid());

        $this->progress('start', [
            'job_id'      => $jobId,
            'source_name' => (string) ($job['source_name'] ?? ''),
            'scan_root'   => $scanRoot,
            'extract'     => $extract,
            'rescan'      => $this->rescanMode,
        ]);

        try {
            $this->progress('collecting', ['scan_root' => $scanRoot]);

            $this->abortIfCancelled($jobId);

            $mediaFiles = $devList !== null && $devList !== ''
                ? $this->collectFromDevList((string) $devList, $mountPath, $subpath, $ignore, $jobId)
                : $this->collectFromFilesystem($scanRoot, $mountPath, $ignore, $jobId);

            $this->abortIfCancelled($jobId);

            $totalFiles = count($mediaFiles);
            $this->scanJobs->setTotalFiles($jobId, $totalFiles);
            $this->progress('discovered', ['total' => $totalFiles]);

            $warm = $this->continuity->warmEngine();
            if ($warm !== null) {
                $this->progress('continuity_warm', $warm);
                if (!$warm['ok']) {
                    error_log(
                        '[scan] Job ' . $jobId . ': continuity pack warm failed: '
                        . ($warm['transport_error'] !== '' ? $warm['transport_error'] : 'unknown')
                    );
                }
            }

            $processed = 0;
            foreach ($mediaFiles as $entry) {
                $this->abortIfCancelled($jobId);

                $outcome = $this->processFile($job, $entry, $extract);
                if ($outcome === 'queued') {
                    $queued++;
                } elseif ($outcome === 'reclassified') {
                    $reclassified++;
                } elseif ($outcome === 'duplicate') {
                    $duplicates++;
                } elseif ($outcome === 'skipped') {
                    $skipped++;
                }
                $processed++;
                $this->scanJobs->incrementProcessed($jobId);
                $this->progress('file', [
                    'processed' => $processed,
                    'total'     => $totalFiles,
                    'path'      => $entry['path'],
                    'outcome'   => $outcome,
                ]);
            }

            $this->scanJobs->markCompleted($jobId);

            $this->audit->record(
                (int) $job['created_by'],
                (string) ($job['created_by_email'] ?? ''),
                '127.0.0.1',
                $this->rescanMode ? 'SCAN_RESCAN_COMPLETED' : 'SCAN_COMPLETED',
                'scan_job',
                $jobId,
                null,
                null,
                [
                    'total_files'  => count($mediaFiles),
                    'queued'       => $queued,
                    'reclassified' => $reclassified,
                    'duplicates'   => $duplicates,
                    'skipped'      => $skipped,
                    'subpath'      => $subpath,
                    'rescan'       => $this->rescanMode,
                ]
            );

            $this->progress('complete', [
                'job_id'       => $jobId,
                'total'        => $totalFiles,
                'queued'       => $queued,
                'reclassified' => $reclassified,
                'duplicates'   => $duplicates,
                'skipped'      => $skipped,
            ]);

            error_log(sprintf(
                '[scan] Job %d completed%s: %d discovered, %d queued, %d reclassified, %d duplicate, %d skipped.',
                $jobId,
                $this->rescanMode ? ' (rescan)' : '',
                $totalFiles,
                $queued,
                $reclassified,
                $duplicates,
                $skipped
            ));
        } catch (ScanCancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->scanJobs->markFailed($jobId, $e->getMessage());
            $this->progress('failed', ['job_id' => $jobId, 'message' => $e->getMessage()]);
            error_log('[scan] Job ' . $jobId . ' failed: ' . $e->getMessage());
            throw $e;
        } finally {
            $this->scanJobs->clearWorkerPid($jobId);
        }
    }

    /** @param array<string, mixed> $data */
    private function progress(string $event, array $data = []): void
    {
        if ($this->onProgress === null) {
            return;
        }

        ($this->onProgress)($event, $data);
    }

    private function abortIfCancelled(int $jobId): void
    {
        if (!$this->scanJobs->isCancelRequested($jobId)) {
            return;
        }

        $job = $this->scanJobs->findById($jobId);
        $outcome = $this->scanJobs->markStopped($jobId);

        if ($job !== null) {
            $this->audit->record(
                (int) $job['created_by'],
                (string) ($job['created_by_email'] ?? ''),
                '127.0.0.1',
                $outcome === 'PAUSED' ? 'SCAN_PAUSED' : 'SCAN_CANCELLED',
                'scan_job',
                $jobId,
                null,
                null,
                [
                    'processed_files' => (int) ($job['processed_files'] ?? 0),
                    'total_files'     => (int) ($job['total_files'] ?? 0),
                ]
            );
        }

        if ($outcome === 'PAUSED') {
            $this->progress('paused', ['job_id' => $jobId]);
            error_log('[scan] Job ' . $jobId . ' paused.');
        } else {
            $this->progress('cancelled', ['job_id' => $jobId]);
            error_log('[scan] Job ' . $jobId . ' cancelled.');
        }

        throw new ScanCancelledException("Scan job {$jobId} was stopped.");
    }

    /**
     * @return list<array{path: string, sidecars: list<string>}>
     */
    private function collectFromFilesystem(
        string $scanRoot,
        string $sourceMount,
        ScanIgnore $ignore,
        int $jobId,
    ): array {
        $scanRoot = str_replace('\\', '/', rtrim($scanRoot, '/'));
        error_log('[scan] Job ' . $jobId . ': opening scan root ' . $scanRoot);

        $rootHandle = @opendir($scanRoot);
        if ($rootHandle === false) {
            throw new RuntimeException("Scan root not found or not mounted: {$scanRoot}");
        }
        closedir($rootHandle);

        error_log('[scan] Job ' . $jobId . ': walking directory tree');

        /** @var array<string, list<string>> $dirSidecars */
        $dirSidecars = [];
        /** @var list<string> $mediaPaths */
        $mediaPaths = [];

        /** @var list<string> $stack */
        $stack = [$scanRoot];
        $dirsScanned = 0;
        $entriesSeen = 0;
        $lastProgress = microtime(true);

        while ($stack !== []) {
            $this->abortIfCancelled($jobId);

            $dir = array_pop($stack);
            if ($ignore->shouldIgnoreDirectory($dir, $sourceMount)) {
                continue;
            }

            $handle = @opendir($dir);
            if ($handle === false) {
                error_log('[scan] Cannot read directory: ' . $dir);
                continue;
            }

            $dirsScanned++;

            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = str_replace('\\', '/', $dir . '/' . $entry);
                $entriesSeen++;

                $now = microtime(true);
                if ($entriesSeen % 250 === 0 || ($now - $lastProgress) >= 1.0) {
                    $this->progress('collecting_progress', [
                        'dirs'        => $dirsScanned,
                        'entries'     => $entriesSeen,
                        'media'       => count($mediaPaths),
                        'current_dir' => $dir,
                    ]);
                    $lastProgress = $now;

                    if ($entriesSeen % 1000 === 0) {
                        $this->abortIfCancelled($jobId);
                    }
                }

                $type = @filetype($path);
                if ($type === 'dir') {
                    if (!$ignore->shouldIgnoreDirectory($path, $sourceMount)) {
                        $stack[] = $path;
                    }
                    continue;
                }

                if ($type !== 'file') {
                    continue;
                }

                if ($ignore->shouldIgnore($path, $sourceMount)) {
                    continue;
                }

                if (MediaExtensions::isSidecar($path)) {
                    $stem = pathinfo($path, PATHINFO_FILENAME);
                    $dirSidecars[$dir . '|' . strtolower($stem)][] = $path;
                    continue;
                }

                if (MediaExtensions::isMedia($path)) {
                    $mediaPaths[] = $path;
                }
            }

            closedir($handle);
        }

        error_log(sprintf(
            '[scan] Job %d: discovery complete — %d dirs, %d entries, %d media files.',
            $jobId,
            $dirsScanned,
            $entriesSeen,
            count($mediaPaths)
        ));

        sort($mediaPaths);

        $entries = [];
        foreach ($mediaPaths as $path) {
            $fileDir = dirname($path);
            $stem    = pathinfo($path, PATHINFO_FILENAME);
            $key     = $fileDir . '|' . strtolower($stem);
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
    private function collectFromDevList(
        string $listPath,
        string $mountPath,
        string $subpath,
        ScanIgnore $ignore,
        int $jobId,
    ): array {
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

        foreach ($lines as $lineNum => $line) {
            if ($lineNum > 0 && $lineNum % 5000 === 0) {
                $this->abortIfCancelled($jobId);
                $this->progress('collecting_progress', [
                    'dirs'        => 0,
                    'entries'     => $lineNum,
                    'media'       => count($mediaPaths),
                    'current_dir' => 'dev file list',
                ]);
            }

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
     * @return 'queued'|'reclassified'|'skipped'|'duplicate'
     */
    private function processFile(array $job, array $entry, bool $extractMetadata): string
    {
        $this->abortIfCancelled((int) $job['id']);

        $path = $entry['path'];

        if (!is_file($path) || !is_readable($path)) {
            error_log('[scan] Skipping unavailable file: ' . $path);
            return 'skipped';
        }

        $existing = $this->files->findByOriginalPath($path);
        if ($existing !== null) {
            if ($this->rescanMode
                && (int) ($existing['scan_job_id'] ?? 0) === (int) $job['id']
                && in_array((string) ($existing['status'] ?? ''), ['PENDING', 'FLAGGED', 'REJECTED'], true)
            ) {
                return $this->reclassifyExisting($job, $existing, $entry, $extractMetadata);
            }

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
            $result = $this->continuity->refine($result, $path, basename($path));

            $meta = is_array($probe) ? $probe : [];

            $fileId = $this->files->insert([
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
                'classifier_confidence'        => $result->confidence,
                'classifier_proposed_dir'      => $result->proposedDir,
                'classifier_proposed_filename' => $result->proposedFilename,
                'proposed_source'              => 'classifier',
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
            $this->continuity->attachFileId($path, $fileId);

            return 'queued';
        } catch (\Throwable $e) {
            error_log('[scan] Skipping ' . $path . ': ' . $e->getMessage());
            return 'skipped';
        }
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $file
     * @param array{path: string, sidecars: list<string>} $entry
     * @return 'reclassified'|'skipped'
     */
    private function reclassifyExisting(array $job, array $file, array $entry, bool $extractMetadata): string
    {
        $id = (int) $file['id'];
        $sidecarPaths = $entry['sidecars'];
        if ($sidecarPaths === []) {
            foreach (FileRepository::parseSidecars($file['classifier_notes'] ?? null) as $sidecar) {
                $sidecarPath = (string) ($sidecar['original_path'] ?? '');
                if ($sidecarPath !== '') {
                    $sidecarPaths[] = $sidecarPath;
                }
            }
        }

        try {
            $probe = null;
            if ($extractMetadata) {
                $probe = $this->ffprobe->probe((string) $file['original_path']);
            } elseif (!empty($file['duration_seconds']) || !empty($file['codec_video'])) {
                $probe = [
                    'duration'      => $file['duration_seconds'] ?? null,
                    'creation_time' => null,
                ];
            }

            $result = $this->classifier->classify(
                (string) $file['original_path'],
                (string) $job['mount_path'],
                $probe,
                $sidecarPaths
            );
            $result = $this->continuity->refine(
                $result,
                (string) $file['original_path'],
                (string) ($file['original_filename'] ?? basename((string) $file['original_path'])),
                $id
            );

            $wasSplit = !empty($file['needs_split']);
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
                return 'skipped';
            }

            if ($wasSplit && !$result->needsSplit) {
                $this->splitQueue->deleteActiveForFile($id);
            }

            return 'reclassified';
        } catch (\Throwable $e) {
            error_log('[scan] Rescan reclassify failed for file #' . $id . ': ' . $e->getMessage());
            return 'skipped';
        }
    }
}
