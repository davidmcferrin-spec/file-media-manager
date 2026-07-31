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
                file_id, original_path, original_filename,
                rule_show_id, rule_show_abbr, rule_confidence, rule_proposed_filename, rule_signals,
                rule_file_date, rule_file_time,
                rule_media_type_id, rule_media_type_abbr,
                engine_agree, engine_confidence, engine_show_id, engine_reason,
                engine_file_date, engine_file_time,
                engine_media_type_id, engine_media_type_abbr,
                final_confidence, final_show_id, final_show_abbr, final_proposed_filename,
                final_file_date, final_file_time,
                final_media_type_id, final_media_type_abbr,
                signal, outcome, duration_ms,
                seed_packet, engine_raw, http_status, transport_error
             ) VALUES (
                ?, ?, ?,
                ?, ?, ?, ?, ?::jsonb,
                ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?, ?,
                ?::jsonb, ?, ?, ?
             ) RETURNING id'
        );
        $stmt->execute([
            $row['file_id'] ?? null,
            (string) ($row['original_path'] ?? ''),
            (string) ($row['original_filename'] ?? ''),
            $row['rule_show_id'] ?? null,
            $row['rule_show_abbr'] ?? null,
            (string) ($row['rule_confidence'] ?? 'UNEVALUATED'),
            $row['rule_proposed_filename'] ?? null,
            json_encode(array_values($signals), JSON_THROW_ON_ERROR),
            $row['rule_file_date'] ?? null,
            $row['rule_file_time'] ?? null,
            $row['rule_media_type_id'] ?? null,
            $row['rule_media_type_abbr'] ?? null,
            array_key_exists('engine_agree', $row) && $row['engine_agree'] !== null
                ? $this->pgBool((bool) $row['engine_agree'])
                : null,
            $row['engine_confidence'] ?? null,
            $row['engine_show_id'] ?? null,
            (string) ($row['engine_reason'] ?? ''),
            $row['engine_file_date'] ?? null,
            $row['engine_file_time'] ?? null,
            $row['engine_media_type_id'] ?? null,
            $row['engine_media_type_abbr'] ?? null,
            (string) ($row['final_confidence'] ?? 'UNEVALUATED'),
            $row['final_show_id'] ?? null,
            $row['final_show_abbr'] ?? null,
            $row['final_proposed_filename'] ?? null,
            $row['final_file_date'] ?? null,
            $row['final_file_time'] ?? null,
            $row['final_media_type_id'] ?? null,
            $row['final_media_type_abbr'] ?? null,
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

    public function attachFileIdByPath(string $originalPath, int $fileId): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE continuity_check_log SET file_id = ?
             WHERE id = (
               SELECT id FROM continuity_check_log
               WHERE original_path = ? AND file_id IS NULL
               ORDER BY created_at DESC, id DESC
               LIMIT 1
             )'
        );

        return $stmt->execute([$fileId, $originalPath]);
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

    /**
     * Lean projection for large XLSX dumps (truncated artifacts + seed counts in SQL).
     *
     * @param array{outcome?: string, q?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function listForExport(array $filters, int $limit = 60000, int $offset = 0): array
    {
        [$where, $params] = $this->whereClause($filters);
        $sql = 'SELECT
                    id, created_at, outcome, duration_ms, file_id,
                    rule_confidence, final_confidence,
                    rule_show_id, rule_show_abbr, final_show_id, final_show_abbr,
                    rule_file_date, rule_file_time, engine_file_date, engine_file_time,
                    final_file_date, final_file_time,
                    rule_media_type_id, rule_media_type_abbr,
                    engine_media_type_id, engine_media_type_abbr,
                    final_media_type_id, final_media_type_abbr,
                    engine_agree, engine_confidence, engine_show_id, engine_reason,
                    signal, original_path, original_filename,
                    rule_proposed_filename, final_proposed_filename, rule_signals,
                    http_status, transport_error,
                    LEFT(COALESCE(engine_raw, \'\'), 2000) AS engine_raw,
                    LEFT(COALESCE(seed_packet::text, \'\'), 4000) AS seed_packet_json,
                    CASE
                      WHEN seed_packet IS NULL THEN 0
                      WHEN jsonb_typeof(seed_packet->\'shows\') = \'array\'
                        THEN jsonb_array_length(seed_packet->\'shows\')
                      ELSE 0
                    END AS seed_shows_count,
                    CASE
                      WHEN seed_packet IS NULL THEN 0
                      WHEN jsonb_typeof(seed_packet->\'timeline\') = \'array\'
                        THEN jsonb_array_length(seed_packet->\'timeline\')
                      ELSE 0
                    END AS seed_timeline_count,
                    CASE
                      WHEN seed_packet IS NULL THEN 0
                      WHEN jsonb_typeof(seed_packet->\'examples\') = \'array\'
                        THEN jsonb_array_length(seed_packet->\'examples\')
                      ELSE 0
                    END AS seed_examples_count
                FROM continuity_check_log'
            . $where
            . ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?';
        $params[] = max(1, min(100000, $limit));
        $params[] = max(0, $offset);
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
