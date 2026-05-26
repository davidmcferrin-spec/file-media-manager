<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

use PDOException;

final class ConversionRuleRepository extends BaseRepository
{
    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $sql = 'SELECT cr.*,
                       s.canonical_name AS show_name,
                       s.abbreviation AS show_abbreviation,
                       mt.name AS media_type_name,
                       mt.abbreviation AS media_type_abbreviation
                FROM conversion_rules cr
                LEFT JOIN shows s ON s.id = cr.show_id
                LEFT JOIN media_types mt ON mt.id = cr.media_type_id
                ORDER BY cr.category ASC, cr.alias ASC';

        $rows = $this->db()->query($sql)->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM conversion_rules WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function createShowRule(string $alias, int $showId, string $notes = ''): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO conversion_rules (category, alias, show_id, notes, active)
             VALUES (\'show\', ?, ?, ?, true)
             RETURNING id'
        );
        $stmt->execute([self::normalizeAlias($alias), $showId, trim($notes)]);

        return (int) $stmt->fetchColumn();
    }

    public function createMediaTypeRule(string $alias, int $mediaTypeId, string $notes = ''): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO conversion_rules (category, alias, media_type_id, notes, active)
             VALUES (\'media_type\', ?, ?, ?, true)
             RETURNING id'
        );
        $stmt->execute([self::normalizeAlias($alias), $mediaTypeId, trim($notes)]);

        return (int) $stmt->fetchColumn();
    }

    public function update(
        int $id,
        string $alias,
        ?int $showId,
        ?int $mediaTypeId,
        string $notes,
        bool $active
    ): bool {
        $stmt = $this->db()->prepare(
            'UPDATE conversion_rules SET
                alias = ?,
                show_id = ?,
                media_type_id = ?,
                notes = ?,
                active = ?,
                updated_at = now()
             WHERE id = ?'
        );

        return $stmt->execute([
            self::normalizeAlias($alias),
            $showId,
            $mediaTypeId,
            trim($notes),
            $active,
            $id,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM conversion_rules WHERE id = ?');

        return $stmt->execute([$id]);
    }

    public function isUniqueViolation(PDOException $e): bool
    {
        return str_contains($e->getMessage(), 'conversion_rules_category_alias_key')
            || str_contains($e->getMessage(), 'duplicate key');
    }

    public static function normalizeAlias(string $alias): string
    {
        $alias = trim($alias);
        $alias = preg_replace('/\s+/', ' ', $alias) ?? $alias;

        return strtolower($alias);
    }
}
