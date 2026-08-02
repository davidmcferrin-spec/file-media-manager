<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

use PDOException;

final class SplitAudioJobRepository extends BaseRepository
{
    public const KIND_LEVELS = 'levels';
    public const KIND_SUGGEST = 'suggest';

    /** @return list<string> */
    public static function kinds(): array
    {
        return [self::KIND_LEVELS, self::KIND_SUGGEST];
    }

    public function create(int $splitQueueId, int $fileId, string $kind, int $createdBy): int
    {
        if (!in_array($kind, self::kinds(), true)) {
            throw new \InvalidArgumentException('Invalid split audio job kind: ' . $kind);
        }

        $stmt = $this->db()->prepare(
            "INSERT INTO split_audio_jobs (split_queue_id, file_id, kind, status, created_by)
             VALUES (?, ?, ?, 'PENDING', ?)
             RETURNING id"
        );
        $stmt->execute([$splitQueueId, $fileId, $kind, $createdBy]);

        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT j.*, u.email AS created_by_email,
                    f.original_filename, f.original_path, f.duration_seconds,
                    f.codec_audio, f.file_date, f.file_time
             FROM split_audio_jobs j
             JOIN users u ON u.id = j.created_by
             JOIN files f ON f.id = j.file_id
             WHERE j.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** Latest job for a split workbench item (any status). */
    public function latestForSplitQueue(int $splitQueueId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT j.*, u.email AS created_by_email
             FROM split_audio_jobs j
             JOIN users u ON u.id = j.created_by
             WHERE j.split_queue_id = ?
             ORDER BY j.created_at DESC, j.id DESC
             LIMIT 1'
        );
        $stmt->execute([$splitQueueId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** Active job for a source file (pending claim or running). */
    public function findActiveForFile(int $fileId): ?array
    {
        $stmt = $this->db()->prepare(
            "SELECT j.*
             FROM split_audio_jobs j
             WHERE j.file_id = ?
               AND j.status IN ('PENDING', 'RUNNING')
             ORDER BY CASE j.status WHEN 'RUNNING' THEN 0 ELSE 1 END,
                      j.created_at ASC
             LIMIT 1"
        );
        $stmt->execute([$fileId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function tryClaim(int $id): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE split_audio_jobs
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
        // Prioritize files without usable captions (no SRT), then oldest job.
        $stmt = $this->db()->query(
            "WITH next AS (
                SELECT j.id
                FROM split_audio_jobs j
                JOIN files f ON f.id = j.file_id
                WHERE j.status = 'PENDING'
                ORDER BY CASE
                    WHEN f.srt_path IS NULL OR TRIM(f.srt_path) = '' THEN 0
                    ELSE 1
                END,
                j.created_at ASC
                LIMIT 1
                FOR UPDATE OF j SKIP LOCKED
             )
             UPDATE split_audio_jobs j
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
            "SELECT id, worker_pid FROM split_audio_jobs
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
            "UPDATE split_audio_jobs
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
            'UPDATE split_audio_jobs SET worker_pid = ? WHERE id = ?'
        )->execute([$pid, $id]);
    }

    public function isCancelRequested(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT cancel_requested FROM split_audio_jobs WHERE id = ?'
        );
        $stmt->execute([$id]);

        return (bool) $stmt->fetchColumn();
    }

    public function requestCancel(int $id): bool
    {
        // PENDING never claimed — cancel immediately (claim would clear the flag).
        $stmt = $this->db()->prepare(
            "UPDATE split_audio_jobs
             SET status = 'CANCELLED',
                 completed_at = now(),
                 worker_pid = NULL,
                 cancel_requested = false,
                 error_message = 'Cancelled before worker claimed the job'
             WHERE id = ? AND status = 'PENDING'
             RETURNING id"
        );
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }

        $stmt = $this->db()->prepare(
            "UPDATE split_audio_jobs SET cancel_requested = true
             WHERE id = ? AND status = 'RUNNING'
             RETURNING id"
        );
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    public function markCompleted(int $id, string $summary = ''): void
    {
        $this->db()->prepare(
            "UPDATE split_audio_jobs
             SET status = 'COMPLETED',
                 completed_at = now(),
                 worker_pid = NULL,
                 cancel_requested = false,
                 result_summary = ?,
                 error_message = NULL
             WHERE id = ?"
        )->execute([trim($summary), $id]);
    }

    public function markCancelled(int $id, string $message = ''): void
    {
        $this->db()->prepare(
            "UPDATE split_audio_jobs
             SET status = 'CANCELLED',
                 completed_at = now(),
                 worker_pid = NULL,
                 cancel_requested = false,
                 error_message = NULLIF(?, '')
             WHERE id = ?"
        )->execute([trim($message), $id]);
    }

    public function markFailed(int $id, string $message): void
    {
        $this->db()->prepare(
            "UPDATE split_audio_jobs
             SET status = 'FAILED',
                 completed_at = now(),
                 worker_pid = NULL,
                 cancel_requested = false,
                 error_message = ?
             WHERE id = ?"
        )->execute([trim($message), $id]);
    }

    public function isWorkerAlive(int $id): bool
    {
        $job = $this->findById($id);
        if ($job === null) {
            return false;
        }
        $pid = isset($job['worker_pid']) ? (int) $job['worker_pid'] : 0;

        return $this->pidAlive($pid);
    }

    public function isUniqueViolation(PDOException $e): bool
    {
        return str_contains($e->getMessage(), 'split_audio_jobs_active_file_key');
    }

    private function pidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $out = [];
            exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL', $out);

            return implode(' ', $out) !== '' && !str_contains(strtolower(implode(' ', $out)), 'no tasks');
        }

        return function_exists('posix_kill') ? @posix_kill($pid, 0) : false;
    }
}
