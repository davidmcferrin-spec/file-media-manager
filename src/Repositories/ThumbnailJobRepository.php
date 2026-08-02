<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

use PDOException;

final class ThumbnailJobRepository extends BaseRepository
{
    public const SIZE_DEFAULT = 'default';
    public const SIZE_LARGE = 'large';

    /** @return list<string> */
    public static function sizes(): array
    {
        return [self::SIZE_DEFAULT, self::SIZE_LARGE];
    }

    public function create(int $fileId, string $size, ?int $createdBy = null): int
    {
        if (!in_array($size, self::sizes(), true)) {
            throw new \InvalidArgumentException('Invalid thumbnail size: ' . $size);
        }

        $stmt = $this->db()->prepare(
            "INSERT INTO thumbnail_jobs (file_id, size, status, created_by)
             VALUES (?, ?, 'PENDING', ?)
             RETURNING id"
        );
        $stmt->execute([$fileId, $size, $createdBy]);

        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT j.*, f.original_filename, f.original_path, f.duration_seconds
             FROM thumbnail_jobs j
             JOIN files f ON f.id = j.file_id
             WHERE j.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function findActiveForFile(int $fileId, string $size): ?array
    {
        $stmt = $this->db()->prepare(
            "SELECT j.*
             FROM thumbnail_jobs j
             WHERE j.file_id = ?
               AND j.size = ?
               AND j.status IN ('PENDING', 'RUNNING')
             ORDER BY CASE j.status WHEN 'RUNNING' THEN 0 ELSE 1 END,
                      j.created_at ASC
             LIMIT 1"
        );
        $stmt->execute([$fileId, $size]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function tryClaim(int $id): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE thumbnail_jobs
             SET status = 'RUNNING',
                 started_at = COALESCE(started_at, now()),
                 error_message = NULL,
                 cancel_requested = false,
                 completed_at = NULL,
                 worker_pid = NULL
             WHERE id = ?
               AND status IN ('PENDING', 'FAILED')
             RETURNING id"
        );
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    public function claimNextPending(): ?int
    {
        $stmt = $this->db()->query(
            "WITH next AS (
                SELECT j.id
                FROM thumbnail_jobs j
                WHERE j.status = 'PENDING'
                ORDER BY j.created_at ASC
                LIMIT 1
                FOR UPDATE OF j SKIP LOCKED
             )
             UPDATE thumbnail_jobs j
             SET status = 'RUNNING',
                 started_at = COALESCE(j.started_at, now()),
                 error_message = NULL,
                 cancel_requested = false,
                 completed_at = NULL,
                 worker_pid = NULL
             FROM next
             WHERE j.id = next.id
             RETURNING j.id"
        );
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        return $this->claimNextOrphanedRunning();
    }

    private function claimNextOrphanedRunning(): ?int
    {
        $rows = $this->db()->query(
            "SELECT id, worker_pid FROM thumbnail_jobs
             WHERE status = 'RUNNING'
             ORDER BY created_at ASC
             LIMIT 20"
        )->fetchAll();
        if (!is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $pid = (int) ($row['worker_pid'] ?? 0);
            if ($this->pidAlive($pid)) {
                continue;
            }
            if ($this->claimOrphanedRunning($id)) {
                return $id;
            }
        }

        return null;
    }

    public function claimOrphanedRunning(int $id): bool
    {
        $job = $this->findById($id);
        if ($job === null || ($job['status'] ?? '') !== 'RUNNING') {
            return false;
        }
        $pid = isset($job['worker_pid']) ? (int) $job['worker_pid'] : 0;
        if ($pid > 0 && $this->pidAlive($pid)) {
            return false;
        }

        $stmt = $this->db()->prepare(
            "UPDATE thumbnail_jobs
             SET status = 'RUNNING',
                 worker_pid = NULL,
                 error_message = NULL,
                 cancel_requested = false
             WHERE id = ? AND status = 'RUNNING'
             RETURNING id"
        );
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    public function setWorkerPid(int $id, int $pid): void
    {
        $this->db()->prepare(
            'UPDATE thumbnail_jobs SET worker_pid = ? WHERE id = ?'
        )->execute([$pid, $id]);
    }

    public function isCancelRequested(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT cancel_requested FROM thumbnail_jobs WHERE id = ?'
        );
        $stmt->execute([$id]);

        return (bool) $stmt->fetchColumn();
    }

    public function markCompleted(int $id): void
    {
        $this->db()->prepare(
            "UPDATE thumbnail_jobs
             SET status = 'COMPLETED',
                 completed_at = now(),
                 worker_pid = NULL,
                 error_message = NULL,
                 cancel_requested = false
             WHERE id = ?"
        )->execute([$id]);
    }

    public function markFailed(int $id, string $message): void
    {
        $this->db()->prepare(
            "UPDATE thumbnail_jobs
             SET status = 'FAILED',
                 completed_at = now(),
                 worker_pid = NULL,
                 error_message = ?,
                 cancel_requested = false
             WHERE id = ?"
        )->execute([mb_substr($message, 0, 2000), $id]);
    }

    public function markCancelled(int $id, string $message = ''): void
    {
        $this->db()->prepare(
            "UPDATE thumbnail_jobs
             SET status = 'CANCELLED',
                 completed_at = now(),
                 worker_pid = NULL,
                 error_message = NULLIF(?, ''),
                 cancel_requested = false
             WHERE id = ?"
        )->execute([mb_substr($message, 0, 2000), $id]);
    }

    public function isUniqueViolation(PDOException $e): bool
    {
        return $e->getCode() === '23505'
            || str_contains($e->getMessage(), 'thumbnail_jobs_active_file_size_key');
    }

    private function pidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec('tasklist /FI "PID eq ' . $pid . '" 2>NUL', $out);

            return implode("\n", $out) !== '' && str_contains(implode("\n", $out), (string) $pid);
        }
        exec('kill -0 ' . $pid . ' 2>/dev/null', $out, $code);

        return $code === 0;
    }
}
