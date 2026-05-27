<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

final class LegacyRenameMapRepository extends BaseRepository
{
    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO legacy_rename_map (
                source_label, match_path, match_filename,
                target_dir, target_filename,
                show_id, show_abbr, media_type_id, media_type_label,
                curator_confidence, row_type, notes, active
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             RETURNING id'
        );
        $stmt->execute([
            $data['source_label'],
            $data['match_path'],
            $data['match_filename'],
            $data['target_dir'],
            $data['target_filename'],
            $data['show_id'],
            $data['show_abbr'],
            $data['media_type_id'],
            $data['media_type_label'],
            $data['curator_confidence'],
            $data['row_type'],
            $data['notes'] ?? '',
            $this->pgBool((bool) ($data['active'] ?? true)),
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function deleteAll(): void
    {
        $this->db()->exec('DELETE FROM legacy_rename_map');
    }

    public function count(): int
    {
        return (int) $this->db()->query('SELECT COUNT(*) FROM legacy_rename_map')->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function list(int $limit = 100, int $offset = 0, ?string $sourceLabel = null): array
    {
        $sql = 'SELECT lrm.*, sh.canonical_name AS show_name, mt.name AS media_type_name
                FROM legacy_rename_map lrm
                LEFT JOIN shows sh ON sh.id = lrm.show_id
                LEFT JOIN media_types mt ON mt.id = lrm.media_type_id';
        $params = [];
        if ($sourceLabel !== null && $sourceLabel !== '') {
            $sql .= ' WHERE lrm.source_label = ?';
            $params[] = strtoupper($sourceLabel);
        }
        $sql .= ' ORDER BY lrm.source_label ASC, lrm.match_path ASC LIMIT ? OFFSET ?';
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

    public function findByMatch(string $sourceLabel, string $matchPath, string $matchFilename): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT lrm.*, sh.canonical_name AS show_name
             FROM legacy_rename_map lrm
             LEFT JOIN shows sh ON sh.id = lrm.show_id
             WHERE lrm.active IS TRUE
               AND upper(lrm.source_label) = upper(?)
               AND lower(lrm.match_path) = lower(?)
               AND lower(lrm.match_filename) = lower(?)
             LIMIT 1'
        );
        $stmt->execute([
            strtoupper(trim($sourceLabel)),
            trim($matchPath),
            trim($matchFilename),
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function findByFullPath(string $sourceLabel, string $fullOriginalPath): ?array
    {
        $fullOriginalPath = trim(str_replace('\\', '/', $fullOriginalPath));
        $filename = basename($fullOriginalPath);
        $dir = dirname($fullOriginalPath);
        if ($dir === '.' || $dir === '') {
            return null;
        }

        return $this->findByMatch($sourceLabel, $dir, $filename);
    }
}
