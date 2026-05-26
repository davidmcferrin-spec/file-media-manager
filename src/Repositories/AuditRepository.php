<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

final class AuditRepository extends BaseRepository
{
    public function record(
        ?int $userId,
        string $userEmail,
        string $ipAddress,
        string $action,
        string $entityType = '',
        ?int $entityId = null,
        ?string $originalPath = null,
        ?string $newPath = null,
        array $details = []
    ): void {
        $stmt = $this->db()->prepare(
            'INSERT INTO audit_log
                (user_id, user_email, ip_address, action, entity_type, entity_id, original_path, new_path, details)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $userEmail,
            $ipAddress,
            $action,
            $entityType,
            $entityId,
            $originalPath,
            $newPath,
            json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters, int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = $this->whereClause($filters);

        $sql = 'SELECT * FROM audit_log WHERE ' . $where . '
                ORDER BY created_at DESC, id DESC
                LIMIT ? OFFSET ?';
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

    /** @param array<string, mixed> $filters */
    public function count(array $filters): int
    {
        [$where, $params] = $this->whereClause($filters);
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM audit_log WHERE ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<string> */
    public function distinctActions(): array
    {
        $rows = $this->db()->query(
            'SELECT DISTINCT action FROM audit_log ORDER BY action ASC'
        )->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        return array_map(fn ($r) => (string) $r['action'], $rows);
    }

    /** @param array<string, mixed> $filters */
    /** @return array{0: string, 1: list<mixed>} */
    private function whereClause(array $filters): array
    {
        $clauses = ['1=1'];
        $params  = [];

        if (!empty($filters['action'])) {
            $clauses[] = 'action = ?';
            $params[]  = (string) $filters['action'];
        }
        if (!empty($filters['entity_type'])) {
            $clauses[] = 'entity_type = ?';
            $params[]  = (string) $filters['entity_type'];
        }
        if (!empty($filters['user_email'])) {
            $clauses[] = 'user_email ILIKE ?';
            $params[]  = '%' . (string) $filters['user_email'] . '%';
        }
        if (!empty($filters['search'])) {
            $clauses[] = '(original_path ILIKE ? OR new_path ILIKE ? OR action ILIKE ?)';
            $term = '%' . (string) $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        return [implode(' AND ', $clauses), $params];
    }
}
