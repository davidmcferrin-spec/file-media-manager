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
            $this->pgBool($extractMetadata),
            $devFileList,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT sj.*, s.name AS source_name, s.mount_path, s.source_code, u.email AS created_by_email
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

    /**
     * Atomically claim the oldest runnable job for a worker without a specific ID.
     * Includes pending, paused, failed, and orphaned running jobs (dead worker).
     */
    public function claimNextPending(): ?int
    {
        $id = $this->claimFreshJob();
        if ($id !== null) {
            return $id;
        }

        return $this->claimOrphanedRunning();
    }

    private function claimFreshJob(): ?int
    {
        $stmt = $this->db()->query(
            'WITH next AS (
                SELECT id FROM scan_jobs
                WHERE status IN (\'PENDING\', \'PAUSED\', \'FAILED\')
                ORDER BY
                    CASE status
                        WHEN \'PENDING\' THEN 0
                        WHEN \'PAUSED\' THEN 1
                        ELSE 2
                    END,
                    created_at ASC
                LIMIT 1
                FOR UPDATE SKIP LOCKED
             )
             UPDATE scan_jobs sj
             SET status = \'RUNNING\',
                 started_at = now(),
                 processed_files = 0,
                 total_files = 0,
                 error_message = NULL,
                 cancel_requested = false
             FROM next
             WHERE sj.id = next.id
             RETURNING sj.id'
        );
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    private function claimOrphanedRunning(): ?int
    {
        $stmt = $this->db()->query(
            'SELECT id, worker_pid FROM scan_jobs
             WHERE status = \'RUNNING\'
             ORDER BY created_at ASC
             LIMIT 20'
        );
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            $id  = (int) $row['id'];
            $pid = (int) ($row['worker_pid'] ?? 0);
            if ($this->isProcessAlive($pid)) {
                continue;
            }

            $claim = $this->db()->prepare(
                'UPDATE scan_jobs
                 SET started_at = now(),
                     processed_files = 0,
                     total_files = 0,
                     error_message = NULL,
                     cancel_requested = false
                 WHERE id = ?
                   AND status = \'RUNNING\'
                 RETURNING id'
            );
            $claim->execute([$id]);
            if ($claim->fetchColumn() !== false) {
                return $id;
            }
        }

        return null;
    }

    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec('tasklist /FI "PID eq ' . $pid . '" 2>NUL', $output, $code);

            return $code === 0 && count($output) > 1;
        }

        exec('kill -0 ' . $pid . ' 2>/dev/null', $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * Claim a specific job started from the web UI or CLI --job-id flag.
     */
    public function tryClaim(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE scan_jobs
             SET status = \'RUNNING\',
                 started_at = now(),
                 processed_files = 0,
                 total_files = 0,
                 error_message = NULL
             WHERE id = ?
               AND status IN (\'PENDING\', \'PAUSED\', \'FAILED\')
             RETURNING id'
        );
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    public function setTotalFiles(int $id, int $totalFiles): void
    {
        $stmt = $this->db()->prepare(
            'UPDATE scan_jobs SET total_files = ? WHERE id = ?'
        );
        $stmt->execute([$totalFiles, $id]);
    }

    public function resetProgress(int $id): void
    {
        $this->db()->prepare(
            'UPDATE scan_jobs SET processed_files = 0, total_files = 0, error_message = NULL WHERE id = ?'
        )->execute([$id]);
    }

    /**
     * Reset a finished job so the worker can walk the tree again (full rescan).
     */
    public function prepareRescan(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE scan_jobs
             SET status = \'PENDING\',
                 cancel_requested = false,
                 worker_pid = NULL,
                 completed_at = NULL,
                 error_message = NULL,
                 processed_files = 0,
                 total_files = 0
             WHERE id = ?
               AND status IN (\'COMPLETED\', \'CANCELLED\', \'PAUSED\', \'FAILED\')
             RETURNING id'
        );
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
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
            'UPDATE scan_jobs SET status = \'COMPLETED\', completed_at = now(), worker_pid = NULL WHERE id = ?'
        )->execute([$id]);
    }

    public function markFailed(int $id, string $message): void
    {
        $stmt = $this->db()->prepare(
            'UPDATE scan_jobs SET status = \'FAILED\', error_message = ?, completed_at = now(), worker_pid = NULL WHERE id = ?'
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

    /**
     * Request stop for a pending or running job. Pending jobs are cancelled immediately.
     */
    public function requestCancel(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE scan_jobs
             SET cancel_requested = true,
                 status = CASE WHEN status = \'PENDING\' THEN \'CANCELLED\' ELSE status END,
                 completed_at = CASE WHEN status = \'PENDING\' THEN now() ELSE completed_at END,
                 error_message = CASE WHEN status = \'PENDING\' THEN NULL ELSE error_message END
             WHERE id = ?
               AND status IN (\'PENDING\', \'RUNNING\')
             RETURNING id'
        );
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    public function isCancelRequested(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT cancel_requested FROM scan_jobs WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();

        return in_array($value, [true, 't', 'true', '1', 1], true);
    }

    public function markCancelled(int $id): void
    {
        $this->db()->prepare(
            'UPDATE scan_jobs
             SET status = \'CANCELLED\',
                 cancel_requested = true,
                 completed_at = now(),
                 error_message = NULL,
                 worker_pid = NULL
             WHERE id = ?'
        )->execute([$id]);
    }

    public function markPaused(int $id): void
    {
        $this->db()->prepare(
            'UPDATE scan_jobs
             SET status = \'PAUSED\',
                 cancel_requested = false,
                 completed_at = NULL,
                 error_message = NULL,
                 worker_pid = NULL
             WHERE id = ?'
        )->execute([$id]);
    }

    /**
     * Stop a running scan — paused when partially complete, cancelled otherwise.
     *
     * @return 'PAUSED'|'CANCELLED'
     */
    public function markStopped(int $id): string
    {
        $job = $this->findById($id);
        if ($job === null) {
            $this->markCancelled($id);

            return 'CANCELLED';
        }

        $processed = (int) ($job['processed_files'] ?? 0);
        $total     = (int) ($job['total_files'] ?? 0);
        $resumable = $processed > 0 && ($total === 0 || $processed < $total);

        if ($resumable) {
            $this->markPaused($id);

            return 'PAUSED';
        }

        $this->markCancelled($id);

        return 'CANCELLED';
    }

    public function setWorkerPid(int $id, int $pid): void
    {
        $this->db()->prepare('UPDATE scan_jobs SET worker_pid = ? WHERE id = ?')->execute([$pid, $id]);
    }

    public function clearWorkerPid(int $id): void
    {
        $this->db()->prepare('UPDATE scan_jobs SET worker_pid = NULL WHERE id = ?')->execute([$id]);
    }

    public function getWorkerPid(int $id): ?int
    {
        $stmt = $this->db()->prepare('SELECT worker_pid FROM scan_jobs WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM scan_jobs WHERE id = ?');

        return $stmt->execute([$id]);
    }
}
