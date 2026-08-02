<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\SplitAudioJobRepository;
use MediaManager\Repositories\SplitQueueRepository;
use MediaManager\Repositories\SystemRepository;
use Throwable;

/**
 * Background FFmpeg audio analysis for Split workbench (levels / suggest).
 */
final class SplitAudioJobService
{
    private string $projectRoot;

    public function __construct(
        private readonly SplitAudioJobRepository $jobs = new SplitAudioJobRepository(),
        private readonly SplitQueueRepository $splitQueue = new SplitQueueRepository(),
        private readonly SystemRepository $system = new SystemRepository(),
        private readonly MediaCacheService $cache = new MediaCacheService(),
        ?string $projectRoot = null,
    ) {
        $this->projectRoot = $projectRoot ?? dirname(__DIR__, 2);
    }

    public static function logPathForJob(int $jobId, ?string $projectRoot = null): string
    {
        $root = $projectRoot ?? dirname(__DIR__, 2);

        return $root . '/storage/logs/split-audio-' . $jobId . '.log';
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

        $kind = (string) ($job['kind'] ?? '');
        try {
            if ($kind === SplitAudioJobRepository::KIND_SUGGEST) {
                $summary = $this->runSuggest($job, $logFile, $id);
            } elseif ($kind === SplitAudioJobRepository::KIND_LEVELS) {
                $summary = $this->runLevels($job, $logFile, $id);
            } else {
                throw new \RuntimeException('Unknown job kind: ' . $kind);
            }

            if ($this->jobs->isCancelRequested($id)) {
                $this->jobs->markCancelled($id, 'Cancelled after analysis (results may be partial)');
                $this->log($logFile, 'INFO', 'Cancelled after analysis');

                return $id;
            }

            $this->jobs->markCompleted($id, $summary);
            $this->log($logFile, 'INFO', 'Job completed', ['summary' => $summary]);
        } catch (Throwable $e) {
            if ($this->jobs->isCancelRequested($id) || $e->getMessage() === 'Cancel requested') {
                $this->jobs->markCancelled($id, $e->getMessage());
                $this->log($logFile, 'INFO', 'Job cancelled', ['message' => $e->getMessage()]);
            } else {
                $this->jobs->markFailed($id, $e->getMessage());
                $this->log($logFile, 'ERROR', 'Job failed', [
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
            }
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function runSuggest(array $job, string $logFile, int $jobId): string
    {
        $settings = $this->audioSettings();
        $mediaPath = str_replace('\\', '/', (string) ($job['original_path'] ?? ''));
        $fileId = (int) $job['file_id'];
        $splitQueueId = (int) $job['split_queue_id'];
        $durationSec = isset($job['duration_seconds']) ? (float) $job['duration_seconds'] : 0.0;

        if ($mediaPath === '' || !is_readable($mediaPath)) {
            throw new \RuntimeException('Source media is not readable on disk');
        }
        if (trim((string) ($job['codec_audio'] ?? '')) === '') {
            throw new \RuntimeException('No audio stream on file');
        }

        $this->log($logFile, 'INFO', 'Running silencedetect', ['path' => $mediaPath]);
        $detector = new AudioSilenceDetector(noiseDb: $settings['noise_db']);
        $gaps = $detector->detect($mediaPath);
        $this->writeSilenceCache($fileId, $mediaPath, $settings['noise_db'], $gaps);

        if ($this->jobs->isCancelRequested($jobId)) {
            throw new \RuntimeException('Cancel requested');
        }

        try {
            (new AudioLevelMapService($this->cache))->buildFromSilenceAndCache(
                $fileId,
                $mediaPath,
                $durationSec,
                $settings['noise_db'],
                $gaps
            );
            $this->log($logFile, 'INFO', 'Seeded silence-based audio level lane');
        } catch (Throwable $e) {
            $this->log($logFile, 'WARN', 'Could not seed audio level lane', ['message' => $e->getMessage()]);
        }

        $suggestion = (new AudioSplitSuggester(
            new AudioSilenceDetector(noiseDb: $settings['noise_db']),
            $settings['flag_seconds'],
            $settings['content_gap'],
            $settings['min_program'],
            $settings['ad_ignore'],
        ))->suggestFromGaps(
            $gaps,
            $durationSec > 0 ? $durationSec : null,
            isset($job['file_date']) ? (string) $job['file_date'] : null,
            isset($job['file_time']) ? (string) $job['file_time'] : null,
        );

        if ($suggestion['segments'] === []) {
            throw new \RuntimeException(
                $suggestion['notes'] !== '' ? $suggestion['notes'] : 'No audio-based segments found'
            );
        }

        $item = $this->splitQueue->findById($splitQueueId);
        if ($item === null) {
            throw new \RuntimeException('Split queue job missing');
        }

        $segments = [];
        foreach ($suggestion['segments'] as $seg) {
            $segments[] = [
                'start'   => $seg['start'],
                'end'     => $seg['end'],
                'show_id' => $seg['show_id'],
                'label'   => $seg['label'],
            ];
        }

        $notes = trim((string) ($item['notes'] ?? ''));
        $notes = trim($notes . "\n\n" . $suggestion['notes']);
        $this->splitQueue->update(
            $splitQueueId,
            $segments,
            $notes,
            (string) ($item['status'] ?? 'PENDING')
        );

        return sprintf(
            'Suggest: %d segment(s), %d content gap(s)',
            count($segments),
            (int) ($suggestion['content_gap_count'] ?? 0)
        );
    }

    /**
     * @param array<string, mixed> $job
     */
    private function runLevels(array $job, string $logFile, int $jobId): string
    {
        $settings = $this->audioSettings();
        $mediaPath = str_replace('\\', '/', (string) ($job['original_path'] ?? ''));
        $fileId = (int) $job['file_id'];
        $durationSec = isset($job['duration_seconds']) ? (float) $job['duration_seconds'] : 0.0;

        if ($mediaPath === '' || !is_readable($mediaPath)) {
            throw new \RuntimeException('Source media is not readable on disk');
        }
        if (trim((string) ($job['codec_audio'] ?? '')) === '') {
            throw new \RuntimeException('No audio stream on file');
        }

        $gaps = null;
        try {
            $this->log($logFile, 'INFO', 'Loading/detecting silence gaps for fallback');
            $gaps = $this->loadOrDetectSilence($fileId, $mediaPath, $settings['noise_db'], $logFile);
        } catch (Throwable $e) {
            $this->log($logFile, 'WARN', 'Silence detect skipped', ['message' => $e->getMessage()]);
        }

        if ($this->jobs->isCancelRequested($jobId)) {
            throw new \RuntimeException('Cancel requested');
        }

        $this->log($logFile, 'INFO', 'Sampling RMS levels', ['path' => $mediaPath]);
        $map = (new AudioLevelMapService($this->cache))->buildAndCache(
            $fileId,
            $mediaPath,
            $durationSec,
            $settings['noise_db'],
            $gaps
        );

        return sprintf(
            'Levels: source=%s, %d block(s)',
            $map['source'],
            count($map['blocks'])
        );
    }

    /**
     * @return list<array{start: float, end: float, duration: float}>
     */
    private function loadOrDetectSilence(
        int $fileId,
        string $mediaPath,
        float $noiseDb,
        string $logFile,
    ): array {
        $mtime = @filemtime($mediaPath) ?: 0;
        $size = @filesize($mediaPath) ?: 0;
        $cachePath = $this->cache->assetDir($fileId) . '/audio_silence.json';
        if (is_readable($cachePath)) {
            $raw = file_get_contents($cachePath);
            $json = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($json)
                && (int) ($json['mtime'] ?? 0) === $mtime
                && (int) ($json['size'] ?? 0) === $size
                && abs((float) ($json['noise_db'] ?? 0) - $noiseDb) < 0.01
                && isset($json['gaps']) && is_array($json['gaps'])
            ) {
                $this->log($logFile, 'INFO', 'Using cached silence map');
                /** @var list<array{start: float, end: float, duration: float}> */
                return $json['gaps'];
            }
        }

        $gaps = (new AudioSilenceDetector(noiseDb: $noiseDb))->detect($mediaPath);
        $this->writeSilenceCache($fileId, $mediaPath, $noiseDb, $gaps);

        return $gaps;
    }

    /**
     * @param list<array{start: float, end: float, duration: float}> $gaps
     */
    private function writeSilenceCache(int $fileId, string $mediaPath, float $noiseDb, array $gaps): void
    {
        $dir = $this->cache->assetDir($fileId);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($dir . '/audio_silence.json', json_encode([
            'mtime'    => @filemtime($mediaPath) ?: 0,
            'size'     => @filesize($mediaPath) ?: 0,
            'noise_db' => $noiseDb,
            'gaps'     => $gaps,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{
     *   flag_seconds: int,
     *   content_gap: float,
     *   min_program: float,
     *   ad_ignore: float,
     *   noise_db: float
     * }
     */
    private function audioSettings(): array
    {
        $flagSeconds = (int) ($this->system->get('split_flag_threshold_seconds')
            ?? env('SPLIT_FLAG_THRESHOLD_SECONDS', ScheduleSplitSuggester::DEFAULT_FLAG_THRESHOLD_SECONDS));
        if ($flagSeconds < 1) {
            $flagSeconds = ScheduleSplitSuggester::DEFAULT_FLAG_THRESHOLD_SECONDS;
        }

        $contentGap = (float) ($this->system->get('split_audio_content_gap_seconds')
            ?? env('SPLIT_AUDIO_CONTENT_GAP_SECONDS', AudioSplitSuggester::DEFAULT_CONTENT_GAP_SECONDS));
        $minProgram = (float) ($this->system->get('split_audio_min_program_seconds')
            ?? env('SPLIT_AUDIO_MIN_PROGRAM_SECONDS', AudioSplitSuggester::DEFAULT_MIN_PROGRAM_SECONDS));
        $adIgnore = (float) ($this->system->get('split_audio_ad_ignore_seconds')
            ?? env('SPLIT_AUDIO_AD_IGNORE_SECONDS', AudioSplitSuggester::DEFAULT_AD_IGNORE_SECONDS));
        $noiseDb = (float) ($this->system->get('split_audio_silence_noise_db')
            ?? env('SPLIT_AUDIO_SILENCE_NOISE_DB', AudioSplitSuggester::DEFAULT_SILENCE_NOISE_DB));

        return [
            'flag_seconds' => $flagSeconds,
            'content_gap'  => max(60.0, $contentGap),
            'min_program'  => max(30.0, $minProgram),
            'ad_ignore'    => max(1.0, $adIgnore),
            'noise_db'     => min(-5.0, max(-80.0, $noiseDb)),
        ];
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
