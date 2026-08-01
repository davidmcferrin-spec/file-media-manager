<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\FileRepository;
use RuntimeException;

/**
 * Hybrid C media for Split workbench:
 * - Frame peeks for scrub / mark in-out (all supported codecs)
 * - Fast-path play segments for MP4/H.264 (stream copy when possible)
 * - Proxy play segments for TS/MXF/MPEG-2/DNxHD/ProRes (H.264/AAC)
 * - WMV unsupported
 */
final class SplitMediaService
{
    public const MODE_FAST = 'fast';
    public const MODE_PROXY = 'proxy';
    public const MODE_UNSUPPORTED = 'unsupported';

    private string $ffmpegBin;
    private int $segmentSeconds;
    private int $proxyWidth;
    private int $frameWidth;

    public function __construct(
        private readonly FileRepository $files = new FileRepository(),
        private readonly MediaCacheService $cache = new MediaCacheService(),
    ) {
        $this->ffmpegBin       = (string) env('FFMPEG_BIN', '/usr/bin/ffmpeg');
        $this->segmentSeconds  = max(10, (int) env('SPLIT_PLAY_SEGMENT_SECONDS', 45));
        $this->proxyWidth      = max(480, (int) env('SPLIT_PROXY_WIDTH', 960));
        $this->frameWidth      = max(320, (int) env('SPLIT_FRAME_WIDTH', 960));
    }

    /**
     * @param array<string, mixed> $file
     * @return self::MODE_*
     */
    public function playMode(array $file): string
    {
        $ext = strtolower(pathinfo((string) ($file['original_filename'] ?? ''), PATHINFO_EXTENSION));
        $codec = strtolower((string) ($file['codec_video'] ?? ''));
        $container = strtolower((string) ($file['container'] ?? ''));

        if ($ext === 'wmv' || str_contains($codec, 'wmv')) {
            return self::MODE_UNSUPPORTED;
        }

        $hardExt = ['ts', 'mts', 'm2ts', 'mxf', 'mpg', 'mpeg', 'vob'];
        if (in_array($ext, $hardExt, true)) {
            return self::MODE_PROXY;
        }

        if (
            str_contains($codec, 'mpeg2')
            || str_contains($codec, 'mpeg-2')
            || str_contains($codec, 'dnx')
            || str_contains($codec, 'prores')
        ) {
            return self::MODE_PROXY;
        }

        $isH264 = str_contains($codec, 'h264')
            || str_contains($codec, 'avc')
            || $codec === 'h.264';
        $isMp4Family = in_array($ext, ['mp4', 'm4v'], true)
            || str_contains($container, 'mp4')
            || str_contains($container, 'isom')
            || str_contains($container, 'iso5');

        if ($isH264 && $isMp4Family) {
            return self::MODE_FAST;
        }

        // MOV/H.264 can often stream-copy; other MOV (ProRes) already caught above.
        if ($isH264 && $ext === 'mov') {
            return self::MODE_FAST;
        }

        if ($ext === '' && $codec === '') {
            return self::MODE_PROXY;
        }

        return self::MODE_PROXY;
    }

    /**
     * @param array<string, mixed> $file
     * @return array{mode: string, label: string, segment_seconds: int, supported: bool}
     */
    public function describe(array $file): array
    {
        $mode = $this->playMode($file);
        $label = match ($mode) {
            self::MODE_FAST => 'Fast path (MP4/H.264)',
            self::MODE_PROXY => 'Proxy path (transcode segment)',
            default => 'Unsupported (WMV)',
        };

        return [
            'mode'             => $mode,
            'label'            => $label,
            'segment_seconds'  => $this->segmentSeconds,
            'supported'        => $mode !== self::MODE_UNSUPPORTED,
        ];
    }

    /** Round time to 0.5s buckets for frame cache keys. */
    public function frameBucket(float $seconds): int
    {
        $seconds = max(0.0, $seconds);

        return (int) round($seconds * 2);
    }

    public function ensureFrame(int $fileId, float $seconds): string
    {
        $file = $this->requireFile($fileId);
        if ($this->playMode($file) === self::MODE_UNSUPPORTED) {
            throw new RuntimeException('WMV is not supported in the split workbench.');
        }

        $source = $this->requireSource($file);
        $bucket = $this->frameBucket($seconds);
        $existing = $this->cache->resolveSplitFramePath($fileId, $bucket);
        if ($existing !== null) {
            return $existing;
        }

        $this->cache->ensureSplitProxyDir($fileId);
        $dest = $this->cache->splitFramePath($fileId, $bucket);

        $at = $bucket / 2.0;
        $duration = isset($file['duration_seconds']) ? (float) $file['duration_seconds'] : 0.0;
        if ($duration > 0) {
            $at = min($at, max(0.0, $duration - 0.05));
        }

        if (is_file($dest)) {
            @unlink($dest);
        }

        $cmd = sprintf(
            '%s -hide_banner -nostdin -loglevel error -ss %s -i %s -frames:v 1 -q:v 3 -vf %s -y %s 2>&1',
            escapeshellcmd($this->ffmpegBin),
            escapeshellarg(sprintf('%.3f', $at)),
            escapeshellarg($source),
            escapeshellarg('scale=' . $this->frameWidth . ':-1'),
            escapeshellarg($dest)
        );

        $output = trim((string) shell_exec($cmd));
        if (!is_readable($dest) || filesize($dest) === 0) {
            throw new RuntimeException(
                'Frame extract failed.' . ($output !== '' ? ' FFmpeg: ' . $output : '')
            );
        }

        return $dest;
    }

    /**
     * @return array{path: string, mode: string, start: int, duration: int}
     */
    public function ensurePlaySegment(int $fileId, float $startSeconds): array
    {
        $file = $this->requireFile($fileId);
        $mode = $this->playMode($file);
        if ($mode === self::MODE_UNSUPPORTED) {
            throw new RuntimeException('WMV is not supported in the split workbench.');
        }

        $source = $this->requireSource($file);
        $duration = isset($file['duration_seconds']) ? (float) $file['duration_seconds'] : 0.0;
        $start = (int) max(0, floor($startSeconds));
        if ($duration > 0 && $start >= (int) floor($duration)) {
            $start = max(0, (int) floor($duration) - 1);
        }

        $segDur = $this->segmentSeconds;
        if ($duration > 0) {
            $remaining = (int) ceil($duration - $start);
            if ($remaining > 0) {
                $segDur = min($segDur, max(1, $remaining));
            }
        }

        $this->cache->ensureSplitProxyDir($fileId);

        // Prefer cached fast segment; also accept a prior proxy fallback for the same window.
        foreach ([$mode, self::MODE_PROXY] as $tryMode) {
            $cached = $this->cache->resolveSplitSegmentPath($fileId, $start, $segDur, $tryMode);
            if ($cached !== null) {
                return [
                    'path'     => $cached,
                    'mode'     => $tryMode,
                    'start'    => $start,
                    'duration' => $segDur,
                ];
            }
        }

        set_time_limit(180);
        $lastOutput = '';
        $finalMode = $mode;
        $dest = $this->cache->splitSegmentPath($fileId, $start, $segDur, $finalMode);

        if ($mode === self::MODE_FAST) {
            if (is_file($dest)) {
                @unlink($dest);
            }
            $lastOutput = $this->runFfmpeg($this->fastCopyCmd($source, $dest, $start, $segDur));
            if (!is_readable($dest) || filesize($dest) === 0) {
                $finalMode = self::MODE_PROXY;
                $dest = $this->cache->splitSegmentPath($fileId, $start, $segDur, $finalMode);
            }
        }

        if ($finalMode === self::MODE_PROXY && (!(is_readable($dest) && filesize($dest) > 0))) {
            if (is_file($dest)) {
                @unlink($dest);
            }
            $lastOutput = $this->runFfmpeg($this->proxyCmd($source, $dest, $start, $segDur));
        }

        if (!is_readable($dest) || filesize($dest) === 0) {
            throw new RuntimeException(
                'Play segment failed.' . ($lastOutput !== '' ? ' FFmpeg: ' . $lastOutput : '')
            );
        }

        return [
            'path'     => $dest,
            'mode'     => $finalMode,
            'start'    => $start,
            'duration' => $segDur,
        ];
    }

    private function fastCopyCmd(string $source, string $dest, int $start, int $duration): string
    {
        return sprintf(
            '%s -hide_banner -nostdin -loglevel error -ss %d -i %s -t %d -map 0:v:0 -map 0:a:0? -c copy -movflags +faststart -y %s 2>&1',
            escapeshellcmd($this->ffmpegBin),
            $start,
            escapeshellarg($source),
            $duration,
            escapeshellarg($dest)
        );
    }

    private function proxyCmd(string $source, string $dest, int $start, int $duration): string
    {
        $vf = sprintf(
            'scale=%d:-2:force_original_aspect_ratio=decrease',
            $this->proxyWidth
        );

        return sprintf(
            '%s -hide_banner -nostdin -loglevel error -ss %d -i %s -t %d -map 0:v:0 -map 0:a:0? -c:v libx264 -preset veryfast -crf 28 -pix_fmt yuv420p -c:a aac -b:a 128k -ac 2 -vf %s -movflags +faststart -y %s 2>&1',
            escapeshellcmd($this->ffmpegBin),
            $start,
            escapeshellarg($source),
            $duration,
            escapeshellarg($vf),
            escapeshellarg($dest)
        );
    }

    private function runFfmpeg(string $cmd): string
    {
        $output = trim((string) shell_exec($cmd));
        if ($output !== '') {
            error_log('[split-media] ffmpeg: ' . $output);
        }

        return $output;
    }

    /** @return array<string, mixed> */
    private function requireFile(int $fileId): array
    {
        $file = $this->files->findById($fileId);
        if ($file === null) {
            throw new RuntimeException('File not found.');
        }

        return $file;
    }

    /** @param array<string, mixed> $file */
    private function requireSource(array $file): string
    {
        $source = FileRepository::mediaSourcePath($file);
        if ($source === '' || !is_readable($source)) {
            throw new RuntimeException('Source file not readable for split media.');
        }

        return $source;
    }
}
