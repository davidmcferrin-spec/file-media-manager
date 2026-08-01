<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\FileRepository;
use MediaManager\Support\Ulid;

/**
 * App-owned derived media cache under STORAGE_MEDIA, sharded by file public_id (ULID).
 *
 * Layout: {STORAGE_MEDIA}/aa/bb/cc/{ulid}/thumb.jpg|preview.webm|split/...
 * NAS originals and deliverable video outputs are never stored here.
 */
final class MediaCacheService
{
    private string $mediaRoot;
    private string $mediaRootRel;
    private string $legacyThumbnailDir;
    private string $legacyPreviewDir;
    private string $legacySplitProxyDir;

    /** @var array<int, string> */
    private array $publicIdCache = [];

    public function __construct(
        private readonly FileRepository $files = new FileRepository(),
        ?string $projectRoot = null,
    ) {
        $root = $projectRoot ?? dirname(__DIR__, 2);
        $this->mediaRootRel = trim((string) env('STORAGE_MEDIA', 'storage/media'), '/');
        $this->mediaRoot = $root . '/' . $this->mediaRootRel;
        $this->legacyThumbnailDir = $root . '/' . trim((string) env('STORAGE_THUMBNAILS', 'storage/thumbnails'), '/');
        $this->legacyPreviewDir = $root . '/' . trim((string) env('STORAGE_PREVIEWS', 'storage/previews'), '/');
        $this->legacySplitProxyDir = $root . '/' . trim((string) env('STORAGE_SPLIT_PROXY', 'storage/split-proxy'), '/');
    }

    public function mediaRoot(): string
    {
        return $this->mediaRoot;
    }

    public function mediaRootRelative(): string
    {
        return $this->mediaRootRel;
    }

    public function publicIdFor(int $fileId): string
    {
        if (isset($this->publicIdCache[$fileId])) {
            return $this->publicIdCache[$fileId];
        }

        $publicId = $this->files->ensurePublicId($fileId);
        $this->publicIdCache[$fileId] = $publicId;

        return $publicId;
    }

    /** Absolute asset directory for a file. */
    public function assetDir(int $fileId): string
    {
        return $this->assetDirForPublicId($this->publicIdFor($fileId));
    }

    public function assetDirForPublicId(string $publicId): string
    {
        return $this->mediaRoot . '/' . Ulid::shardPath($publicId);
    }

    /** Project-relative asset directory, e.g. storage/media/01/J8/X9/{ulid} */
    public function relativeAssetDir(int $fileId): string
    {
        return $this->mediaRootRel . '/' . Ulid::shardPath($this->publicIdFor($fileId));
    }

    public function relativeAssetDirForPublicId(string $publicId): string
    {
        return $this->mediaRootRel . '/' . Ulid::shardPath($publicId);
    }

    /** Canonical write/read path (sharded). */
    public function thumbnailPath(int $fileId): string
    {
        return $this->assetDir($fileId) . '/thumb.jpg';
    }

    public function thumbnailLargePath(int $fileId): string
    {
        return $this->assetDir($fileId) . '/thumb.large.jpg';
    }

    public function relativeThumbnailPath(int $fileId): string
    {
        return $this->relativeAssetDir($fileId) . '/thumb.jpg';
    }

    /** Prefer sharded thumb; fall back to pre-ULID flat storage. */
    public function resolveThumbnailPath(int $fileId, bool $large = false): ?string
    {
        $sharded = $large ? $this->thumbnailLargePath($fileId) : $this->thumbnailPath($fileId);
        if (is_readable($sharded)) {
            return $sharded;
        }

        if ($large) {
            $legacyLarge = $this->legacyThumbnailDir . '/' . $fileId . '.jpg.large.jpg';
            if (is_readable($legacyLarge)) {
                return $legacyLarge;
            }

            return null;
        }

        $legacy = $this->legacyThumbnailDir . '/' . $fileId . '.jpg';
        if (is_readable($legacy)) {
            return $legacy;
        }

        return null;
    }

    public function previewPath(int $fileId): string
    {
        return $this->assetDir($fileId) . '/preview.webm';
    }

    public function resolvePreviewPath(int $fileId): ?string
    {
        $sharded = $this->previewPath($fileId);
        if (is_readable($sharded) && filesize($sharded) > 0) {
            return $sharded;
        }

        $legacy = $this->legacyPreviewDir . '/' . $fileId . '_av.webm';
        if (is_readable($legacy) && filesize($legacy) > 0) {
            return $legacy;
        }

        $legacyOld = $this->legacyPreviewDir . '/' . $fileId . '.webm';
        if (is_readable($legacyOld) && filesize($legacyOld) > 0) {
            return $legacyOld;
        }

        return null;
    }

    /** @deprecated Legacy flat root; new split cache lives under assetDir()/split. */
    public function splitProxyDir(): string
    {
        return $this->legacySplitProxyDir;
    }

    public function splitFramePath(int $fileId, int $bucketTenths): string
    {
        return $this->assetDir($fileId) . '/split/frame_' . $bucketTenths . '.jpg';
    }

    public function resolveSplitFramePath(int $fileId, int $bucketTenths): ?string
    {
        $sharded = $this->splitFramePath($fileId, $bucketTenths);
        if (is_readable($sharded) && filesize($sharded) > 0) {
            return $sharded;
        }

        $legacy = $this->legacySplitProxyDir . '/' . $fileId . '/frame_' . $bucketTenths . '.jpg';
        if (is_readable($legacy) && filesize($legacy) > 0) {
            return $legacy;
        }

        return null;
    }

    public function splitSegmentPath(int $fileId, int $startSec, int $durationSec, string $mode): string
    {
        $safeMode = preg_replace('/[^a-z0-9_-]/i', '', $mode) ?? 'proxy';

        return $this->assetDir($fileId) . '/split/seg_' . $startSec . '_' . $durationSec . '_' . $safeMode . '.mp4';
    }

    public function resolveSplitSegmentPath(int $fileId, int $startSec, int $durationSec, string $mode): ?string
    {
        $sharded = $this->splitSegmentPath($fileId, $startSec, $durationSec, $mode);
        if (is_readable($sharded) && filesize($sharded) > 0) {
            return $sharded;
        }

        $safeMode = preg_replace('/[^a-z0-9_-]/i', '', $mode) ?? 'proxy';
        $legacy = $this->legacySplitProxyDir . '/' . $fileId
            . '/seg_' . $startSec . '_' . $durationSec . '_' . $safeMode . '.mp4';
        if (is_readable($legacy) && filesize($legacy) > 0) {
            return $legacy;
        }

        return null;
    }

    public function invalidate(int $fileId): void
    {
        $publicId = null;
        try {
            $publicId = $this->publicIdFor($fileId);
        } catch (\Throwable) {
            // File may already be gone; still clear legacy paths.
        }

        if ($publicId !== null) {
            $dir = $this->assetDirForPublicId($publicId);
            if (is_dir($dir)) {
                $this->removeTree($dir);
            }
            unset($this->publicIdCache[$fileId]);
        }

        $thumb = $this->legacyThumbnailDir . '/' . $fileId . '.jpg';
        if (is_file($thumb)) {
            @unlink($thumb);
        }

        $legacyLarge = $this->legacyThumbnailDir . '/' . $fileId . '.jpg.large.jpg';
        if (is_file($legacyLarge)) {
            @unlink($legacyLarge);
        }

        $preview = $this->legacyPreviewDir . '/' . $fileId . '_av.webm';
        if (is_file($preview)) {
            @unlink($preview);
        }

        $legacyPreview = $this->legacyPreviewDir . '/' . $fileId . '.webm';
        if (is_file($legacyPreview)) {
            @unlink($legacyPreview);
        }

        $splitDir = $this->legacySplitProxyDir . '/' . $fileId;
        if (is_dir($splitDir)) {
            $this->removeTree($splitDir);
        }

        $this->files->clearThumbnailPath($fileId);
    }

    public function ensurePreviewDir(): void
    {
        $this->ensureMediaRoot();
    }

    public function ensureAssetDir(int $fileId): string
    {
        $dir = $this->assetDir($fileId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create media asset directory.');
        }

        return $dir;
    }

    public function ensureSplitProxyDir(int $fileId): void
    {
        $dir = $this->assetDir($fileId) . '/split';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create split proxy directory.');
        }
    }

    private function ensureMediaRoot(): void
    {
        if (!is_dir($this->mediaRoot) && !mkdir($this->mediaRoot, 0775, true) && !is_dir($this->mediaRoot)) {
            throw new \RuntimeException('Cannot create media storage root.');
        }
    }

    private function removeTree(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            $path = $fileInfo->getPathname();
            if ($fileInfo->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
