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

    public function findByCanonicalName(string $name): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM shows WHERE lower(canonical_name) = lower(?) LIMIT 1');
        $stmt->execute([trim($name)]);
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

    /**
     * Merge duplicate/auto-generated shows into a canonical dictionary entry.
     *
     * @param list<int> $absorbedIds
     * @return array{schedule: int, files: int, rules: int, deleted: int}
     */
    public function mergeInto(int $canonicalId, array $absorbedIds): array
    {
        $canonical = $this->findById($canonicalId);
        if ($canonical === null) {
            throw new \InvalidArgumentException('Canonical show not found.');
        }

        $counts = ['schedule' => 0, 'files' => 0, 'rules' => 0, 'deleted' => 0];
        $canonicalAliases = $this->decodeAliases((string) ($canonical['aliases'] ?? '[]'));
        $canonicalAliases[] = (string) $canonical['canonical_name'];
        $canonicalAliases[] = (string) $canonical['abbreviation'];

        $scheduleRepo = new ProgramScheduleRepository();

        foreach ($absorbedIds as $absorbedId) {
            $absorbedId = (int) $absorbedId;
            if ($absorbedId <= 0 || $absorbedId === $canonicalId) {
                continue;
            }

            $absorbed = $this->findById($absorbedId);
            if ($absorbed === null) {
                continue;
            }

            $counts['schedule'] += $scheduleRepo->reassignShow($absorbedId, $canonicalId);

            $stmt = $this->db()->prepare('UPDATE files SET show_id = ? WHERE show_id = ?');
            $stmt->execute([$canonicalId, $absorbedId]);
            $counts['files'] += $stmt->rowCount();

            $stmt = $this->db()->prepare(
                'UPDATE conversion_rules SET show_id = ? WHERE show_id = ? AND category = \'show\''
            );
            $stmt->execute([$canonicalId, $absorbedId]);
            $counts['rules'] += $stmt->rowCount();

            $canonicalAliases = array_merge(
                $canonicalAliases,
                $this->decodeAliases((string) ($absorbed['aliases'] ?? '[]')),
                [(string) $absorbed['canonical_name'], (string) $absorbed['abbreviation']]
            );

            $del = $this->db()->prepare('DELETE FROM shows WHERE id = ?');
            if ($del->execute([$absorbedId])) {
                $counts['deleted']++;
            }
        }

        $canonicalAliases = array_values(array_unique(array_filter(array_map('trim', $canonicalAliases))));
        $this->update(
            $canonicalId,
            (string) $canonical['canonical_name'],
            (string) $canonical['abbreviation'],
            $canonicalAliases,
            (string) ($canonical['notes'] ?? ''),
            !empty($canonical['active'])
        );

        return $counts;
    }

    /** @return list<string> */
    private function decodeAliases(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $aliases = [];
        foreach ($decoded as $alias) {
            if (is_string($alias) && trim($alias) !== '') {
                $aliases[] = trim($alias);
            }
        }

        return $aliases;
    }
}
