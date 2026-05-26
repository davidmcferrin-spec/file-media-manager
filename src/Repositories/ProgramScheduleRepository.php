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
    public function list(int $limit = 200, int $offset = 0, ?string $search = null): array
    {
        $sql = 'SELECT pse.*, sh.abbreviation AS show_abbr, sh.canonical_name AS show_name
                FROM program_schedule_entries pse
                JOIN shows sh ON sh.id = pse.show_id';
        $params = [];
        if ($search !== null && $search !== '') {
            $sql .= ' WHERE pse.title ILIKE ? OR sh.abbreviation ILIKE ? OR sh.canonical_name ILIKE ?';
            $term = '%' . $search . '%';
            $params = [$term, $term, $term];
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
}
