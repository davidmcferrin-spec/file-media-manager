<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

final class ScanJobRepository extends BaseRepository
{
    public function create(int $sourceId, int $createdBy, string $subpath = '', bool $extractMetadata = true, ?string $devFileList = null): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO scan_jobs (source_id, status, created_by, subpath, extract_metadata, dev_file_list)
             VALUES (?, \'PENDING\', ?, ?, ?, ?)
             RETURNING id'
        );
        $stmt->execute([
            $sourceId,
            $createdBy,
            trim($subpath, '/'),
            $extractMetadata,
            $devFileList,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT sj.*, s.name AS source_name, s.mount_path, u.email AS created_by_email
             FROM scan_jobs sj
             JOIN sources s ON s.id = sj.source_id
             JOIN users u ON u.id = sj.created_by
             WHERE sj.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 20): array
    {
        $stmt = $this->db()->prepare(
            'SELECT sj.*, s.name AS source_name, u.email AS created_by_email
             FROM scan_jobs sj
             JOIN sources s ON s.id = sj.source_id
             JOIN users u ON u.id = sj.created_by
             ORDER BY sj.created_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function markRunning(int $id, int $totalFiles): void
    {
        $stmt = $this->db()->prepare(
            'UPDATE scan_jobs SET status = \'RUNNING\', total_files = ?, processed_files = 0,
             started_at = now(), error_message = NULL WHERE id = ?'
        );
        $stmt->execute([$totalFiles, $id]);
    }

    public function incrementProcessed(int $id): void
    {
        $this->db()->prepare(
            'UPDATE scan_jobs SET processed_files = processed_files + 1 WHERE id = ?'
        )->execute([$id]);
    }

    public function markCompleted(int $id): void
    {
        $this->db()->prepare(
            'UPDATE scan_jobs SET status = \'COMPLETED\', completed_at = now() WHERE id = ?'
        )->execute([$id]);
    }

    public function markFailed(int $id, string $message): void
    {
        $stmt = $this->db()->prepare(
            'UPDATE scan_jobs SET status = \'FAILED\', error_message = ?, completed_at = now() WHERE id = ?'
        );
        $stmt->execute([$message, $id]);
    }

    public function findPending(): ?array
    {
        $row = $this->db()->query(
            'SELECT * FROM scan_jobs WHERE status = \'PENDING\' ORDER BY created_at ASC LIMIT 1'
        )->fetch();

        return is_array($row) ? $row : null;
    }
}
