<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

final class BroadcastEraRepository extends BaseRepository
{
    /** @return list<array<string, mixed>> */
    public function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM broadcast_eras';
        if ($activeOnly) {
            $sql .= ' WHERE active IS TRUE';
        }
        $sql .= ' ORDER BY sort_order ASC, effective_from ASC, id ASC';
        $rows = $this->db()->query($sql)->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM broadcast_eras WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Active eras overlapping an inclusive ISO date range.
     *
     * @return list<array<string, mixed>>
     */
    public function listOverlapping(string $fromIso, string $toIso): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM broadcast_eras
             WHERE active IS TRUE
               AND effective_from <= ?::date
               AND (effective_to IS NULL OR effective_to >= ?::date)
             ORDER BY sort_order ASC, effective_from ASC, id ASC'
        );
        $stmt->execute([$toIso, $fromIso]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function create(
        string $name,
        string $effectiveFrom,
        ?string $effectiveTo,
        string $notes = '',
        int $sortOrder = 0,
        bool $active = true
    ): int {
        $stmt = $this->db()->prepare(
            'INSERT INTO broadcast_eras (name, effective_from, effective_to, notes, sort_order, active)
             VALUES (?, ?, ?, ?, ?, ?)
             RETURNING id'
        );
        $stmt->execute([
            trim($name),
            $effectiveFrom,
            $effectiveTo,
            trim($notes),
            $sortOrder,
            $this->pgBool($active),
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function update(
        int $id,
        string $name,
        string $effectiveFrom,
        ?string $effectiveTo,
        string $notes,
        int $sortOrder,
        bool $active
    ): bool {
        $stmt = $this->db()->prepare(
            'UPDATE broadcast_eras SET
                name = ?, effective_from = ?, effective_to = ?, notes = ?,
                sort_order = ?, active = ?, updated_at = now()
             WHERE id = ?'
        );

        return $stmt->execute([
            trim($name),
            $effectiveFrom,
            $effectiveTo,
            trim($notes),
            $sortOrder,
            $this->pgBool($active),
            $id,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM broadcast_eras WHERE id = ?');

        return $stmt->execute([$id]);
    }

    public function count(): int
    {
        return (int) $this->db()->query('SELECT COUNT(*) FROM broadcast_eras')->fetchColumn();
    }
}
