<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\FileRepository;
use MediaManager\Repositories\SystemRepository;
use RuntimeException;

final class ThumbnailService
{
    private string $ffmpegBin;
    private string $storageDir;
    private int $offsetSeconds;
    private int $width;
    private int $quality;

    public function __construct(
        private readonly FileRepository $files = new FileRepository(),
        private readonly SystemRepository $settings = new SystemRepository(),
    ) {
        $this->ffmpegBin     = (string) env('FFMPEG_BIN', '/usr/bin/ffmpeg');
        $projectRoot         = dirname(__DIR__, 2);
        $this->storageDir    = $projectRoot . '/' . trim((string) env('STORAGE_THUMBNAILS', 'storage/thumbnails'), '/');
        $this->offsetSeconds = (int) ($settings->get('thumbnail_offset_seconds') ?? env('THUMBNAIL_OFFSET_SECONDS', 50));
        $this->width         = (int) env('THUMBNAIL_WIDTH', 320);
        $this->quality       = (int) env('THUMBNAIL_QUALITY', 5);
    }

    public function pathForFileId(int $fileId): string
    {
        return $this->storageDir . '/' . $fileId . '.jpg';
    }

    public function ensureThumbnail(int $fileId): string
    {
        $file = $this->files->findById($fileId);
        if ($file === null) {
            throw new RuntimeException('File not found.');
        }

        $dest = $this->pathForFileId($fileId);
        if (is_readable($dest)) {
            return $dest;
        }

        $source = (string) $file['original_path'];
        if (!is_readable($source)) {
            throw new RuntimeException('Source file not readable for thumbnail.');
        }

        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0775, true)) {
            throw new RuntimeException('Cannot create thumbnail directory.');
        }

        $offset = $this->offsetSeconds;
        if (!empty($file['duration_seconds']) && (float) $file['duration_seconds'] < $offset) {
            $offset = max(1, (int) floor((float) $file['duration_seconds'] / 2));
        }

        $cmd = sprintf(
            '%s -hide_banner -loglevel error -ss %d -i %s -vframes 1 -vf scale=%d:-1 -q:v %d -y %s 2>&1',
            escapeshellcmd($this->ffmpegBin),
            $offset,
            escapeshellarg($source),
            $this->width,
            $this->quality,
            escapeshellarg($dest)
        );

        $output = shell_exec($cmd);
        if (!is_readable($dest)) {
            throw new RuntimeException('Thumbnail generation failed: ' . trim((string) $output));
        }

        $relative = 'storage/thumbnails/' . $fileId . '.jpg';
        $this->files->updateThumbnail($fileId, $relative);

        return $dest;
    }
}
