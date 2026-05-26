<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

final class FileRepository extends BaseRepository
{
    public function existsByOriginalPath(string $path): bool
    {
        $stmt = $this->db()->prepare('SELECT 1 FROM files WHERE original_path = ? LIMIT 1');
        $stmt->execute([$path]);

        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO files (
                scan_job_id, source_id, original_path, original_dir, original_filename,
                proposed_dir, proposed_filename, show_id, media_type_id,
                file_date, file_time, confidence, classifier_notes, status,
                duration_seconds, filesize_bytes, container, codec_video, codec_audio,
                resolution, framerate, metadata_extracted, needs_split, split_notes
             ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?
             )
             RETURNING id'
        );

        $stmt->execute([
            $data['scan_job_id'],
            $data['source_id'],
            $data['original_path'],
            $data['original_dir'],
            $data['original_filename'],
            $data['proposed_dir'],
            $data['proposed_filename'],
            $data['show_id'],
            $data['media_type_id'],
            $data['file_date'],
            $data['file_time'],
            $data['confidence'],
            $data['classifier_notes'],
            $data['status'] ?? 'PENDING',
            $data['duration_seconds'],
            $data['filesize_bytes'],
            $data['container'],
            $data['codec_video'],
            $data['codec_audio'],
            $data['resolution'],
            $data['framerate'],
            $this->pgBool((bool) ($data['metadata_extracted'] ?? false)),
            $this->pgBool((bool) ($data['needs_split'] ?? false)),
            $data['split_notes'] ?? '',
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT f.*, sh.abbreviation AS show_abbr, sh.canonical_name AS show_name,
                    mt.name AS media_type_name, mt.abbreviation AS media_type_abbr,
                    s.mount_path AS source_mount, s.name AS source_name
             FROM files f
             LEFT JOIN shows sh ON sh.id = f.show_id
             LEFT JOIN media_types mt ON mt.id = f.media_type_id
             LEFT JOIN sources s ON s.id = f.source_id
             WHERE f.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function listQueue(array $filters, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->queueWhereClause($filters);

        $sql = 'SELECT f.*, sh.abbreviation AS show_abbr, mt.name AS media_type_name,
                       s.name AS source_name
                FROM files f
                LEFT JOIN shows sh ON sh.id = f.show_id
                LEFT JOIN media_types mt ON mt.id = f.media_type_id
                LEFT JOIN sources s ON s.id = f.source_id
                WHERE ' . $where . '
                ORDER BY f.confidence ASC, f.created_at DESC, f.id DESC
                LIMIT ? OFFSET ?';

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

    /** @param array<string, mixed> $filters */
    public function countQueue(array $filters): int
    {
        [$where, $params] = $this->queueWhereClause($filters);
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM files f WHERE ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, int> */
    public function statusCounts(): array
    {
        $rows = $this->db()->query(
            'SELECT status, COUNT(*) AS cnt FROM files GROUP BY status'
        )->fetchAll();

        $counts = [
            'PENDING' => 0, 'APPROVED' => 0, 'REJECTED' => 0,
            'FLAGGED' => 0, 'EXECUTED' => 0, 'ROLLED_BACK' => 0,
        ];
        if (!is_array($rows)) {
            return $counts;
        }
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /** @param array<string, mixed> $filters */
    /** @return array{0: string, 1: list<mixed>} */
    private function queueWhereClause(array $filters): array
    {
        $clauses = ['1=1'];
        $params  = [];

        if (!empty($filters['status'])) {
            $clauses[] = 'f.status = ?';
            $params[]  = (string) $filters['status'];
        }
        if (!empty($filters['confidence'])) {
            $clauses[] = 'f.confidence = ?';
            $params[]  = (string) $filters['confidence'];
        }
        if (!empty($filters['scan_job_id'])) {
            $clauses[] = 'f.scan_job_id = ?';
            $params[]  = (int) $filters['scan_job_id'];
        }
        if (!empty($filters['show_id'])) {
            $clauses[] = 'f.show_id = ?';
            $params[]  = (int) $filters['show_id'];
        }
        if (!empty($filters['needs_split'])) {
            $clauses[] = 'f.needs_split IS TRUE';
        }
        if (!empty($filters['search'])) {
            $clauses[] = '(f.original_filename ILIKE ? OR f.original_path ILIKE ? OR f.proposed_filename ILIKE ?)';
            $term = '%' . (string) $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        return [implode(' AND ', $clauses), $params];
    }

    public function updateStatus(int $id, string $status, int $userId): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE files SET status = ?, reviewed_by = ?, reviewed_at = now() WHERE id = ?'
        );

        return $stmt->execute([$status, $userId, $id]);
    }

    public function updateProposed(
        int $id,
        ?string $proposedDir,
        ?string $proposedFilename,
        ?int $showId,
        ?int $mediaTypeId,
        ?string $fileDate,
        ?string $fileTime
    ): bool {
        $stmt = $this->db()->prepare(
            'UPDATE files SET
                proposed_dir = ?,
                proposed_filename = ?,
                show_id = ?,
                media_type_id = ?,
                file_date = ?,
                file_time = ?
             WHERE id = ?'
        );

        return $stmt->execute([
            $proposedDir,
            $proposedFilename,
            $showId,
            $mediaTypeId,
            $fileDate,
            $fileTime,
            $id,
        ]);
    }

    public function markExecuted(int $id, string $executedPath, int $userId): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE files SET
                status = \'EXECUTED\',
                executed_path = ?,
                executed_by = ?,
                executed_at = now()
             WHERE id = ?'
        );

        return $stmt->execute([$executedPath, $userId, $id]);
    }

    public function markRolledBack(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE files SET status = \'ROLLED_BACK\', executed_path = NULL WHERE id = ?'
        );

        return $stmt->execute([$id]);
    }

    public function updateThumbnail(int $id, string $thumbnailPath): void
    {
        $stmt = $this->db()->prepare(
            'UPDATE files SET thumbnail_path = ?, thumbnail_at = now() WHERE id = ?'
        );
        $stmt->execute([$thumbnailPath, $id]);
    }

    public function clearThumbnailPath(int $id): void
    {
        $this->db()->prepare(
            'UPDATE files SET thumbnail_path = NULL, thumbnail_at = NULL WHERE id = ?'
        )->execute([$id]);
    }

    /**
     * Best path for reading media from disk (post-execute uses executed_path).
     *
     * @param array<string, mixed> $file
     */
    public static function mediaSourcePath(array $file): string
    {
        $executed = (string) ($file['executed_path'] ?? '');
        if ($executed !== '' && is_readable($executed)) {
            return $executed;
        }

        return (string) ($file['original_path'];
    }

    /**
     * @param array<string, mixed> $file
     */
    public static function displayPath(array $file): string
    {
        $executed = (string) ($file['executed_path'] ?? '');
        if ($executed !== '') {
            return $executed;
        }

        return (string) ($file['original_path'];
    }

    /** @param list<int> $ids */
    public function findApprovedByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db()->prepare(
            "SELECT f.*, s.mount_path AS source_mount
             FROM files f
             JOIN sources s ON s.id = f.source_id
             WHERE f.id IN ({$placeholders}) AND f.status = 'APPROVED'"
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @return list<array<string, mixed>> */
    public function allApproved(int $limit = 500): array
    {
        $stmt = $this->db()->prepare(
            "SELECT f.*, s.mount_path AS source_mount
             FROM files f
             JOIN sources s ON s.id = f.source_id
             WHERE f.status = 'APPROVED'
               AND f.proposed_dir IS NOT NULL
               AND f.proposed_filename IS NOT NULL
             ORDER BY f.id ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @return list<array<string, mixed>> */
    public function recentlyExecuted(int $limit = 50): array
    {
        $stmt = $this->db()->prepare(
            "SELECT f.*, s.mount_path AS source_mount, s.name AS source_name
             FROM files f
             JOIN sources s ON s.id = f.source_id
             WHERE f.status = 'EXECUTED' AND f.executed_path IS NOT NULL
             ORDER BY f.executed_at DESC NULLS LAST
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @return list<array<string, mixed>> */
    public function byScanJob(int $scanJobId, int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->db()->prepare(
            'SELECT f.*, sh.abbreviation AS show_abbr, mt.name AS media_type_name
             FROM files f
             LEFT JOIN shows sh ON sh.id = f.show_id
             LEFT JOIN media_types mt ON mt.id = f.media_type_id
             WHERE f.scan_job_id = ?
             ORDER BY f.id ASC
             LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $scanJobId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function countByScanJob(int $scanJobId): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM files WHERE scan_job_id = ?');
        $stmt->execute([$scanJobId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, int> */
    public function confidenceSummary(int $scanJobId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT confidence, COUNT(*) AS cnt FROM files WHERE scan_job_id = ? GROUP BY confidence'
        );
        $stmt->execute([$scanJobId]);
        $summary = ['HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $summary[(string) $row['confidence']] = (int) $row['cnt'];
        }

        return $summary;
    }

    public function countProtectedByScanJob(int $scanJobId): int
    {
        $stmt = $this->db()->prepare(
            "SELECT COUNT(*) FROM files
             WHERE scan_job_id = ?
               AND status IN ('APPROVED', 'EXECUTED', 'ROLLED_BACK')"
        );
        $stmt->execute([$scanJobId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<int> */
    public function idsByScanJob(int $scanJobId): array
    {
        $stmt = $this->db()->prepare('SELECT id FROM files WHERE scan_job_id = ?');
        $stmt->execute([$scanJobId]);
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    public function deleteByScanJob(int $scanJobId): int
    {
        $this->db()->prepare(
            'DELETE FROM split_queue
             WHERE file_id IN (SELECT id FROM files WHERE scan_job_id = ?)'
        )->execute([$scanJobId]);

        $stmt = $this->db()->prepare('DELETE FROM files WHERE scan_job_id = ?');
        $stmt->execute([$scanJobId]);

        return $stmt->rowCount();
    }

    /** @return list<array{original_path: string, proposed_filename: string}> */
    public static function parseSidecars(?string $classifierNotes): array
    {
        if ($classifierNotes === null || $classifierNotes === '') {
            return [];
        }
        $data = json_decode($classifierNotes, true);
        if (!is_array($data) || !isset($data['sidecars']) || !is_array($data['sidecars'])) {
            return [];
        }
        $sidecars = [];
        foreach ($data['sidecars'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $path = (string) ($entry['original_path'] ?? '');
            $name = (string) ($entry['proposed_filename'] ?? '');
            if ($path !== '' && $name !== '') {
                $sidecars[] = ['original_path' => $path, 'proposed_filename' => $name];
            }
        }

        return $sidecars;
    }
}
