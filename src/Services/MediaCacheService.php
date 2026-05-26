<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\FileRepository;

final class MediaCacheService
{
    private string $thumbnailDir;
    private string $previewDir;

    public function __construct(
        private readonly FileRepository $files = new FileRepository(),
        ?string $projectRoot = null,
    ) {
        $root = $projectRoot ?? dirname(__DIR__, 2);
        $this->thumbnailDir = $root . '/' . trim((string) env('STORAGE_THUMBNAILS', 'storage/thumbnails'), '/');
        $this->previewDir   = $root . '/' . trim((string) env('STORAGE_PREVIEWS', 'storage/previews'), '/');
    }

    public function thumbnailPath(int $fileId): string
    {
        return $this->thumbnailDir . '/' . $fileId . '.jpg';
    }

    public function previewPath(int $fileId): string
    {
        return $this->previewDir . '/' . $fileId . '.webm';
    }

    public function invalidate(int $fileId): void
    {
        $thumb = $this->thumbnailPath($fileId);
        if (is_file($thumb)) {
            @unlink($thumb);
        }

        $preview = $this->previewPath($fileId);
        if (is_file($preview)) {
            @unlink($preview);
        }

        $this->files->clearThumbnailPath($fileId);
    }

    public function ensurePreviewDir(): void
    {
        if (!is_dir($this->previewDir) && !mkdir($this->previewDir, 0775, true)) {
            throw new \RuntimeException('Cannot create preview directory.');
        }
    }
}
