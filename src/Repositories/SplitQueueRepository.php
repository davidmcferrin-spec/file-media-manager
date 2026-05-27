<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

use PDOException;

final class SplitQueueRepository extends BaseRepository
{
    /** @return list<array<string, mixed>> */
    public function all(?string $status = null, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT sq.*,
                       f.original_path, f.original_filename, f.duration_seconds,
                       f.split_notes, f.proposed_dir, f.proposed_filename,
                       sh.abbreviation AS show_abbr,
                       u.email AS created_by_email
                FROM split_queue sq
                JOIN files f ON f.id = sq.file_id
                LEFT JOIN shows sh ON sh.id = f.show_id
                JOIN users u ON u.id = sq.created_by';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' WHERE sq.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY sq.created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db()->prepare($sql);
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function count(?string $status = null): int
    {
        if ($status === null || $status === '') {
            return (int) $this->db()->query('SELECT COUNT(*) FROM split_queue')->fetchColumn();
        }
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM split_queue WHERE status = ?');
        $stmt->execute([$status]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, int> */
    public function statusCounts(): array
    {
        $counts = ['PENDING' => 0, 'IN_PROGRESS' => 0, 'DONE' => 0, 'FAILED' => 0];
        $rows = $this->db()->query('SELECT status, COUNT(*) AS cnt FROM split_queue GROUP BY status')->fetchAll();
        if (!is_array($rows)) {
            return $counts;
        }
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['cnt'];
        }

        return $counts;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT sq.*,
                    f.original_path, f.original_filename, f.original_dir,
                    f.duration_seconds, f.split_notes, f.proposed_dir, f.proposed_filename,
                    f.show_id, sh.abbreviation AS show_abbr,
                    u.email AS created_by_email
             FROM split_queue sq
             JOIN files f ON f.id = sq.file_id
             LEFT JOIN shows sh ON sh.id = f.show_id
             JOIN users u ON u.id = sq.created_by
             WHERE sq.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function hasActiveForFile(int $fileId): bool
    {
        $stmt = $this->db()->prepare(
            "SELECT 1 FROM split_queue WHERE file_id = ? AND status IN ('PENDING', 'IN_PROGRESS') LIMIT 1"
        );
        $stmt->execute([$fileId]);

        return $stmt->fetchColumn() !== false;
    }

    public function create(int $fileId, int $createdBy, string $notes = ''): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO split_queue (file_id, notes, created_by, status)
             VALUES (?, ?, ?, \'PENDING\')
             RETURNING id'
        );
        $stmt->execute([$fileId, trim($notes), $createdBy]);

        return (int) $stmt->fetchColumn();
    }

    /** @param list<array<string, mixed>> $segments */
    public function update(int $id, array $segments, string $notes, string $status): bool
    {
        $params = [
            json_encode(array_values($segments), JSON_THROW_ON_ERROR),
            trim($notes),
            $status,
            $id,
        ];

        if (in_array($status, ['DONE', 'FAILED'], true)) {
            $stmt = $this->db()->prepare(
                'UPDATE split_queue SET
                    segments = ?,
                    notes = ?,
                    status = ?,
                    completed_at = now()
                 WHERE id = ?'
            );
        } else {
            $stmt = $this->db()->prepare(
                'UPDATE split_queue SET
                    segments = ?,
                    notes = ?,
                    status = ?,
                    completed_at = NULL
                 WHERE id = ?'
            );
        }

        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM split_queue WHERE id = ?');

        return $stmt->execute([$id]);
    }

    /** @return list<int> */
    public function deleteActiveForFile(int $fileId): array
    {
        $stmt = $this->db()->prepare(
            "DELETE FROM split_queue
             WHERE file_id = ? AND status IN ('PENDING', 'IN_PROGRESS')
             RETURNING id"
        );
        $stmt->execute([$fileId]);
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /** @return list<array<string, mixed>> */
    public function splittableFiles(int $limit = 50): array
    {
        $stmt = $this->db()->prepare(
            "SELECT f.*, sh.abbreviation AS show_abbr
             FROM files f
             LEFT JOIN shows sh ON sh.id = f.show_id
             WHERE f.needs_split IS TRUE
               AND f.status IN ('PENDING', 'FLAGGED', 'APPROVED')
               AND NOT EXISTS (
                   SELECT 1 FROM split_queue sq
                   WHERE sq.file_id = f.id AND sq.status IN ('PENDING', 'IN_PROGRESS')
               )
             ORDER BY f.duration_seconds DESC NULLS LAST
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function isUniqueViolation(PDOException $e): bool
    {
        return str_contains($e->getMessage(), 'split_queue_active_file_key');
    }
}
