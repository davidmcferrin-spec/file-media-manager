<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\FileRepository;
use MediaManager\Services\MediaCacheService;
use RuntimeException;

final class RollbackService
{
    public function __construct(
        private readonly FileRepository $files = new FileRepository(),
        private readonly AuditRepository $audit = new AuditRepository(),
        private readonly MediaCacheService $cache = new MediaCacheService(),
    ) {
    }

    /**
     * @param list<int> $fileIds
     * @return array{succeeded: int, failed: int, errors: list<string>}
     */
    public function rollback(array $fileIds, int $userId, string $userEmail, string $ip): array
    {
        $result = ['succeeded' => 0, 'failed' => 0, 'errors' => []];

        foreach ($fileIds as $fileId) {
            try {
                $this->rollbackOne($fileId, $userId, $userEmail, $ip);
                $result['succeeded']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][] = sprintf('#%d: %s', $fileId, $e->getMessage());
            }
        }

        return $result;
    }

    private function rollbackOne(int $fileId, int $userId, string $userEmail, string $ip): void
    {
        $file = $this->files->findById($fileId);
        if ($file === null) {
            throw new RuntimeException('File not found.');
        }
        if (($file['status'] ?? '') !== 'EXECUTED') {
            throw new RuntimeException('File is not in EXECUTED status.');
        }

        $executedPath = (string) ($file['executed_path'] ?? '');
        $originalPath = (string) $file['original_path'];

        if ($executedPath === '' || !file_exists($executedPath)) {
            throw new RuntimeException('Executed file no longer at expected path.');
        }
        if (file_exists($originalPath)) {
            throw new RuntimeException('Original path already occupied — cannot rollback safely.');
        }

        $originalDir = dirname($originalPath);
        if (!is_dir($originalDir) && !mkdir($originalDir, 0775, true) && !is_dir($originalDir)) {
            throw new RuntimeException('Cannot recreate original directory.');
        }

        $this->audit->record(
            $userId,
            $userEmail,
            $ip,
            'ROLLBACK_PENDING',
            'file',
            $fileId,
            $executedPath,
            $originalPath,
            ['phase' => 'before']
        );

        if (!rename($executedPath, $originalPath)) {
            throw new RuntimeException('Failed to move file back to original location.');
        }

        $this->rollbackSidecars($file, $userId, $userEmail, $ip);

        $this->files->markRolledBack($fileId);
        $this->cache->invalidate($fileId);

        $this->audit->record(
            $userId,
            $userEmail,
            $ip,
            'FILE_ROLLED_BACK',
            'file',
            $fileId,
            $executedPath,
            $originalPath,
            []
        );
    }

    /** @param array<string, mixed> $file */
    private function rollbackSidecars(array $file, int $userId, string $userEmail, string $ip): void
    {
        $sidecars = FileRepository::parseSidecars($file['classifier_notes'] ?? null);
        if ($sidecars === []) {
            return;
        }

        $executedDir = dirname((string) $file['executed_path']);
        $originalDir = (string) $file['original_dir'];

        foreach ($sidecars as $sidecar) {
            $from = $executedDir . '/' . $sidecar['proposed_filename'];
            $to   = $originalDir . '/' . basename($sidecar['original_path']);

            if (!file_exists($from)) {
                continue;
            }
            if (file_exists($to)) {
                continue;
            }
            if (@rename($from, $to)) {
                $this->audit->record(
                    $userId,
                    $userEmail,
                    $ip,
                    'SIDECAR_ROLLED_BACK',
                    'file',
                    (int) $file['id'],
                    $from,
                    $to,
                    []
                );
            }
        }
    }
}
