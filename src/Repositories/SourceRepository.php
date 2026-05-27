<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

final class SourceRepository extends BaseRepository
{
    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->db()->query('SELECT * FROM sources ORDER BY name ASC')->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM sources WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function update(
        int $id,
        string $name,
        string $mountPath,
        string $description,
        ?string $sourceCode,
        bool $active
    ): bool {
        $code = $sourceCode !== null ? strtoupper(trim($sourceCode)) : null;
        if ($code === '') {
            $code = null;
        }

        $stmt = $this->db()->prepare(
            'UPDATE sources SET name = ?, mount_path = ?, description = ?, source_code = ?, active = ? WHERE id = ?'
        );

        return $stmt->execute([
            trim($name),
            rtrim(trim($mountPath), '/'),
            trim($description),
            $code,
            $active,
            $id,
        ]);
    }
}
