<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\FileRepository;
use MediaManager\Repositories\SystemRepository;
use RuntimeException;

final class ThumbnailService
{
    private string $ffmpegBin;
    private int $offsetSeconds;
    private int $defaultWidth;
    private int $quality;

    public function __construct(
        private readonly FileRepository $files = new FileRepository(),
        private readonly MediaCacheService $cache = new MediaCacheService(),
        private readonly SystemRepository $settings = new SystemRepository(),
    ) {
        $this->ffmpegBin     = (string) env('FFMPEG_BIN', '/usr/bin/ffmpeg');
        $this->offsetSeconds = (int) ($settings->get('thumbnail_offset_seconds') ?? env('THUMBNAIL_OFFSET_SECONDS', 50));
        $this->defaultWidth  = (int) env('THUMBNAIL_WIDTH', 320);
        $this->quality       = (int) env('THUMBNAIL_QUALITY', 5);
    }

    public function pathForFileId(int $fileId): string
    {
        return $this->cache->thumbnailPath($fileId);
    }

    public function ensureThumbnail(int $fileId, ?int $width = null): string
    {
        $width ??= $this->defaultWidth;
        $large = $width > $this->defaultWidth;

        $existing = $this->cache->resolveThumbnailPath($fileId, $large);
        if ($existing !== null) {
            return $existing;
        }

        $file = $this->files->findById($fileId);
        if ($file === null) {
            throw new RuntimeException('File not found.');
        }

        $source = FileRepository::mediaSourcePath($file);
        if (!is_readable($source)) {
            throw new RuntimeException('Source file not readable for thumbnail.');
        }

        $this->cache->ensureAssetDir($fileId);
        $dest = $large
            ? $this->cache->thumbnailLargePath($fileId)
            : $this->cache->thumbnailPath($fileId);

        $offset = $this->offsetSeconds;
        if (!empty($file['duration_seconds']) && (float) $file['duration_seconds'] < $offset) {
            $offset = max(1, (int) floor((float) $file['duration_seconds'] / 2));
        }

        $cmd = sprintf(
            '%s -hide_banner -loglevel error -ss %d -i %s -vframes 1 -vf scale=%d:-1 -q:v %d -y %s 2>&1',
            escapeshellcmd($this->ffmpegBin),
            $offset,
            escapeshellarg($source),
            $width,
            $this->quality,
            escapeshellarg($dest)
        );

        $output = shell_exec($cmd);
        if (!is_readable($dest)) {
            throw new RuntimeException('Thumbnail generation failed: ' . trim((string) $output));
        }

        if (!$large) {
            $this->files->updateThumbnail($fileId, $this->cache->relativeThumbnailPath($fileId));
        }

        return $dest;
    }
}
