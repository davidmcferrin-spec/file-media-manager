<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

use MediaManager\Services\DateNormalizer;
use MediaManager\Services\ScheduleTimeParser;

final class ProgramScheduleRepository extends BaseRepository
{
    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO program_schedule_entries (
                show_id, source_row_id, title, hour_start_et, hour_end_et,
                days_of_week, effective_from, effective_to,
                era_name, anchor_names, show_type, network_brand, notes, active
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             RETURNING id'
        );
        $stmt->execute([
            $data['show_id'],
            $data['source_row_id'] ?? null,
            $data['title'],
            $data['hour_start_et'],
            $data['hour_end_et'],
            $data['days_of_week'],
            $data['effective_from'],
            $data['effective_to'] ?? null,
            $data['era_name'] ?? '',
            $data['anchor_names'] ?? '',
            $data['show_type'] ?? '',
            $data['network_brand'] ?? '',
            $data['notes'] ?? '',
            $this->pgBool((bool) ($data['active'] ?? true)),
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function deleteAll(): void
    {
        $this->db()->exec('DELETE FROM program_schedule_entries');
    }

    public function count(): int
    {
        return (int) $this->db()->query('SELECT COUNT(*) FROM program_schedule_entries')->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function list(int $limit = 200, int $offset = 0, ?string $search = null, ?int $showId = null): array
    {
        $sql = 'SELECT pse.*, sh.abbreviation AS show_abbr, sh.canonical_name AS show_name
                FROM program_schedule_entries pse
                JOIN shows sh ON sh.id = pse.show_id';
        $params = [];
        $where = [];

        if ($showId !== null && $showId > 0) {
            $where[] = 'pse.show_id = ?';
            $params[] = $showId;
        }
        if ($search !== null && $search !== '') {
            $where[] = '(pse.title ILIKE ? OR sh.abbreviation ILIKE ? OR sh.canonical_name ILIKE ?)';
            $term = '%' . $search . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY pse.effective_from DESC, pse.hour_start_et ASC, pse.title ASC LIMIT ? OFFSET ?';
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

    public function countFiltered(?string $search = null, ?int $showId = null): int
    {
        $sql = 'SELECT COUNT(*)
                FROM program_schedule_entries pse
                JOIN shows sh ON sh.id = pse.show_id';
        $params = [];
        $where = [];

        if ($showId !== null && $showId > 0) {
            $where[] = 'pse.show_id = ?';
            $params[] = $showId;
        }
        if ($search !== null && $search !== '') {
            $where[] = '(pse.title ILIKE ? OR sh.abbreviation ILIKE ? OR sh.canonical_name ILIKE ?)';
            $term = '%' . $search . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = $this->db()->prepare($sql);
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT pse.*, sh.abbreviation AS show_abbr, sh.canonical_name AS show_name
             FROM program_schedule_entries pse
             JOIN shows sh ON sh.id = pse.show_id
             WHERE pse.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE program_schedule_entries SET
                show_id = ?,
                title = ?,
                hour_start_et = ?,
                hour_end_et = ?,
                days_of_week = ?,
                effective_from = ?,
                effective_to = ?,
                era_name = ?,
                anchor_names = ?,
                show_type = ?,
                network_brand = ?,
                notes = ?,
                active = ?
             WHERE id = ?'
        );

        return $stmt->execute([
            $data['show_id'],
            $data['title'],
            $data['hour_start_et'],
            $data['hour_end_et'],
            $data['days_of_week'],
            $data['effective_from'],
            $data['effective_to'] ?? null,
            $data['era_name'] ?? '',
            $data['anchor_names'] ?? '',
            $data['show_type'] ?? '',
            $data['network_brand'] ?? '',
            $data['notes'] ?? '',
            $this->pgBool((bool) ($data['active'] ?? true)),
            $id,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM program_schedule_entries WHERE id = ?');

        return $stmt->execute([$id]);
    }

    /** @return array<int, int> show_id => entry count */
    public function countByShow(): array
    {
        $rows = $this->db()->query(
            'SELECT show_id, COUNT(*) AS cnt FROM program_schedule_entries GROUP BY show_id'
        )->fetchAll();

        $counts = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $counts[(int) $row['show_id']] = (int) $row['cnt'];
            }
        }

        return $counts;
    }

    /** @return list<array<string, mixed>> */
    public function matchAt(string $dateYmd, int $timeMinutes, int $dayBit): array
    {
        if (!DateNormalizer::isValidDate($dateYmd)) {
            return [];
        }

        $year = substr($dateYmd, 0, 4);
        $month = substr($dateYmd, 4, 2);
        $day = substr($dateYmd, 6, 2);
        $dateIso = $year . '-' . $month . '-' . $day;
        $hour = ScheduleTimeParser::minutesToTime((int) (floor($timeMinutes / 60) * 60));

        $stmt = $this->db()->prepare(
            'SELECT pse.*, sh.abbreviation AS show_abbr, sh.canonical_name AS show_name
             FROM program_schedule_entries pse
             JOIN shows sh ON sh.id = pse.show_id
             WHERE pse.active IS TRUE
               AND pse.effective_from <= ?::date
               AND (pse.effective_to IS NULL OR pse.effective_to >= ?::date)
               AND (pse.days_of_week & ?) <> 0
               AND pse.hour_start_et <= ?::time
               AND (
                 (pse.hour_end_et > pse.hour_start_et AND pse.hour_end_et > ?::time)
                 OR (pse.hour_end_et <= pse.hour_start_et AND ?::time >= pse.hour_start_et)
               )
             ORDER BY pse.effective_from DESC
             LIMIT 5'
        );
        $stmt->execute([$dateIso, $dateIso, $dayBit, $hour, $hour, $hour]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function reassignShow(int $fromShowId, int $toShowId): int
    {
        $stmt = $this->db()->prepare(
            'UPDATE program_schedule_entries SET show_id = ? WHERE show_id = ?'
        );
        $stmt->execute([$toShowId, $fromShowId]);

        return $stmt->rowCount();
    }

    /**
     * Active schedule entries overlapping [fromIso, toIso].
     *
     * @return list<array<string, mixed>>
     */
    public function listOverlapping(string $fromIso, string $toIso, ?int $showId = null): array
    {
        $sql = 'SELECT pse.*, sh.abbreviation AS show_abbr, sh.canonical_name AS show_name
                FROM program_schedule_entries pse
                JOIN shows sh ON sh.id = pse.show_id
                WHERE pse.active IS TRUE
                  AND pse.effective_from <= ?::date
                  AND (pse.effective_to IS NULL OR pse.effective_to >= ?::date)';
        $params = [$toIso, $fromIso];

        if ($showId !== null && $showId > 0) {
            $sql .= ' AND pse.show_id = ?';
            $params[] = $showId;
        }

        $sql .= ' ORDER BY pse.effective_from ASC, pse.hour_start_et ASC, pse.id ASC';

        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * Open-ended entries (no effective_to) — candidates for schedule hygiene.
     *
     * @return list<array<string, mixed>>
     */
    public function listOpenEnded(int $limit = 200, int $offset = 0): array
    {
        $stmt = $this->db()->prepare(
            'SELECT pse.*, sh.abbreviation AS show_abbr, sh.canonical_name AS show_name
             FROM program_schedule_entries pse
             JOIN shows sh ON sh.id = pse.show_id
             WHERE pse.active IS TRUE AND pse.effective_to IS NULL
             ORDER BY pse.effective_from ASC, pse.hour_start_et ASC
             LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function countOpenEnded(): int
    {
        return (int) $this->db()->query(
            'SELECT COUNT(*) FROM program_schedule_entries
             WHERE active IS TRUE AND effective_to IS NULL'
        )->fetchColumn();
    }

    public function setEffectiveTo(int $id, string $effectiveTo): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE program_schedule_entries SET effective_to = ?::date WHERE id = ?'
        );

        return $stmt->execute([$effectiveTo, $id]);
    }
}
