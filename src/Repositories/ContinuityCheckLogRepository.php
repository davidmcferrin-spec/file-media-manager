<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

final class ContinuityCheckLogRepository extends BaseRepository
{
    /** @param array<string, mixed> $row */
    public function insert(array $row): int
    {
        $signals = $row['rule_signals'] ?? [];
        if (!is_array($signals)) {
            $signals = [];
        }

        $seed = $row['seed_packet'] ?? null;
        $seedJson = null;
        if (is_array($seed)) {
            $seedJson = json_encode($seed, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO continuity_check_log (
                original_path, original_filename,
                rule_show_id, rule_show_abbr, rule_confidence, rule_proposed_filename, rule_signals,
                engine_agree, engine_confidence, engine_show_id, engine_reason,
                final_confidence, final_show_id, final_show_abbr, final_proposed_filename,
                signal, outcome, duration_ms,
                seed_packet, engine_raw, http_status, transport_error
             ) VALUES (
                ?, ?, ?, ?, ?, ?, ?::jsonb,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?::jsonb, ?, ?, ?
             ) RETURNING id'
        );
        $stmt->execute([
            (string) ($row['original_path'] ?? ''),
            (string) ($row['original_filename'] ?? ''),
            $row['rule_show_id'] ?? null,
            $row['rule_show_abbr'] ?? null,
            (string) ($row['rule_confidence'] ?? 'LOW'),
            $row['rule_proposed_filename'] ?? null,
            json_encode(array_values($signals), JSON_THROW_ON_ERROR),
            array_key_exists('engine_agree', $row) && $row['engine_agree'] !== null
                ? $this->pgBool((bool) $row['engine_agree'])
                : null,
            $row['engine_confidence'] ?? null,
            $row['engine_show_id'] ?? null,
            (string) ($row['engine_reason'] ?? ''),
            (string) ($row['final_confidence'] ?? 'LOW'),
            $row['final_show_id'] ?? null,
            $row['final_show_abbr'] ?? null,
            $row['final_proposed_filename'] ?? null,
            (string) ($row['signal'] ?? ''),
            (string) ($row['outcome'] ?? 'error'),
            (int) ($row['duration_ms'] ?? 0),
            $seedJson,
            mb_substr((string) ($row['engine_raw'] ?? ''), 0, 8000),
            $row['http_status'] ?? null,
            mb_substr((string) ($row['transport_error'] ?? ''), 0, 1000),
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{outcome?: string, q?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters, int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = $this->whereClause($filters);
        $sql = 'SELECT * FROM continuity_check_log' . $where
            . ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @param array{outcome?: string, q?: string} $filters */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->whereClause($filters);
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM continuity_check_log' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{total: int, confirmed: int, conflict: int, review: int, error: int, unreachable: int, last_hour: int} */
    public function summary(): array
    {
        $row = $this->db()->query(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN outcome = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
                SUM(CASE WHEN outcome = 'conflict' THEN 1 ELSE 0 END) AS conflict,
                SUM(CASE WHEN outcome = 'review' THEN 1 ELSE 0 END) AS review,
                SUM(CASE WHEN outcome = 'error' THEN 1 ELSE 0 END) AS error,
                SUM(CASE WHEN outcome = 'unreachable' THEN 1 ELSE 0 END) AS unreachable,
                SUM(CASE WHEN created_at >= now() - interval '1 hour' THEN 1 ELSE 0 END) AS last_hour
             FROM continuity_check_log"
        )->fetch();

        if (!is_array($row)) {
            return [
                'total' => 0, 'confirmed' => 0, 'conflict' => 0,
                'review' => 0, 'error' => 0, 'unreachable' => 0, 'last_hour' => 0,
            ];
        }

        return [
            'total'       => (int) ($row['total'] ?? 0),
            'confirmed'   => (int) ($row['confirmed'] ?? 0),
            'conflict'    => (int) ($row['conflict'] ?? 0),
            'review'      => (int) ($row['review'] ?? 0),
            'error'       => (int) ($row['error'] ?? 0),
            'unreachable' => (int) ($row['unreachable'] ?? 0),
            'last_hour'   => (int) ($row['last_hour'] ?? 0),
        ];
    }

    public function avgDurationMs(int $limit = 200): ?float
    {
        $stmt = $this->db()->prepare(
            'SELECT AVG(duration_ms)::float FROM (
                SELECT duration_ms FROM continuity_check_log
                WHERE outcome IN (\'confirmed\', \'conflict\', \'review\')
                ORDER BY created_at DESC LIMIT ?
             ) t'
        );
        $stmt->execute([$limit]);
        $val = $stmt->fetchColumn();

        return $val !== false && $val !== null ? (float) $val : null;
    }

    /**
     * @param array{outcome?: string, q?: string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function whereClause(array $filters): array
    {
        $clauses = [];
        $params = [];
        $outcome = trim((string) ($filters['outcome'] ?? ''));
        if ($outcome !== '' && in_array($outcome, ['confirmed', 'conflict', 'review', 'error', 'unreachable'], true)) {
            $clauses[] = 'outcome = ?';
            $params[] = $outcome;
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $clauses[] = '(original_path ILIKE ? OR original_filename ILIKE ? OR engine_reason ILIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $where = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);

        return [$where, $params];
    }
}
