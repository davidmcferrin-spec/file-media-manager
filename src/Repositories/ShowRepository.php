<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

use PDOException;

final class ShowRepository extends BaseRepository
{
    /** @return list<array<string, mixed>> */
    public function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM shows';
        if ($activeOnly) {
            $sql .= ' WHERE active IS TRUE';
        }
        $sql .= ' ORDER BY canonical_name ASC';

        $rows = $this->db()->query($sql)->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM shows WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function findByAbbreviation(string $abbreviation): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM shows WHERE lower(abbreviation) = lower(?) LIMIT 1');
        $stmt->execute([trim($abbreviation)]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param list<string> $aliases */
    public function create(string $canonicalName, string $abbreviation, array $aliases, string $notes = ''): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO shows (canonical_name, abbreviation, aliases, notes, active)
             VALUES (?, ?, ?, ?, true)
             RETURNING id'
        );
        $stmt->execute([
            trim($canonicalName),
            strtoupper(trim($abbreviation)),
            json_encode(array_values($aliases), JSON_THROW_ON_ERROR),
            trim($notes),
        ]);

        return (int) $stmt->fetchColumn();
    }

    /** @param list<string> $aliases */
    public function update(
        int $id,
        string $canonicalName,
        string $abbreviation,
        array $aliases,
        string $notes,
        bool $active
    ): bool {
        $stmt = $this->db()->prepare(
            'UPDATE shows SET
                canonical_name = ?,
                abbreviation = ?,
                aliases = ?,
                notes = ?,
                active = ?,
                updated_at = now()
             WHERE id = ?'
        );

        return $stmt->execute([
            trim($canonicalName),
            strtoupper(trim($abbreviation)),
            json_encode(array_values($aliases), JSON_THROW_ON_ERROR),
            trim($notes),
            $active,
            $id,
        ]);
    }

    public function isUniqueViolation(PDOException $e): bool
    {
        return str_contains($e->getMessage(), 'shows_abbreviation_lower_key')
            || str_contains($e->getMessage(), 'duplicate key');
    }
}
