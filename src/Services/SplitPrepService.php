<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\CaptionExtractJobRepository;
use MediaManager\Repositories\FileRepository;
use MediaManager\Repositories\SplitAudioJobRepository;
use MediaManager\Repositories\SplitQueueRepository;
use MediaManager\Support\WorkerMode;
use PDOException;

/**
 * When a file is marked for split: ensure split queue row, caption extract
 * (if no usable SRT), and one audio analysis job. Without usable captions
 * queues suggest (silence → segments + seeded levels); with captions queues
 * levels only. Audio claim order prioritizes files without srt_path.
 */
final class SplitPrepService
{
    private readonly string $projectRoot;

    public function __construct(
        private readonly FileRepository $files = new FileRepository(),
        private readonly SplitQueueRepository $splitQueue = new SplitQueueRepository(),
        private readonly CaptionExtractJobRepository $captionJobs = new CaptionExtractJobRepository(),
        private readonly SplitAudioJobRepository $audioJobs = new SplitAudioJobRepository(),
        ?string $projectRoot = null,
    ) {
        $this->projectRoot = $projectRoot ?? dirname(__DIR__, 2);
    }

    /** Usable caption service = readable SRT with at least one cue. */
    public function hasUsableCaptions(array $file): bool
    {
        $srt = str_replace('\\', '/', trim((string) ($file['srt_path'] ?? '')));
        if ($srt === '' || !is_readable($srt)) {
            return false;
        }

        return SrtCaptionParser::parseFile($srt) !== [];
    }

    /**
     * @param list<int> $fileIds
     * @return array{
     *   split_queued: int,
     *   caption_job_id: ?int,
     *   caption_files: int,
     *   audio_jobs: int,
     *   audio_levels_jobs: int,
     *   audio_suggest_jobs: int
     * }
     */
    public function onMarkedForSplitMany(array $fileIds, int $userId, bool $spawnWorkers = true): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $fileIds),
            static fn (int $id): bool => $id > 0
        )));

        $splitQueued = 0;
        $captionIds = [];
        $audioJobs = 0;
        $audioLevelsJobs = 0;
        $audioSuggestJobs = 0;
        $lastAudioJobId = null;

        foreach ($ids as $fileId) {
            $result = $this->onMarkedForSplit($fileId, $userId, false);
            if (!empty($result['split_created']) || !empty($result['split_queue_id'])) {
                if (!empty($result['split_created'])) {
                    $splitQueued++;
                }
            }
            if (!empty($result['needs_caption'])) {
                $captionIds[] = $fileId;
            }
            if (!empty($result['audio_job_id'])) {
                $audioJobs++;
                $lastAudioJobId = (int) $result['audio_job_id'];
                $kind = (string) ($result['audio_kind'] ?? '');
                if ($kind === SplitAudioJobRepository::KIND_SUGGEST) {
                    $audioSuggestJobs++;
                } elseif ($kind === SplitAudioJobRepository::KIND_LEVELS) {
                    $audioLevelsJobs++;
                }
            }
        }

        $captionJobId = null;
        if ($captionIds !== []) {
            $captionJobId = $this->enqueueCaptionExtract($captionIds, $userId, $spawnWorkers);
        }

        if ($spawnWorkers && $lastAudioJobId !== null) {
            $this->spawnAudioWorker($lastAudioJobId);
        }

        return [
            'split_queued'       => $splitQueued,
            'caption_job_id'     => $captionJobId,
            'caption_files'      => count($captionIds),
            'audio_jobs'         => $audioJobs,
            'audio_levels_jobs'  => $audioLevelsJobs,
            'audio_suggest_jobs' => $audioSuggestJobs,
        ];
    }

    /**
     * @return array{
     *   split_queue_id: ?int,
     *   split_created: bool,
     *   needs_caption: bool,
     *   caption_job_id: ?int,
     *   audio_job_id: ?int,
     *   audio_kind: ?string
     * }
     */
    public function onMarkedForSplit(int $fileId, int $userId, bool $spawnWorkers = true): array
    {
        $file = $this->files->findById($fileId);
        if ($file === null) {
            return [
                'split_queue_id' => null,
                'split_created'  => false,
                'needs_caption'  => false,
                'caption_job_id' => null,
                'audio_job_id'   => null,
                'audio_kind'     => null,
            ];
        }

        $splitQueueId = null;
        $splitCreated = false;
        $active = $this->splitQueue->findActiveForFile($fileId);
        if ($active !== null) {
            $splitQueueId = (int) $active['id'];
        } else {
            try {
                $splitQueueId = $this->splitQueue->create(
                    $fileId,
                    $userId,
                    trim((string) ($file['split_notes'] ?? ''))
                );
                $splitCreated = true;
            } catch (PDOException $e) {
                if ($this->splitQueue->isUniqueViolation($e)) {
                    $again = $this->splitQueue->findActiveForFile($fileId);
                    $splitQueueId = $again !== null ? (int) $again['id'] : null;
                }
            }
        }

        $needsCaption = !$this->hasUsableCaptions($file);
        $captionJobId = null;
        if ($needsCaption && $spawnWorkers) {
            $captionJobId = $this->enqueueCaptionExtract([$fileId], $userId, true);
        }

        // One active audio job per file: suggest when no usable SRT (fills
        // segments + seeds levels); levels-only when captions already exist.
        $audioKind = $needsCaption
            ? SplitAudioJobRepository::KIND_SUGGEST
            : SplitAudioJobRepository::KIND_LEVELS;

        $audioJobId = null;
        if (
            $splitQueueId !== null
            && trim((string) ($file['codec_audio'] ?? '')) !== ''
            && $this->audioJobs->findActiveForFile($fileId) === null
        ) {
            try {
                $audioJobId = $this->audioJobs->create(
                    $splitQueueId,
                    $fileId,
                    $audioKind,
                    $userId
                );
                if ($spawnWorkers) {
                    $this->spawnAudioWorker($audioJobId);
                }
            } catch (PDOException $e) {
                if (!$this->audioJobs->isUniqueViolation($e)) {
                    error_log('[split-prep] audio queue file #' . $fileId . ': ' . $e->getMessage());
                }
                $audioJobId = null;
            }
        }

        return [
            'split_queue_id' => $splitQueueId,
            'split_created'  => $splitCreated,
            'needs_caption'  => $needsCaption,
            'caption_job_id' => $captionJobId,
            'audio_job_id'   => $audioJobId,
            'audio_kind'     => $audioJobId !== null ? $audioKind : null,
        ];
    }

    /**
     * @param list<int> $fileIds
     */
    public function enqueueCaptionExtract(array $fileIds, int $userId, bool $spawnWorkers = true): ?int
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $fileIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return null;
        }

        $active = $this->captionJobs->findActive();
        if ($active !== null) {
            $jobId = (int) $active['id'];
            $this->captionJobs->prependPriority($jobId, $ids);
            return $jobId;
        }

        $jobId = $this->captionJobs->create($userId, 'selected', $ids);
        $logFile = CaptionExtractJobService::logPathForJob($jobId, $this->projectRoot);
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        @file_put_contents(
            $logFile,
            '[' . gmdate('Y-m-d\TH:i:s\Z') . '] INFO Queued from split-prep count=' . count($ids) . "\n",
            FILE_APPEND | LOCK_EX
        );

        if ($spawnWorkers) {
            $this->spawnCaptionWorker($jobId, $logFile);
        }

        return $jobId;
    }

    private function spawnCaptionWorker(int $jobId, string $logFile): void
    {
        if (!WorkerMode::shouldSpawn()) {
            return;
        }
        $phpBin = PHP_BINARY;
        $script = $this->projectRoot . '/scripts/caption_extract.php';
        $flags = '--job-id=' . $jobId;
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen(
                'start /B "" ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($script)
                . ' ' . $flags . ' >> ' . escapeshellarg($logFile) . ' 2>&1',
                'r'
            ));
        } else {
            exec(
                'nohup ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($script)
                . ' ' . $flags . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &'
            );
        }
    }

    private function spawnAudioWorker(int $jobId): void
    {
        if (!WorkerMode::shouldSpawn()) {
            return;
        }
        $phpBin = PHP_BINARY;
        $script = $this->projectRoot . '/scripts/split_audio.php';
        $flags = '--job-id=' . $jobId;
        $logDir = $this->projectRoot . '/' . trim((string) env('STORAGE_LOGS', 'storage/logs'), '/');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . '/split-audio-' . $jobId . '.log';
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen(
                'start /B "" ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($script)
                . ' ' . $flags . ' >> ' . escapeshellarg($logFile) . ' 2>&1',
                'r'
            ));
        } else {
            exec(
                'nohup ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($script)
                . ' ' . $flags . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &'
            );
        }
    }
}
