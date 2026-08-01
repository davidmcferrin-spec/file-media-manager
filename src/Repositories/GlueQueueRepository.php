<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

use PDOException;

final class GlueQueueRepository extends BaseRepository
{
    /** @return list<array<string, mixed>> */
    public function all(?string $status = null, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT gq.*,
                       u.email AS created_by_email,
                       qc.email AS qc_by_email,
                       of.original_filename AS output_filename,
                       of.status AS output_status
                FROM glue_queue gq
                JOIN users u ON u.id = gq.created_by
                LEFT JOIN users qc ON qc.id = gq.qc_by
                LEFT JOIN files of ON of.id = gq.output_file_id';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' WHERE gq.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY gq.created_at DESC LIMIT ? OFFSET ?';
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
            return (int) $this->db()->query('SELECT COUNT(*) FROM glue_queue')->fetchColumn();
        }
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM glue_queue WHERE status = ?');
        $stmt->execute([$status]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, int> */
    public function statusCounts(): array
    {
        $counts = [
            'PENDING'      => 0,
            'RUNNING'      => 0,
            'READY_FOR_QC' => 0,
            'APPROVED'     => 0,
            'DONE'         => 0,
            'FAILED'       => 0,
            'CANCELLED'    => 0,
        ];
        $rows = $this->db()->query('SELECT status, COUNT(*) AS cnt FROM glue_queue GROUP BY status')->fetchAll();
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
            'SELECT gq.*,
                    u.email AS created_by_email,
                    qc.email AS qc_by_email,
                    of.original_filename AS output_filename,
                    of.original_path AS output_original_path,
                    of.status AS output_status,
                    of.duration_seconds AS output_file_duration
             FROM glue_queue gq
             JOIN users u ON u.id = gq.created_by
             LEFT JOIN users qc ON qc.id = gq.qc_by
             LEFT JOIN files of ON of.id = gq.output_file_id
             WHERE gq.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function findActiveByGroupKey(string $groupKey): ?array
    {
        $stmt = $this->db()->prepare(
            "SELECT * FROM glue_queue
             WHERE glue_group_key = ?
               AND status IN ('PENDING', 'RUNNING', 'READY_FOR_QC', 'APPROVED')
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $stmt->execute([$groupKey]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, array<string, mixed>> keyed by glue_group_key
     */
    public function activeJobsByGroupKey(): array
    {
        $rows = $this->db()->query(
            "SELECT gq.*, of.original_filename AS output_filename
             FROM glue_queue gq
             LEFT JOIN files of ON of.id = gq.output_file_id
             WHERE gq.status IN ('PENDING', 'RUNNING', 'READY_FOR_QC', 'APPROVED')
             ORDER BY gq.created_at DESC"
        )->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row['glue_group_key'] ?? '');
            if ($key === '' || isset($out[$key])) {
                continue;
            }
            $out[$key] = $row;
        }

        return $out;
    }

    /**
     * @param list<int> $sourceFileIds
     */
    public function create(
        string $glueGroupKey,
        array $sourceFileIds,
        int $createdBy,
        ?float $expectedDuration = null,
        string $notes = ''
    ): int {
        $stmt = $this->db()->prepare(
            'INSERT INTO glue_queue (
                glue_group_key, source_file_ids, expected_duration_seconds, notes, created_by, status
             ) VALUES (?, ?, ?, ?, ?, \'PENDING\')
             RETURNING id'
        );
        $stmt->execute([
            $glueGroupKey,
            json_encode(array_values(array_map('intval', $sourceFileIds)), JSON_THROW_ON_ERROR),
            $expectedDuration,
            trim($notes),
            $createdBy,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function markRunning(int $id): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE glue_queue SET
                status = 'RUNNING',
                started_at = COALESCE(started_at, now()),
                error_message = '',
                completed_at = NULL
             WHERE id = ? AND status IN ('PENDING', 'FAILED')"
        );

        return $stmt->execute([$id]) && $stmt->rowCount() > 0;
    }

    public function markReadyForQc(
        int $id,
        string $outputPath,
        int $outputFileId,
        ?float $outputDuration,
        ?int $outputFilesize
    ): bool {
        $stmt = $this->db()->prepare(
            "UPDATE glue_queue SET
                status = 'READY_FOR_QC',
                output_path = ?,
                output_file_id = ?,
                output_duration_seconds = ?,
                output_filesize_bytes = ?,
                error_message = '',
                completed_at = NULL
             WHERE id = ? AND status = 'RUNNING'"
        );

        return $stmt->execute([
            $outputPath,
            $outputFileId,
            $outputDuration,
            $outputFilesize,
            $id,
        ]) && $stmt->rowCount() > 0;
    }

    public function markFailed(int $id, string $errorMessage): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE glue_queue SET
                status = 'FAILED',
                error_message = ?,
                completed_at = now()
             WHERE id = ?"
        );

        return $stmt->execute([trim($errorMessage), $id]) && $stmt->rowCount() > 0;
    }

    public function markApproved(int $id, int $qcBy): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE glue_queue SET
                status = 'APPROVED',
                qc_by = ?,
                qc_at = now(),
                error_message = ''
             WHERE id = ? AND status = 'READY_FOR_QC'"
        );

        return $stmt->execute([$qcBy, $id]) && $stmt->rowCount() > 0;
    }

    public function markDone(int $id): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE glue_queue SET
                status = 'DONE',
                sources_deleted_at = now(),
                completed_at = now(),
                error_message = ''
             WHERE id = ? AND status = 'APPROVED'"
        );

        return $stmt->execute([$id]) && $stmt->rowCount() > 0;
    }

    public function markCancelled(int $id): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE glue_queue SET
                status = 'CANCELLED',
                completed_at = now()
             WHERE id = ? AND status IN ('PENDING', 'FAILED', 'READY_FOR_QC')"
        );

        return $stmt->execute([$id]) && $stmt->rowCount() > 0;
    }

    public function clearOutputRef(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE glue_queue SET
                output_path = NULL,
                output_file_id = NULL,
                output_duration_seconds = NULL,
                output_filesize_bytes = NULL
             WHERE id = ?'
        );

        return $stmt->execute([$id]);
    }

    public function resetToPending(int $id): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE glue_queue SET
                status = 'PENDING',
                started_at = NULL,
                completed_at = NULL,
                qc_by = NULL,
                qc_at = NULL,
                sources_deleted_at = NULL,
                output_path = NULL,
                output_file_id = NULL,
                output_duration_seconds = NULL,
                output_filesize_bytes = NULL,
                error_message = ''
             WHERE id = ? AND status IN ('FAILED', 'CANCELLED')"
        );

        return $stmt->execute([$id]) && $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM glue_queue WHERE id = ?');

        return $stmt->execute([$id]) && $stmt->rowCount() > 0;
    }

    /** @return list<int> */
    public function parseSourceIds(array|string|null $raw): array
    {
        if (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = json_decode((string) ($raw ?? '[]'), true);
        }
        if (!is_array($decoded)) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    public function isUniqueViolation(PDOException $e): bool
    {
        return str_contains($e->getMessage(), 'glue_queue_active_group_key');
    }
}
