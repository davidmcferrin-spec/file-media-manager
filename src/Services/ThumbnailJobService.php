<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\ThumbnailJobRepository;
use MediaManager\Support\WorkerMode;
use PDOException;
use Throwable;

/**
 * Background FFmpeg thumbnail generation (keeps Apache free of NAS decode).
 */
final class ThumbnailJobService
{
    private string $projectRoot;

    public function __construct(
        private readonly ThumbnailJobRepository $jobs = new ThumbnailJobRepository(),
        private readonly ThumbnailService $thumbs = new ThumbnailService(),
        private readonly MediaCacheService $cache = new MediaCacheService(),
        ?string $projectRoot = null,
    ) {
        $this->projectRoot = $projectRoot ?? dirname(__DIR__, 2);
    }

    public static function logPathForJob(int $jobId, ?string $projectRoot = null): string
    {
        $root = $projectRoot ?? dirname(__DIR__, 2);

        return $root . '/storage/logs/thumbnail-' . $jobId . '.log';
    }

    /**
     * Enqueue default-size thumb if missing. Returns job id or null when already cached / queued.
     *
     * @return array{job_id: ?int, status: 'ready'|'queued'|'active'|'error', error?: string}
     */
    public function enqueueIfNeeded(int $fileId, bool $large = false, ?int $createdBy = null, bool $spawn = true): array
    {
        $size = $large ? ThumbnailJobRepository::SIZE_LARGE : ThumbnailJobRepository::SIZE_DEFAULT;
        $existing = $this->cache->resolveThumbnailPath($fileId, $large);
        if ($existing !== null) {
            return ['job_id' => null, 'status' => 'ready'];
        }

        $active = $this->jobs->findActiveForFile($fileId, $size);
        if ($active !== null) {
            return ['job_id' => (int) $active['id'], 'status' => 'active'];
        }

        try {
            $jobId = $this->jobs->create($fileId, $size, $createdBy);
        } catch (PDOException $e) {
            if ($this->jobs->isUniqueViolation($e)) {
                $again = $this->jobs->findActiveForFile($fileId, $size);

                return [
                    'job_id' => $again !== null ? (int) $again['id'] : null,
                    'status' => 'active',
                ];
            }

            return ['job_id' => null, 'status' => 'error', 'error' => $e->getMessage()];
        }

        if ($spawn) {
            $this->maybeSpawnWorker($jobId);
        }

        return ['job_id' => $jobId, 'status' => 'queued'];
    }

    /** @return int|null Completed job id */
    public function runNextPending(): ?int
    {
        $id = $this->jobs->claimNextPending();
        if ($id === null) {
            return null;
        }

        return $this->executeClaimedJob($id);
    }

    /** @return int|null Completed job id */
    public function runJob(int $id): ?int
    {
        if (!$this->jobs->tryClaim($id) && !$this->jobs->claimOrphanedRunning($id)) {
            return null;
        }

        return $this->executeClaimedJob($id);
    }

    private function executeClaimedJob(int $id): int
    {
        $logFile = self::logPathForJob($id, $this->projectRoot);
        $this->ensureLogDir($logFile);
        $pid = (int) getmypid();
        $this->jobs->setWorkerPid($id, $pid);
        $this->log($logFile, 'INFO', 'Job claimed', ['job_id' => $id, 'pid' => $pid]);

        $job = $this->jobs->findById($id);
        if ($job === null) {
            $this->jobs->markFailed($id, 'Job row missing after claim');
            $this->log($logFile, 'ERROR', 'Job row missing after claim');

            return $id;
        }

        if ($this->jobs->isCancelRequested($id)) {
            $this->jobs->markCancelled($id, 'Cancelled before work started');
            $this->log($logFile, 'INFO', 'Cancelled before work started');

            return $id;
        }

        $fileId = (int) ($job['file_id'] ?? 0);
        $large = (($job['size'] ?? '') === ThumbnailJobRepository::SIZE_LARGE);

        try {
            $path = $this->thumbs->generate($fileId, $large);
            $this->jobs->markCompleted($id);
            $this->log($logFile, 'INFO', 'Thumbnail ready', [
                'file_id' => $fileId,
                'path'    => $path,
                'size'    => $job['size'] ?? 'default',
            ]);
        } catch (Throwable $e) {
            $this->jobs->markFailed($id, $e->getMessage());
            $this->log($logFile, 'ERROR', 'Job failed', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        }

        return $id;
    }

    private function maybeSpawnWorker(int $jobId): void
    {
        if (!WorkerMode::shouldSpawn()) {
            return;
        }

        $script = $this->projectRoot . '/scripts/thumbnail.php';
        $logDir = $this->projectRoot . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . '/thumbnail-' . $jobId . '.log';
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $cmd = sprintf(
            '%s %s --job-id=%d >> %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($script),
            $jobId,
            escapeshellarg($logFile)
        );
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen('start /B ' . $cmd, 'r'));
        } else {
            exec($cmd);
        }
    }

    private function ensureLogDir(string $logFile): void
    {
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /** @param array<string, mixed> $ctx */
    private function log(string $logFile, string $level, string $message, array $ctx = []): void
    {
        $line = '[' . gmdate('Y-m-d\TH:i:s\Z') . "] {$level} {$message}";
        if ($ctx !== []) {
            $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES);
        }
        $line .= "\n";
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
