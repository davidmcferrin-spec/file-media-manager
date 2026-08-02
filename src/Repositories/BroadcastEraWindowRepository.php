<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

final class BroadcastEraWindowRepository extends BaseRepository
{
    /** @return list<array<string, mixed>> */
    public function listForEra(int $eraId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM broadcast_era_windows
             WHERE era_id = ?
             ORDER BY hour_start_et ASC, id ASC'
        );
        $stmt->execute([$eraId]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param list<int> $eraIds
     * @return list<array<string, mixed>>
     */
    public function listForEras(array $eraIds): array
    {
        if ($eraIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($eraIds), '?'));
        $stmt = $this->db()->prepare(
            "SELECT * FROM broadcast_era_windows
             WHERE era_id IN ($placeholders)
             ORDER BY era_id ASC, hour_start_et ASC, id ASC"
        );
        $stmt->execute(array_values($eraIds));
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM broadcast_era_windows WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function insert(int $eraId, string $hourStart, string $hourEnd, int $daysOfWeek, string $notes = ''): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO broadcast_era_windows (era_id, hour_start_et, hour_end_et, days_of_week, notes)
             VALUES (?, ?, ?, ?, ?)
             RETURNING id'
        );
        $stmt->execute([$eraId, $hourStart, $hourEnd, $daysOfWeek, trim($notes)]);

        return (int) $stmt->fetchColumn();
    }

    public function update(int $id, string $hourStart, string $hourEnd, int $daysOfWeek, string $notes = ''): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE broadcast_era_windows SET
                hour_start_et = ?, hour_end_et = ?, days_of_week = ?, notes = ?
             WHERE id = ?'
        );

        return $stmt->execute([$hourStart, $hourEnd, $daysOfWeek, trim($notes), $id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM broadcast_era_windows WHERE id = ?');

        return $stmt->execute([$id]);
    }

    public function deleteForEra(int $eraId): void
    {
        $stmt = $this->db()->prepare('DELETE FROM broadcast_era_windows WHERE era_id = ?');
        $stmt->execute([$eraId]);
    }
}
