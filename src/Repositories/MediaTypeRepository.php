<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

final class MediaTypeRepository extends BaseRepository
{
    /** @return list<array<string, mixed>> */
    public function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM media_types';
        if ($activeOnly) {
            $sql .= ' WHERE active IS TRUE';
        }
        $sql .= ' ORDER BY name ASC';

        $rows = $this->db()->query($sql)->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM media_types WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function update(
        int $id,
        string $name,
        string $abbreviation,
        string $folderName,
        string $description,
        bool $active
    ): bool {
        $stmt = $this->db()->prepare(
            'UPDATE media_types SET name = ?, abbreviation = ?, folder_name = ?, description = ?, active = ? WHERE id = ?'
        );

        return $stmt->execute([
            trim($name),
            trim($abbreviation),
            trim($folderName),
            trim($description),
            $active,
            $id,
        ]);
    }
}
