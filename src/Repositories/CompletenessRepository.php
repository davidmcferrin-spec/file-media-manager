<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

final class CompletenessRepository extends BaseRepository
{
    /**
     * Program/Clean evidence files with usable show + date + time.
     * Excludes REJECTED. Combined across all sources.
     *
     * @return list<array<string, mixed>>
     */
    public function listEvidence(string $fromYmd, string $toYmd, ?int $showId = null): array
    {
        $sql = 'SELECT f.id, f.show_id, f.media_type_id, f.file_date, f.file_time,
                       f.duration_seconds, f.filesize_bytes, f.needs_split, f.split_notes,
                       f.original_path, f.original_filename, f.proposed_filename, f.status,
                       f.confidence, f.source_id,
                       mt.abbreviation AS media_type_abbr, mt.name AS media_type_name,
                       sh.abbreviation AS show_abbr, sh.canonical_name AS show_name,
                       s.name AS source_name
                FROM files f
                JOIN media_types mt ON mt.id = f.media_type_id
                JOIN shows sh ON sh.id = f.show_id
                JOIN sources s ON s.id = f.source_id
                WHERE f.status <> \'REJECTED\'
                  AND f.show_id IS NOT NULL
                  AND f.file_date IS NOT NULL AND f.file_date <> \'\'
                  AND f.file_time IS NOT NULL AND f.file_time <> \'\'
                  AND lower(mt.abbreviation) IN (\'program\', \'clean\')
                  AND f.file_date >= ? AND f.file_date <= ?';
        $params = [$fromYmd, $toYmd];

        if ($showId !== null && $showId > 0) {
            $sql .= ' AND f.show_id = ?';
            $params[] = $showId;
        }

        $sql .= ' ORDER BY f.file_date ASC, f.file_time ASC, f.id ASC';

        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * Files missing show, date, or time — cannot place on the Timeline audit.
     * (ISO/GISO with full metadata are identified; they simply don't fill Program/Clean.)
     *
     * @return list<array<string, mixed>>
     */
    public function listUnmatched(int $limit = 100, int $offset = 0, ?string $search = null): array
    {
        $sql = 'SELECT f.id, f.show_id, f.media_type_id, f.file_date, f.file_time,
                       f.duration_seconds, f.filesize_bytes, f.needs_split,
                       f.original_path, f.original_filename, f.proposed_filename, f.status,
                       f.confidence,
                       mt.abbreviation AS media_type_abbr, mt.name AS media_type_name,
                       sh.abbreviation AS show_abbr, sh.canonical_name AS show_name,
                       s.name AS source_name
                FROM files f
                LEFT JOIN media_types mt ON mt.id = f.media_type_id
                LEFT JOIN shows sh ON sh.id = f.show_id
                LEFT JOIN sources s ON s.id = f.source_id
                WHERE f.status <> \'REJECTED\'
                  AND (
                    f.show_id IS NULL
                    OR f.file_date IS NULL OR f.file_date = \'\'
                    OR f.file_time IS NULL OR f.file_time = \'\'
                  )';
        $params = [];

        if ($search !== null && $search !== '') {
            $sql .= ' AND (f.original_filename ILIKE ? OR f.original_path ILIKE ? OR f.proposed_filename ILIKE ?)';
            $term = '%' . $search . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $sql .= ' ORDER BY f.id DESC LIMIT ? OFFSET ?';
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

    public function countUnmatched(?string $search = null): int
    {
        $sql = 'SELECT COUNT(*)
                FROM files f
                WHERE f.status <> \'REJECTED\'
                  AND (
                    f.show_id IS NULL
                    OR f.file_date IS NULL OR f.file_date = \'\'
                    OR f.file_time IS NULL OR f.file_time = \'\'
                  )';
        $params = [];

        if ($search !== null && $search !== '') {
            $sql .= ' AND (f.original_filename ILIKE ? OR f.original_path ILIKE ? OR f.proposed_filename ILIKE ?)';
            $term = '%' . $search . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
