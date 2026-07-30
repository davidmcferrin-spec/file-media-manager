<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

final class ExpectedGapRepository extends BaseRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listForRange(string $fromIso, string $toIso, ?int $showId = null): array
    {
        $sql = 'SELECT g.*, sh.abbreviation AS show_abbr, sh.canonical_name AS show_name,
                       u.email AS created_by_email
                FROM schedule_expected_gaps g
                JOIN shows sh ON sh.id = g.show_id
                LEFT JOIN users u ON u.id = g.created_by
                WHERE g.air_date >= ?::date AND g.air_date <= ?::date';
        $params = [$fromIso, $toIso];

        if ($showId !== null && $showId > 0) {
            $sql .= ' AND g.show_id = ?';
            $params[] = $showId;
        }

        $sql .= ' ORDER BY g.air_date DESC, g.hour_start_et ASC, sh.abbreviation ASC';

        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT g.*, sh.abbreviation AS show_abbr, sh.canonical_name AS show_name
             FROM schedule_expected_gaps g
             JOIN shows sh ON sh.id = g.show_id
             WHERE g.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $data */
    public function upsert(array $data): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO schedule_expected_gaps (
                show_id, air_date, hour_start_et, media_lane, reason, notes, created_by
             ) VALUES (?, ?::date, ?::time, ?, ?, ?, ?)
             ON CONFLICT (show_id, air_date, hour_start_et, media_lane)
             DO UPDATE SET
                reason = EXCLUDED.reason,
                notes = EXCLUDED.notes,
                created_by = EXCLUDED.created_by,
                created_at = now()
             RETURNING id'
        );
        $stmt->execute([
            (int) $data['show_id'],
            (string) $data['air_date'],
            (string) $data['hour_start_et'],
            (string) $data['media_lane'],
            (string) $data['reason'],
            (string) ($data['notes'] ?? ''),
            $data['created_by'] ?? null,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM schedule_expected_gaps WHERE id = ?');

        return $stmt->execute([$id]);
    }

    public function countForRange(string $fromIso, string $toIso, ?int $showId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM schedule_expected_gaps
                WHERE air_date >= ?::date AND air_date <= ?::date';
        $params = [$fromIso, $toIso];
        if ($showId !== null && $showId > 0) {
            $sql .= ' AND show_id = ?';
            $params[] = $showId;
        }
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
