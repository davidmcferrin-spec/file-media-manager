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

    public function findByOriginalPath(string $path): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT f.*, s.mount_path AS source_mount
             FROM files f
             LEFT JOIN sources s ON s.id = f.source_id
             WHERE f.original_path = ?
             LIMIT 1'
        );
        $stmt->execute([$path]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
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
                resolution, framerate, metadata_extracted, needs_split, split_notes,
                classifier_confidence, classifier_proposed_dir, classifier_proposed_filename,
                proposed_source
             ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?
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
            $data['classifier_confidence'] ?? $data['confidence'],
            $data['classifier_proposed_dir'] ?? $data['proposed_dir'],
            $data['classifier_proposed_filename'] ?? $data['proposed_filename'],
            $data['proposed_source'] ?? 'classifier',
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

    /** Reset APPROVED → PENDING (clears review stamp). */
    public function unapprove(int $id): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE files SET status = 'PENDING', reviewed_by = NULL, reviewed_at = NULL
             WHERE id = ? AND status = 'APPROVED'"
        );

        return $stmt->execute([$id]) && $stmt->rowCount() > 0;
    }

    /**
     * Delete a queue row that has not been executed / rolled back.
     * Removes related split_queue rows first.
     */
    public function deleteRemovable(int $id): bool
    {
        $file = $this->findById($id);
        if ($file === null) {
            return false;
        }
        if (!in_array((string) ($file['status'] ?? ''), ['PENDING', 'FLAGGED', 'REJECTED', 'APPROVED'], true)) {
            return false;
        }

        $this->db()->prepare('DELETE FROM split_queue WHERE file_id = ?')->execute([$id]);
        $stmt = $this->db()->prepare('DELETE FROM files WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Full classifier overwrite for reclassify (resets review + dual-proposal state).
     *
     * @param array<string, mixed> $data
     */
    public function updateClassification(int $id, array $data): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE files SET
                proposed_dir = ?,
                proposed_filename = ?,
                show_id = ?,
                media_type_id = ?,
                file_date = ?,
                file_time = ?,
                confidence = ?,
                classifier_confidence = ?,
                classifier_proposed_dir = ?,
                classifier_proposed_filename = ?,
                proposed_source = 'classifier',
                classifier_notes = ?,
                needs_split = ?,
                split_notes = ?,
                status = 'PENDING',
                reviewed_by = NULL,
                reviewed_at = NULL,
                alt_proposed_dir = NULL,
                alt_proposed_filename = NULL,
                alt_source = NULL,
                legacy_map_id = NULL,
                map_curator_confidence = NULL,
                proposal_agreement = NULL
             WHERE id = ?
               AND status IN ('PENDING', 'FLAGGED', 'REJECTED')"
        );

        return $stmt->execute([
            $data['proposed_dir'] ?? null,
            $data['proposed_filename'] ?? null,
            $data['show_id'] ?? null,
            $data['media_type_id'] ?? null,
            $data['file_date'] ?? null,
            $data['file_time'] ?? null,
            $data['confidence'] ?? 'LOW',
            $data['confidence'] ?? 'LOW',
            $data['proposed_dir'] ?? null,
            $data['proposed_filename'] ?? null,
            $data['classifier_notes'] ?? '{}',
            $this->pgBool((bool) ($data['needs_split'] ?? false)),
            $data['split_notes'] ?? '',
            $id,
        ]) && $stmt->rowCount() > 0;
    }

    public function countReclassifiableByScanJob(int $scanJobId): int
    {
        $stmt = $this->db()->prepare(
            "SELECT COUNT(*) FROM files
             WHERE scan_job_id = ?
               AND status IN ('PENDING', 'FLAGGED', 'REJECTED')"
        );
        $stmt->execute([$scanJobId]);

        return (int) $stmt->fetchColumn();
    }

    public function updateProposed(
        int $id,
        ?string $proposedDir,
        ?string $proposedFilename,
        ?int $showId,
        ?int $mediaTypeId,
        ?string $fileDate,
        ?string $fileTime,
        ?bool $needsSplit = null,
        ?string $splitNotes = null
    ): bool {
        if ($needsSplit !== null) {
            $stmt = $this->db()->prepare(
                'UPDATE files SET
                    proposed_dir = ?,
                    proposed_filename = ?,
                    show_id = ?,
                    media_type_id = ?,
                    file_date = ?,
                    file_time = ?,
                    needs_split = ?,
                    split_notes = ?
                 WHERE id = ?'
            );

            return $stmt->execute([
                $proposedDir,
                $proposedFilename,
                $showId,
                $mediaTypeId,
                $fileDate,
                $fileTime,
                $this->pgBool($needsSplit),
                $splitNotes ?? '',
                $id,
            ]);
        }

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

    public function updateSplitFlag(int $id, bool $needsSplit, string $splitNotes = ''): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE files SET needs_split = ?, split_notes = ? WHERE id = ?'
        );

        return $stmt->execute([$this->pgBool($needsSplit), trim($splitNotes), $id]);
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

        $original = (string) ($file['original_path'] ?? '');
        if ($original !== '' && is_readable($original)) {
            return $original;
        }

        $mount = rtrim((string) ($file['source_mount'] ?? ''), '/');
        $filename = (string) ($file['original_filename'] ?? basename($original));
        $originalDir = (string) ($file['original_dir'] ?? '');

        if ($mount !== '' && $filename !== '') {
            foreach (self::mediaSourcePathCandidates($mount, $originalDir, $filename) as $candidate) {
                if (is_readable($candidate)) {
                    return $candidate;
                }
            }
        }

        return $original;
    }

    /**
     * @return list<string>
     */
    private static function mediaSourcePathCandidates(
        string $currentMount,
        string $originalDir,
        string $filename
    ): array {
        $currentMount = rtrim($currentMount, '/');
        $candidates   = [];

        if ($originalDir !== '') {
            $relativeDir = self::relativeDirUnderMount($originalDir, $currentMount);
            if ($relativeDir !== null) {
                $candidates[] = $currentMount
                    . ($relativeDir !== '' ? '/' . $relativeDir : '')
                    . '/'
                    . $filename;
            }
        }

        return array_values(array_unique($candidates));
    }

    private static function relativeDirUnderMount(string $originalDir, string $currentMount): ?string
    {
        $currentMount = rtrim(str_replace('\\', '/', $currentMount), '/');
        $originalDir  = rtrim(str_replace('\\', '/', $originalDir), '/');

        if ($originalDir === $currentMount) {
            return '';
        }

        if (str_starts_with($originalDir, $currentMount . '/')) {
            return substr($originalDir, strlen($currentMount) + 1);
        }

        $mountBase = basename($currentMount);
        $parts     = explode('/', trim($originalDir, '/'));
        $idx       = array_search($mountBase, $parts, true);
        if ($idx !== false) {
            $tail = array_slice($parts, $idx + 1);

            return implode('/', $tail);
        }

        if (count($parts) > 3) {
            return implode('/', array_slice($parts, 3));
        }

        return null;
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

        return (string) ($file['original_path'] ?? '');
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
            'SELECT f.*, sh.abbreviation AS show_abbr, sh.canonical_name AS show_name,
                    mt.name AS media_type_name, mt.abbreviation AS media_type_abbr
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

    public function ensureClassifierSnapshot(int $id): void
    {
        $this->db()->prepare(
            'UPDATE files SET
                classifier_confidence = COALESCE(classifier_confidence, confidence),
                classifier_proposed_dir = COALESCE(classifier_proposed_dir, proposed_dir),
                classifier_proposed_filename = COALESCE(classifier_proposed_filename, proposed_filename),
                proposed_source = COALESCE(proposed_source, \'classifier\')
             WHERE id = ?'
        )->execute([$id]);
    }

    /** @param array<string, mixed> $result */
    public function updateProposalReconciliation(int $id, array $result): bool
    {
        $file = $this->findById($id);
        if ($file === null) {
            return false;
        }

        $notes = self::mergeReconcileNotes((string) ($file['classifier_notes'] ?? ''), $result);

        $sql = 'UPDATE files SET
            classifier_confidence = COALESCE(classifier_confidence, ?),
            classifier_proposed_dir = COALESCE(classifier_proposed_dir, ?),
            classifier_proposed_filename = COALESCE(classifier_proposed_filename, ?),
            proposed_dir = ?,
            proposed_filename = ?,
            confidence = ?,
            proposed_source = ?,
            alt_proposed_dir = ?,
            alt_proposed_filename = ?,
            alt_source = ?,
            legacy_map_id = ?,
            map_curator_confidence = ?,
            proposal_agreement = ?,
            classifier_notes = ?';

        $params = [
            $result['classifier_confidence'] ?? $file['confidence'],
            $result['classifier_proposed_dir'] ?? $file['proposed_dir'],
            $result['classifier_proposed_filename'] ?? $file['proposed_filename'],
            $result['proposed_dir'],
            $result['proposed_filename'],
            $result['confidence'],
            $result['proposed_source'],
            $result['alt_proposed_dir'],
            $result['alt_proposed_filename'],
            $result['alt_source'],
            $result['legacy_map_id'],
            $result['map_curator_confidence'],
            $result['proposal_agreement'],
            $notes,
        ];

        if (!empty($result['show_id'])) {
            $sql .= ', show_id = ?';
            $params[] = $result['show_id'];
        }
        if (!empty($result['media_type_id'])) {
            $sql .= ', media_type_id = ?';
            $params[] = $result['media_type_id'];
        }
        if (!empty($result['file_date'])) {
            $sql .= ', file_date = ?';
            $params[] = $result['file_date'];
        }
        if (!empty($result['file_time'])) {
            $sql .= ', file_time = ?';
            $params[] = $result['file_time'];
        }
        if (!empty($result['status'])) {
            $sql .= ', status = ?';
            $params[] = $result['status'];
        }

        $sql .= ' WHERE id = ?';
        $params[] = $id;

        $stmt = $this->db()->prepare($sql);

        return $stmt->execute($params);
    }

    public function adoptProposalSource(int $id, string $source): bool
    {
        $file = $this->findById($id);
        if ($file === null) {
            return false;
        }

        if ($source === 'legacy_map') {
            $dir = $file['alt_proposed_dir'] ?? null;
            $name = $file['alt_proposed_filename'] ?? null;
            if ($dir === null || $name === null) {
                return false;
            }
            $altDir = $file['proposed_dir'];
            $altName = $file['proposed_filename'];
            $altSource = $file['proposed_source'];
        } elseif ($source === 'classifier') {
            $dir = $file['classifier_proposed_dir'] ?? $file['proposed_dir'];
            $name = $file['classifier_proposed_filename'] ?? $file['proposed_filename'];
            $altDir = $file['alt_proposed_dir'];
            $altName = $file['alt_proposed_filename'];
            $altSource = 'legacy_map';
        } else {
            return false;
        }

        $stmt = $this->db()->prepare(
            'UPDATE files SET
                proposed_dir = ?,
                proposed_filename = ?,
                proposed_source = ?,
                alt_proposed_dir = ?,
                alt_proposed_filename = ?,
                alt_source = ?
             WHERE id = ?'
        );

        return $stmt->execute([$dir, $name, $source, $altDir, $altName, $altSource, $id]);
    }

    /** @param array<string, mixed> $result */
    private static function mergeReconcileNotes(string $existingJson, array $result): string
    {
        $data = json_decode($existingJson, true);
        if (!is_array($data)) {
            $data = ['signals' => [], 'sidecars' => [], 'guest' => null, 'exact' => false];
        }
        $signals = is_array($data['signals'] ?? null) ? $data['signals'] : [];
        foreach ($result['reconcile_signals'] ?? [] as $signal) {
            if (is_string($signal) && $signal !== '') {
                $signals[] = $signal;
            }
        }
        $data['signals'] = array_values(array_unique($signals));
        $data['map'] = [
            'agreement'           => $result['proposal_agreement'] ?? null,
            'curator_confidence'  => $result['map_curator_confidence'] ?? null,
            'legacy_map_id'       => $result['legacy_map_id'] ?? null,
        ];

        return json_encode($data, JSON_THROW_ON_ERROR);
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
