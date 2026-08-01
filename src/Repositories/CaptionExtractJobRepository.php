<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

final class CaptionExtractJobRepository extends BaseRepository
{
    /**
     * @param list<int>|null $fileIds
     */
    public function create(int $createdBy, string $scope = 'missing_srt', ?array $fileIds = null): int
    {
        if (!in_array($scope, ['missing_srt', 'has_captions', 'selected', 'probe_only'], true)) {
            $scope = 'missing_srt';
        }
        if ($scope === 'selected' && $fileIds !== null && $fileIds !== []) {
            $idsJson = json_encode(array_values(array_unique(array_map('intval', $fileIds))), JSON_THROW_ON_ERROR);
            $stmt = $this->db()->prepare(
                'INSERT INTO caption_extract_jobs (status, scope, file_ids, created_by)
                 VALUES (\'PENDING\', ?, ?::jsonb, ?)
                 RETURNING id'
            );
            $stmt->execute([$scope, $idsJson, $createdBy]);
        } else {
            $stmt = $this->db()->prepare(
                'INSERT INTO caption_extract_jobs (status, scope, file_ids, created_by)
                 VALUES (\'PENDING\', ?, NULL, ?)
                 RETURNING id'
            );
            $stmt->execute([$scope, $createdBy]);
        }

        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT j.*, u.email AS created_by_email,
                    f.original_filename AS current_original_filename,
                    f.duration_seconds AS current_duration_seconds
             FROM caption_extract_jobs j
             JOIN users u ON u.id = j.created_by
             LEFT JOIN files f ON f.id = j.current_file_id
             WHERE j.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 25): array
    {
        $stmt = $this->db()->prepare(
            'SELECT j.*, u.email AS created_by_email
             FROM caption_extract_jobs j
             JOIN users u ON u.id = j.created_by
             ORDER BY j.created_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function findRunning(): ?array
    {
        $row = $this->db()->query(
            "SELECT j.*, u.email AS created_by_email
             FROM caption_extract_jobs j
             JOIN users u ON u.id = j.created_by
             WHERE j.status = 'RUNNING'
             ORDER BY j.started_at ASC NULLS LAST
             LIMIT 1"
        )->fetch();

        return is_array($row) ? $row : null;
    }

    /** Active job (pending claim or running) — only one allowed. */
    public function findActive(): ?array
    {
        $row = $this->db()->query(
            "SELECT j.*, u.email AS created_by_email
             FROM caption_extract_jobs j
             JOIN users u ON u.id = j.created_by
             WHERE j.status IN ('PENDING', 'RUNNING')
             ORDER BY CASE j.status WHEN 'RUNNING' THEN 0 ELSE 1 END,
                      j.created_at ASC
             LIMIT 1"
        )->fetch();

        return is_array($row) ? $row : null;
    }

    public function tryClaim(int $id): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE caption_extract_jobs
             SET status = 'RUNNING',
                 started_at = COALESCE(started_at, now()),
                 error_message = NULL,
                 cancel_requested = false,
                 completed_at = NULL
             WHERE id = ?
               AND status IN ('PENDING', 'PAUSED', 'FAILED')
             RETURNING id"
        );
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Atomically claim the oldest runnable job for the daemon worker.
     */
    public function claimNextPending(): ?int
    {
        $stmt = $this->db()->query(
            "WITH next AS (
                SELECT id FROM caption_extract_jobs
                WHERE status IN ('PENDING', 'PAUSED')
                ORDER BY
                    CASE status
                        WHEN 'PENDING' THEN 0
                        ELSE 1
                    END,
                    created_at ASC
                LIMIT 1
                FOR UPDATE SKIP LOCKED
             )
             UPDATE caption_extract_jobs j
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
            "SELECT id, worker_pid FROM caption_extract_jobs
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
            "UPDATE caption_extract_jobs
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
            'UPDATE caption_extract_jobs SET worker_pid = ? WHERE id = ?'
        )->execute([$pid, $id]);
    }

    public function setTotals(int $id, int $totalFiles, float $totalDurationSeconds): void
    {
        $this->db()->prepare(
            'UPDATE caption_extract_jobs
             SET total_files = ?, total_duration_seconds = ?
             WHERE id = ?'
        )->execute([$totalFiles, $totalDurationSeconds, $id]);
    }

    public function setCurrent(int $id, ?int $fileId, ?string $filename): void
    {
        if ($fileId === null) {
            $this->db()->prepare(
                'UPDATE caption_extract_jobs
                 SET current_file_id = NULL,
                     current_filename = NULL,
                     current_started_at = NULL
                 WHERE id = ?'
            )->execute([$id]);

            return;
        }

        $this->db()->prepare(
            'UPDATE caption_extract_jobs
             SET current_file_id = ?,
                 current_filename = ?,
                 current_started_at = now()
             WHERE id = ?'
        )->execute([$fileId, $filename, $id]);
    }

    public function recordResult(
        int $id,
        string $outcome,
        float $durationSeconds,
        ?string $lastError = null
    ): void {
        $ok = $outcome === 'ok' ? 1 : 0;
        $fail = $outcome === 'fail' ? 1 : 0;
        $skip = $outcome === 'skip' ? 1 : 0;

        $this->db()->prepare(
            'UPDATE caption_extract_jobs
             SET processed_files = processed_files + 1,
                 ok_count = ok_count + ?,
                 fail_count = fail_count + ?,
                 skip_count = skip_count + ?,
                 processed_duration_seconds = processed_duration_seconds + ?,
                 last_error = COALESCE(?, last_error)
             WHERE id = ?'
        )->execute([$ok, $fail, $skip, max(0.0, $durationSeconds), $lastError, $id]);
    }

    public function isCancelRequested(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT cancel_requested FROM caption_extract_jobs WHERE id = ?'
        );
        $stmt->execute([$id]);

        return (bool) $stmt->fetchColumn();
    }

    public function requestCancel(int $id): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE caption_extract_jobs SET cancel_requested = true
             WHERE id = ? AND status IN ('PENDING', 'RUNNING', 'PAUSED')
             RETURNING id"
        );
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    public function markCompleted(int $id): void
    {
        $this->db()->prepare(
            "UPDATE caption_extract_jobs
             SET status = 'COMPLETED',
                 completed_at = now(),
                 current_file_id = NULL,
                 current_filename = NULL,
                 current_started_at = NULL,
                 worker_pid = NULL,
                 cancel_requested = false
             WHERE id = ?"
        )->execute([$id]);
    }

    public function markCancelled(int $id): void
    {
        $this->db()->prepare(
            "UPDATE caption_extract_jobs
             SET status = 'CANCELLED',
                 completed_at = now(),
                 current_file_id = NULL,
                 current_filename = NULL,
                 current_started_at = NULL,
                 worker_pid = NULL,
                 cancel_requested = false
             WHERE id = ?"
        )->execute([$id]);
    }

    public function markFailed(int $id, string $message): void
    {
        $this->db()->prepare(
            "UPDATE caption_extract_jobs
             SET status = 'FAILED',
                 error_message = ?,
                 last_error = ?,
                 completed_at = now(),
                 current_file_id = NULL,
                 current_filename = NULL,
                 current_started_at = NULL,
                 worker_pid = NULL
             WHERE id = ?"
        )->execute([$message, $message, $id]);
    }

    /** @return list<int> */
    public function getPriorityIds(int $id): array
    {
        $stmt = $this->db()->prepare(
            'SELECT priority_file_ids FROM caption_extract_jobs WHERE id = ?'
        );
        $stmt->execute([$id]);
        $raw = $stmt->fetchColumn();

        return $this->decodeIdList($raw);
    }

    /**
     * Prepend file IDs to the priority lane (selected order preserved; duplicates removed).
     *
     * @param list<int> $ids
     * @return list<int> new priority list
     */
    public function prependPriority(int $id, array $ids): array
    {
        $incoming = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $n): bool => $n > 0
        )));
        if ($incoming === []) {
            return $this->getPriorityIds($id);
        }

        $existing = $this->getPriorityIds($id);
        $existing = array_values(array_filter(
            $existing,
            static fn (int $n): bool => !in_array($n, $incoming, true)
        ));
        $merged = array_merge($incoming, $existing);
        $this->setPriorityIds($id, $merged);

        return $merged;
    }

    public function removeFromPriority(int $id, int $fileId): void
    {
        $remaining = array_values(array_filter(
            $this->getPriorityIds($id),
            static fn (int $n): bool => $n !== $fileId
        ));
        $this->setPriorityIds($id, $remaining);
    }

    /** @param list<int> $ids */
    public function setPriorityIds(int $id, array $ids): void
    {
        $clean = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $n): bool => $n > 0
        )));
        $json = json_encode($clean, JSON_THROW_ON_ERROR);
        $this->db()->prepare(
            'UPDATE caption_extract_jobs SET priority_file_ids = ?::jsonb WHERE id = ?'
        )->execute([$json, $id]);
    }

    /** @param mixed $raw @return list<int> */
    private function decodeIdList(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $raw),
            static fn (int $n): bool => $n > 0
        )));
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

    /**
     * Clear a zombie PENDING/RUNNING job so it can be deleted (worker dead).
     */
    public function forceAbandon(int $id, string $reason = 'Force-deleted: worker not running'): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE caption_extract_jobs
             SET status = 'CANCELLED',
                 error_message = COALESCE(error_message, ?),
                 last_error = COALESCE(last_error, ?),
                 completed_at = COALESCE(completed_at, now()),
                 current_file_id = NULL,
                 current_filename = NULL,
                 current_started_at = NULL,
                 worker_pid = NULL,
                 cancel_requested = false
             WHERE id = ?
               AND status IN ('PENDING', 'RUNNING', 'PAUSED')
             RETURNING id"
        );
        $stmt->execute([$reason, $reason, $id]);

        return $stmt->fetchColumn() !== false;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM caption_extract_jobs WHERE id = ?');

        return $stmt->execute([$id]);
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
