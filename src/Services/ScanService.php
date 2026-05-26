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

    public function runJob(int $jobId): void
    {
        $job = $this->scanJobs->findById($jobId);
        if ($job === null) {
            throw new RuntimeException("Scan job {$jobId} not found.");
        }

        if (($job['status'] ?? '') === 'COMPLETED') {
            return;
        }

        $mountPath = rtrim((string) $job['mount_path'], '/');
        $subpath   = trim((string) ($job['subpath'] ?? ''), '/');
        $scanRoot  = $subpath !== '' ? $mountPath . '/' . $subpath : $mountPath;
        $extract   = (bool) ($job['extract_metadata'] ?? true);
        $devList   = $job['dev_file_list'] ?? null;
        $ignore    = ScanIgnore::fromRepository();

        try {
            $mediaFiles = $devList !== null && $devList !== ''
                ? $this->collectFromDevList((string) $devList, $mountPath, $subpath, $ignore)
                : $this->collectFromFilesystem($scanRoot, $mountPath, $ignore);

            $this->scanJobs->markRunning($jobId, count($mediaFiles));

            foreach ($mediaFiles as $entry) {
                $this->processFile($job, $entry, $extract);
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
                ['total_files' => count($mediaFiles), 'subpath' => $subpath]
            );
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

    /** @param array{path: string, sidecars: list<string>} $entry */
    private function processFile(array $job, array $entry, bool $extractMetadata): void
    {
        $path = $entry['path'];

        if ($this->files->existsByOriginalPath($path)) {
            return;
        }

        $probe = null;
        $filesize = @filesize($path);
        if ($extractMetadata && is_readable($path)) {
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
    }
}
