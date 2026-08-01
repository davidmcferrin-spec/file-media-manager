<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\CaptionExtractJobRepository;
use MediaManager\Repositories\FileRepository;
use Throwable;

/**
 * Background worker: probe + extract SRT for many catalog files.
 */
final class CaptionExtractJobService
{
    private string $logPath = '';

    public function __construct(
        private readonly CaptionExtractJobRepository $jobs = new CaptionExtractJobRepository(),
        private readonly FileRepository $files = new FileRepository(),
        private readonly CaptionExtractService $extractor = new CaptionExtractService(),
        private readonly string $projectRoot = '',
    ) {
    }

    public function runJob(int $jobId): void
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null) {
            throw new \InvalidArgumentException('Caption extract job not found: ' . $jobId);
        }

        $claimed = $this->jobs->tryClaim($jobId);
        if (!$claimed) {
            $claimed = $this->jobs->claimOrphanedRunning($jobId);
        }
        if (!$claimed) {
            $status = (string) ($job['status'] ?? '');
            if ($status === 'RUNNING') {
                throw new \RuntimeException('Job #' . $jobId . ' is already running (worker still alive).');
            }
            throw new \RuntimeException('Job #' . $jobId . ' cannot be claimed (status=' . $status . ').');
        }

        $this->jobs->setWorkerPid($jobId, (int) getmypid());
        $this->logPath = $this->resolveLogPath($jobId);
        $this->log('INFO', 'Worker started', [
            'job_id' => $jobId,
            'pid'    => getmypid(),
            'scope'  => $job['scope'] ?? 'missing_srt',
        ]);

        try {
            $scope = (string) ($job['scope'] ?? 'missing_srt');
            $selectedIds = $this->decodeFileIds($job['file_ids'] ?? null);

            $summary = $this->files->summarizeCaptionExtractCandidates($scope, $selectedIds);
            $this->jobs->setTotals($jobId, $summary['count'], $summary['duration_seconds']);
            $this->log('INFO', 'Candidate summary', [
                'total_files' => $summary['count'],
                'total_duration_hours' => round($summary['duration_seconds'] / 3600, 2),
            ]);

            if ($summary['count'] === 0) {
                $this->log('INFO', 'No candidates — marking completed');
                $this->jobs->setCurrent($jobId, null, null);
                $this->jobs->markCompleted($jobId);

                return;
            }

            $afterId = 0;
            $batch = 50;
            while (true) {
                if ($this->jobs->isCancelRequested($jobId)) {
                    $this->log('WARN', 'Cancel requested — stopping');
                    $this->jobs->setCurrent($jobId, null, null);
                    $this->jobs->markCancelled($jobId);

                    return;
                }

                // Priority lane: selected clips jump ahead of normal id order.
                $priority = $this->jobs->getPriorityIds($jobId);
                if ($priority !== []) {
                    foreach ($priority as $priorityId) {
                        if ($this->jobs->isCancelRequested($jobId)) {
                            $this->log('WARN', 'Cancel requested — stopping');
                            $this->jobs->setCurrent($jobId, null, null);
                            $this->jobs->markCancelled($jobId);

                            return;
                        }

                        $row = $this->files->findById($priorityId);
                        if ($row !== null && $this->isStillCandidate($row, $scope, $selectedIds)) {
                            $this->log('INFO', 'PRIORITY file', ['file_id' => $priorityId]);
                            $this->processOne($jobId, $row, true);
                        } else {
                            $this->log('INFO', 'PRIORITY drop (not a candidate)', [
                                'file_id' => $priorityId,
                            ]);
                        }
                        $this->jobs->removeFromPriority($jobId, $priorityId);
                    }
                    continue;
                }

                $rows = $this->files->listCaptionExtractCandidates($scope, $selectedIds, $afterId, $batch);
                if ($rows === []) {
                    // Fresh priority may have been added while draining the last batch.
                    if ($this->jobs->getPriorityIds($jobId) !== []) {
                        continue;
                    }
                    break;
                }

                foreach ($rows as $row) {
                    if ($this->jobs->isCancelRequested($jobId)) {
                        $this->log('WARN', 'Cancel requested — stopping');
                        $this->jobs->setCurrent($jobId, null, null);
                        $this->jobs->markCancelled($jobId);

                        return;
                    }

                    // Re-check priority between normal files so Move to top takes effect ASAP.
                    if ($this->jobs->getPriorityIds($jobId) !== []) {
                        break;
                    }

                    $afterId = (int) $row['id'];
                    $this->processOne($jobId, $row, false);
                }
            }

            $this->jobs->setCurrent($jobId, null, null);
            $this->jobs->markCompleted($jobId);
            $final = $this->jobs->findById($jobId);
            $this->log('INFO', 'Job completed', [
                'ok'       => $final['ok_count'] ?? 0,
                'fail'     => $final['fail_count'] ?? 0,
                'skip'     => $final['skip_count'] ?? 0,
                'processed'=> $final['processed_files'] ?? 0,
            ]);
        } catch (Throwable $e) {
            $this->log('ERROR', 'Job failed', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            $this->jobs->markFailed($jobId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<int>|null $selectedIds
     */
    private function isStillCandidate(array $row, string $scope, ?array $selectedIds): bool
    {
        if (!empty($row['srt_path'])) {
            return false;
        }
        $status = (string) ($row['status'] ?? '');
        if (!in_array($status, ['PENDING', 'FLAGGED', 'APPROVED', 'REJECTED', 'EXECUTED'], true)) {
            return false;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($scope === 'has_captions' && empty($row['has_captions'])) {
            return false;
        }
        if ($scope === 'selected') {
            $selected = $selectedIds ?? [];

            return $id > 0 && in_array($id, $selected, true);
        }

        return $id > 0;
    }

    /** @param array<string, mixed> $row */
    private function processOne(int $jobId, array $row, bool $priority): void
    {
        $fileId = (int) $row['id'];
        $filename = (string) ($row['original_filename'] ?? ('#' . $fileId));
        $duration = isset($row['duration_seconds']) && $row['duration_seconds'] !== null
            ? (float) $row['duration_seconds']
            : CaptionExtractEtaEstimator::DEFAULT_DURATION_SECONDS;

        $this->jobs->setCurrent($jobId, $fileId, $filename);
        $t0 = microtime(true);
        $this->log('INFO', $priority ? 'START priority file' : 'START file', [
            'file_id'          => $fileId,
            'filename'         => $filename,
            'path'             => (string) ($row['original_path'] ?? ''),
            'duration_seconds' => $duration,
            'has_captions'     => !empty($row['has_captions']),
            'stream_index'     => $row['caption_stream_index'] ?? null,
            'priority'         => $priority,
        ]);

        try {
            $result = $this->extractor->extractForFile($fileId);
            $elapsed = round(microtime(true) - $t0, 2);

            if ($result['ok']) {
                $this->jobs->recordResult($jobId, 'ok', $duration, null);
                $this->log('INFO', 'OK extract', [
                    'file_id'      => $fileId,
                    'srt_path'     => $result['srt_path'],
                    'message'      => $result['message'],
                    'wall_seconds' => $elapsed,
                    'ffmpeg_tail'  => $result['ffmpeg_tail'] ?? '',
                ]);
            } elseif (!empty($result['skip'])) {
                $this->jobs->recordResult($jobId, 'skip', $duration, null);
                $this->log('INFO', 'SKIP no captions', [
                    'file_id'      => $fileId,
                    'message'      => $result['message'],
                    'wall_seconds' => $elapsed,
                ]);
            } else {
                $this->jobs->recordResult($jobId, 'fail', $duration, $result['message']);
                $this->log('ERROR', 'FAIL extract', [
                    'file_id'      => $fileId,
                    'filename'     => $filename,
                    'path'         => (string) ($row['original_path'] ?? ''),
                    'message'      => $result['message'],
                    'wall_seconds' => $elapsed,
                    'ffmpeg_tail'  => $result['ffmpeg_tail'] ?? '',
                ]);
            }
        } catch (Throwable $e) {
            $elapsed = round(microtime(true) - $t0, 2);
            $msg = $e->getMessage();
            $this->jobs->recordResult($jobId, 'fail', $duration, $msg);
            $this->log('ERROR', 'EXCEPTION extract', [
                'file_id'      => $fileId,
                'filename'     => $filename,
                'exception'    => $e::class,
                'message'      => $msg,
                'wall_seconds' => $elapsed,
                'trace'        => $e->getTraceAsString(),
            ]);
        }
    }

    /** @param mixed $raw */
    private function decodeFileIds(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return array_map('intval', $raw);
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? array_map('intval', $decoded) : null;
        }

        return null;
    }

    private function resolveLogPath(int $jobId): string
    {
        $root = $this->projectRoot !== ''
            ? $this->projectRoot
            : dirname(__DIR__, 2);
        $dir = $root . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . '/caption-extract-' . $jobId . '.log';
    }

    /** @param array<string, mixed> $context */
    private function log(string $level, string $message, array $context = []): void
    {
        $line = '[' . gmdate('Y-m-d\TH:i:s\Z') . '] ' . $level . ' ' . $message;
        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $line .= "\n";

        if ($this->logPath !== '') {
            @file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
        }
        error_log('[caption-extract] ' . trim($line));
        if (PHP_SAPI === 'cli') {
            echo $line;
        }
    }

    public static function logPathForJob(int $jobId, string $projectRoot): string
    {
        return rtrim($projectRoot, '/\\') . '/storage/logs/caption-extract-' . $jobId . '.log';
    }

    /** @return list<string> */
    public static function tailLog(string $path, int $lines = 120): array
    {
        if (!is_readable($path)) {
            return [];
        }
        $content = file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($content) || $content === []) {
            return [];
        }

        return array_slice($content, -max(1, $lines));
    }
}
