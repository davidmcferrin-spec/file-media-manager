<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\FileRepository;
use MediaManager\Repositories\SystemRepository;
use RuntimeException;

final class PreviewService
{
    private string $ffmpegBin;
    private int $offsetSeconds;
    private int $durationSeconds;
    private int $width;
    private int $height;

    public function __construct(
        private readonly FileRepository $files = new FileRepository(),
        private readonly MediaCacheService $cache = new MediaCacheService(),
        private readonly SystemRepository $settings = new SystemRepository(),
    ) {
        $this->ffmpegBin        = (string) env('FFMPEG_BIN', '/usr/bin/ffmpeg');
        $this->offsetSeconds    = (int) ($settings->get('thumbnail_offset_seconds') ?? env('THUMBNAIL_OFFSET_SECONDS', 50));
        $this->durationSeconds  = (int) env('PREVIEW_DURATION_SECONDS', 180);
        $this->width            = (int) env('PREVIEW_WIDTH', 420);
        $this->height           = (int) env('PREVIEW_HEIGHT', 236);
    }

    public function ensurePreview(int $fileId): string
    {
        $dest = $this->cache->previewPath($fileId);
        if (is_readable($dest) && filesize($dest) > 0) {
            return $dest;
        }

        if (is_file($dest)) {
            @unlink($dest);
        }

        $file = $this->files->findById($fileId);
        if ($file === null) {
            throw new RuntimeException('File not found.');
        }

        $source = FileRepository::mediaSourcePath($file);
        if ($source === '' || !is_readable($source)) {
            $stored = (string) ($file['original_path'] ?? '');
            $mount  = (string) ($file['source_mount'] ?? '');
            throw new RuntimeException(
                'Source file not readable for preview.'
                . ($stored !== '' ? ' Stored path: ' . $stored : '')
                . ($mount !== '' ? ' Source mount: ' . $mount : '')
            );
        }

        $this->cache->ensurePreviewDir();

        $offset = $this->offsetSeconds;
        if (!empty($file['duration_seconds']) && (float) $file['duration_seconds'] < $offset) {
            $offset = max(1, (int) floor((float) $file['duration_seconds'] / 4));
        }

        $duration = $this->durationSeconds;
        if (!empty($file['duration_seconds'])) {
            $remaining = (float) $file['duration_seconds'] - $offset;
            if ($remaining > 0 && $remaining < $duration) {
                $duration = max(1, (int) floor($remaining));
            }
        }

        $lastOutput = $this->generatePreview($source, $dest, $offset, $duration);
        if (!is_readable($dest) || filesize($dest) === 0) {
            throw new RuntimeException(
                'Preview generation failed.'
                . ($lastOutput !== '' ? ' FFmpeg: ' . $lastOutput : ' Check that libvpx is installed.')
            );
        }

        return $dest;
    }

    private function generatePreview(string $source, string $dest, int $offset, int $duration): string
    {
        $scale = sprintf(
            'scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2',
            $this->width,
            $this->height,
            $this->width,
            $this->height
        );

        $codecs = [
            '-c:v libvpx-vp9 -b:v 800k -deadline realtime -cpu-used 4',
            '-c:v libvpx -b:v 800k -deadline realtime -cpu-used 4',
        ];

        $lastOutput = '';
        foreach ($codecs as $codecArgs) {
            if (is_file($dest)) {
                @unlink($dest);
            }

            $cmd = sprintf(
                '%s -hide_banner -nostdin -loglevel error -ss %d -i %s -t %d -vf %s %s -an -f webm -y %s 2>&1',
                escapeshellcmd($this->ffmpegBin),
                $offset,
                escapeshellarg($source),
                $duration,
                escapeshellarg($scale),
                $codecArgs,
                escapeshellarg($dest)
            );

            $output = trim((string) shell_exec($cmd));
            if ($output !== '') {
                $lastOutput = $output;
                error_log('[preview] ffmpeg: ' . $output);
            }

            if (is_readable($dest) && filesize($dest) > 0) {
                return $lastOutput;
            }
        }

        return $lastOutput;
    }
}
