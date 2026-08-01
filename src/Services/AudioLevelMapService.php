<?php

declare(strict_types=1);

namespace MediaManager\Services;

use RuntimeException;

/**
 * Coarse audio activity map for Split workbench (not a waveform).
 *
 * Levels: 0 quiet · 1 low · 2 dialog · 3 hot
 * Cached under storage/media/…/audio_levels.json
 */
final class AudioLevelMapService
{
    public const CACHE_NAME = 'audio_levels.json';
    public const DEFAULT_BUCKET_SECONDS = 2.0;

    /** @var list<string> */
    public const LEVEL_LABELS = ['Quiet', 'Low', 'Dialog', 'Hot'];

    private string $ffmpegBin;

    public function __construct(
        private readonly MediaCacheService $cache = new MediaCacheService(),
        ?string $ffmpegBin = null,
        private readonly float $bucketSeconds = self::DEFAULT_BUCKET_SECONDS,
    ) {
        $this->ffmpegBin = $ffmpegBin ?? (string) env('FFMPEG_BIN', '/usr/bin/ffmpeg');
    }

    /**
     * @return array{
     *   available: bool,
     *   bucket_seconds: float,
     *   duration: float,
     *   source: string,
     *   levels: list<int>,
     *   blocks: list<array{start: float, end: float, level: int}>,
     *   labels: list<string>
     * }|null
     */
    public function loadCached(
        int $fileId,
        string $mediaPath,
        float $noiseDb,
    ): ?array {
        $meta = $this->fileMeta($mediaPath);
        $path = $this->cachePath($fileId);
        if (!is_readable($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($json)
            || (int) ($json['mtime'] ?? 0) !== $meta['mtime']
            || (int) ($json['size'] ?? 0) !== $meta['size']
            || abs((float) ($json['noise_db'] ?? 0) - $noiseDb) >= 0.01
            || abs((float) ($json['bucket_seconds'] ?? 0) - $this->bucketSeconds) >= 0.01
            || !isset($json['levels']) || !is_array($json['levels'])
        ) {
            return null;
        }

        /** @var list<int> $levels */
        $levels = array_map(static fn ($v): int => max(0, min(3, (int) $v)), $json['levels']);
        $duration = (float) ($json['duration'] ?? 0);
        $bucket = (float) ($json['bucket_seconds'] ?? $this->bucketSeconds);

        return $this->pack(
            $levels,
            $duration,
            $bucket,
            (string) ($json['source'] ?? 'cache')
        );
    }

    /**
     * Full RMS analysis (FFmpeg). Falls back to silence-based map on failure if gaps provided.
     *
     * @param list<array{start: float, end: float, duration?: float}>|null $silenceGaps
     * @return array{
     *   available: bool,
     *   bucket_seconds: float,
     *   duration: float,
     *   source: string,
     *   levels: list<int>,
     *   blocks: list<array{start: float, end: float, level: int}>,
     *   labels: list<string>
     * }
     */
    public function buildAndCache(
        int $fileId,
        string $mediaPath,
        float $durationSeconds,
        float $noiseDb,
        ?array $silenceGaps = null,
    ): array {
        $duration = max(0.0, $durationSeconds);
        $map = null;
        try {
            $rms = $this->sampleRmsDb($mediaPath, $duration);
            if ($rms !== []) {
                $levels = self::quantizeRms($rms, $noiseDb);
                $map = $this->pack($levels, $duration, $this->bucketSeconds, 'rms');
            }
        } catch (RuntimeException) {
            $map = null;
        }

        if ($map === null && $silenceGaps !== null) {
            $levels = self::levelsFromSilenceGaps($silenceGaps, $duration, $this->bucketSeconds);
            $map = $this->pack($levels, $duration, $this->bucketSeconds, 'silence');
        }

        if ($map === null) {
            throw new RuntimeException('Could not build audio level map (no RMS samples and no silence gaps).');
        }

        $this->writeCache($fileId, $mediaPath, $noiseDb, $map);

        return $map;
    }

    /**
     * Quick 2-tone map from silence gaps (quiet vs dialog).
     *
     * @param list<array{start: float, end: float, duration?: float}> $silenceGaps
     * @return array{
     *   available: bool,
     *   bucket_seconds: float,
     *   duration: float,
     *   source: string,
     *   levels: list<int>,
     *   blocks: list<array{start: float, end: float, level: int}>,
     *   labels: list<string>
     * }
     */
    public function buildFromSilenceAndCache(
        int $fileId,
        string $mediaPath,
        float $durationSeconds,
        float $noiseDb,
        array $silenceGaps,
    ): array {
        $duration = max(0.0, $durationSeconds);
        $levels = self::levelsFromSilenceGaps($silenceGaps, $duration, $this->bucketSeconds);
        $map = $this->pack($levels, $duration, $this->bucketSeconds, 'silence');
        $this->writeCache($fileId, $mediaPath, $noiseDb, $map);

        return $map;
    }

    /**
     * @param list<float|null> $rmsDb per bucket (null / -inf = quiet)
     * @return list<int>
     */
    public static function quantizeRms(array $rmsDb, float $noiseDb): array
    {
        $active = [];
        foreach ($rmsDb as $db) {
            if ($db === null || !is_finite($db) || $db < $noiseDb) {
                continue;
            }
            $active[] = $db;
        }

        $p33 = null;
        $p66 = null;
        if ($active !== []) {
            sort($active);
            $n = count($active);
            $p33 = $active[(int) floor(($n - 1) * 0.33)];
            $p66 = $active[(int) floor(($n - 1) * 0.66)];
        }

        $out = [];
        foreach ($rmsDb as $db) {
            if ($db === null || !is_finite($db) || $db < $noiseDb) {
                $out[] = 0;
                continue;
            }
            if ($p33 === null || $p66 === null) {
                $out[] = 2;
                continue;
            }
            if ($db < $p33) {
                $out[] = 1;
            } elseif ($db < $p66) {
                $out[] = 2;
            } else {
                $out[] = 3;
            }
        }

        return $out;
    }

    /**
     * @param list<array{start: float, end: float, duration?: float}> $gaps
     * @return list<int>
     */
    public static function levelsFromSilenceGaps(array $gaps, float $duration, float $bucketSeconds): array
    {
        $bucket = max(0.5, $bucketSeconds);
        $count = $duration > 0 ? (int) max(1, (int) ceil($duration / $bucket)) : 0;
        $levels = array_fill(0, $count, 2);
        foreach ($gaps as $gap) {
            $start = max(0.0, (float) ($gap['start'] ?? 0));
            $end = max($start, (float) ($gap['end'] ?? 0));
            $i0 = (int) floor($start / $bucket);
            $i1 = (int) min($count - 1, floor(($end - 0.001) / $bucket));
            for ($i = $i0; $i <= $i1; $i++) {
                if ($i >= 0 && $i < $count) {
                    $levels[$i] = 0;
                }
            }
        }

        return $levels;
    }

    /**
     * @param list<int> $levels
     * @return list<array{start: float, end: float, level: int}>
     */
    public static function levelsToBlocks(array $levels, float $bucketSeconds, float $duration): array
    {
        if ($levels === []) {
            return [];
        }
        $bucket = max(0.5, $bucketSeconds);
        $blocks = [];
        $curLevel = $levels[0];
        $start = 0.0;
        $n = count($levels);
        for ($i = 1; $i <= $n; $i++) {
            $level = $i < $n ? $levels[$i] : null;
            if ($level !== $curLevel) {
                $end = min($duration > 0 ? $duration : $i * $bucket, $i * $bucket);
                $blocks[] = [
                    'start' => round($start, 3),
                    'end'   => round($end, 3),
                    'level' => $curLevel,
                ];
                $start = $i * $bucket;
                $curLevel = $level ?? $curLevel;
            }
        }

        return $blocks;
    }

    /**
     * @return list<float|null>
     */
    public function sampleRmsDb(string $mediaPath, float $durationSeconds): array
    {
        if (!is_readable($mediaPath)) {
            throw new RuntimeException('Media file is not readable: ' . $mediaPath);
        }
        if (!is_file($this->ffmpegBin)) {
            throw new RuntimeException('FFmpeg is not available at ' . $this->ffmpegBin);
        }

        $bucket = max(0.5, $this->bucketSeconds);
        $samplesPerBucket = max(1, (int) round(8000 * $bucket));
        $tmp = tempnam(sys_get_temp_dir(), 'amm');
        if ($tmp === false) {
            throw new RuntimeException('Could not create temp file for audio levels.');
        }
        $metaPath = $tmp . '.txt';
        @unlink($tmp);
        $metaPathFwd = str_replace('\\', '/', $metaPath);

        $af = sprintf(
            'aresample=8000,asetnsamples=n=%d,astats=metadata=1:reset=1,ametadata=mode=print:file=%s',
            $samplesPerBucket,
            $metaPathFwd
        );

        $cmd = sprintf(
            '%s -hide_banner -nostats -i %s -vn -af %s -f null - 2>&1',
            escapeshellcmd($this->ffmpegBin),
            escapeshellarg($mediaPath),
            escapeshellarg($af)
        );

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);

        $raw = is_readable($metaPath) ? (string) file_get_contents($metaPath) : '';
        @unlink($metaPath);

        $rms = self::parseRmsMetadata($raw);
        if ($rms === [] && $code !== 0) {
            $tail = trim(substr(implode("\n", $output), -600));
            throw new RuntimeException(
                'FFmpeg audio level scan failed (exit ' . $code . ')'
                . ($tail !== '' ? ': ' . $tail : '')
            );
        }

        // Pad / trim to expected bucket count from duration.
        $expected = $durationSeconds > 0 ? (int) max(1, (int) ceil($durationSeconds / $bucket)) : count($rms);
        if (count($rms) < $expected) {
            $pad = $expected - count($rms);
            for ($i = 0; $i < $pad; $i++) {
                $rms[] = null;
            }
        } elseif (count($rms) > $expected && $expected > 0) {
            $rms = array_slice($rms, 0, $expected);
        }

        return $rms;
    }

    /**
     * @return list<float|null>
     */
    public static function parseRmsMetadata(string $raw): array
    {
        $out = [];
        if ($raw === '') {
            return $out;
        }
        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
            if (preg_match('/lavfi\.astats\.Overall\.RMS_level=(-?(?:\d+(?:\.\d+)?|inf))/i', $line, $m) !== 1) {
                continue;
            }
            $token = strtolower($m[1]);
            if ($token === 'inf' || $token === '-inf') {
                $out[] = null;
                continue;
            }
            $out[] = (float) $m[1];
        }

        return $out;
    }

    /**
     * @param list<int> $levels
     * @return array{
     *   available: bool,
     *   bucket_seconds: float,
     *   duration: float,
     *   source: string,
     *   levels: list<int>,
     *   blocks: list<array{start: float, end: float, level: int}>,
     *   labels: list<string>
     * }
     */
    private function pack(array $levels, float $duration, float $bucket, string $source): array
    {
        return [
            'available'      => $levels !== [],
            'bucket_seconds' => $bucket,
            'duration'       => $duration,
            'source'         => $source,
            'levels'         => $levels,
            'blocks'         => self::levelsToBlocks($levels, $bucket, $duration),
            'labels'         => self::LEVEL_LABELS,
        ];
    }

    /**
     * @param array{
     *   bucket_seconds: float,
     *   duration: float,
     *   source: string,
     *   levels: list<int>
     * } $map
     */
    private function writeCache(int $fileId, string $mediaPath, float $noiseDb, array $map): void
    {
        $meta = $this->fileMeta($mediaPath);
        $dir = $this->cache->assetDir($fileId);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $payload = [
            'mtime'          => $meta['mtime'],
            'size'           => $meta['size'],
            'noise_db'       => $noiseDb,
            'bucket_seconds' => $map['bucket_seconds'],
            'duration'       => $map['duration'],
            'source'         => $map['source'],
            'levels'         => $map['levels'],
        ];
        @file_put_contents(
            $this->cachePath($fileId),
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
    }

    private function cachePath(int $fileId): string
    {
        return $this->cache->assetDir($fileId) . '/' . self::CACHE_NAME;
    }

    /** @return array{mtime: int, size: int} */
    private function fileMeta(string $mediaPath): array
    {
        return [
            'mtime' => @filemtime($mediaPath) ?: 0,
            'size'  => @filesize($mediaPath) ?: 0,
        ];
    }
}
