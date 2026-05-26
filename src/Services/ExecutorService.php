<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\FileRepository;
use RuntimeException;

final class ExecutorService
{
    public function __construct(
        private readonly FileRepository $files = new FileRepository(),
        private readonly AuditRepository $audit = new AuditRepository(),
    ) {
    }

    /**
     * @param list<int>|null $fileIds null = all approved
     * @return array{succeeded: int, failed: int, errors: list<string>}
     */
    public function executeApproved(?array $fileIds, int $userId, string $userEmail, string $ip): array
    {
        $queue = $fileIds !== null && $fileIds !== []
            ? $this->files->findApprovedByIds($fileIds)
            : $this->files->allApproved();

        $result = ['succeeded' => 0, 'failed' => 0, 'errors' => []];

        foreach ($queue as $file) {
            try {
                $this->executeOne($file, $userId, $userEmail, $ip);
                $result['succeeded']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][] = sprintf(
                    '#%d %s: %s',
                    (int) $file['id'],
                    (string) $file['original_filename'],
                    $e->getMessage()
                );
                error_log('[execute] ' . $result['errors'][array_key_last($result['errors'])]);
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $file */
    private function executeOne(array $file, int $userId, string $userEmail, string $ip): void
    {
        $id = (int) $file['id'];
        $originalPath = (string) $file['original_path'];
        $mountPath    = rtrim((string) ($file['source_mount'] ?? ''), '/');
        $proposedDir  = (string) ($file['proposed_dir'] ?? '');
        $proposedName = (string) ($file['proposed_filename'] ?? '');

        if ($mountPath === '' || $proposedDir === '' || $proposedName === '') {
            throw new RuntimeException('Missing target path or mount.');
        }

        if (!is_readable($originalPath)) {
            throw new RuntimeException('Source file not found or not readable.');
        }

        $targetDir  = $mountPath . '/' . $proposedDir;
        $targetPath = $targetDir . '/' . $proposedName;

        if (file_exists($targetPath)) {
            throw new RuntimeException('Target already exists: ' . $targetPath);
        }

        $this->audit->record(
            $userId,
            $userEmail,
            $ip,
            'FILE_EXECUTE_PENDING',
            'file',
            $id,
            $originalPath,
            $targetPath,
            ['phase' => 'before']
        );

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Cannot create target directory: ' . $targetDir);
        }

        if (!rename($originalPath, $targetPath)) {
            throw new RuntimeException('Failed to move file on disk.');
        }

        $sidecarErrors = $this->moveSidecars($file, $targetDir, $userId, $userEmail, $ip);

        $this->files->markExecuted($id, $targetPath, $userId);

        $this->audit->record(
            $userId,
            $userEmail,
            $ip,
            'FILE_EXECUTED',
            'file',
            $id,
            $originalPath,
            $targetPath,
            ['sidecar_errors' => $sidecarErrors]
        );
    }

    /**
     * @param array<string, mixed> $file
     * @return list<string>
     */
    private function moveSidecars(array $file, string $targetDir, int $userId, string $userEmail, string $ip): array
    {
        $errors  = [];
        $sidecars = FileRepository::parseSidecars($file['classifier_notes'] ?? null);

        foreach ($sidecars as $sidecar) {
            $from = $sidecar['original_path'];
            $to   = $targetDir . '/' . $sidecar['proposed_filename'];

            if (!is_readable($from)) {
                $errors[] = 'Sidecar missing: ' . basename($from);
                continue;
            }
            if (file_exists($to)) {
                $errors[] = 'Sidecar target exists: ' . basename($to);
                continue;
            }
            if (!rename($from, $to)) {
                $errors[] = 'Sidecar move failed: ' . basename($from);
                continue;
            }

            $this->audit->record(
                $userId,
                $userEmail,
                $ip,
                'SIDECAR_EXECUTED',
                'file',
                (int) $file['id'],
                $from,
                $to,
                []
            );
        }

        return $errors;
    }
}
