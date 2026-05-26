<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

final class IgnorePathRepository extends BaseRepository
{
    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $sql = 'SELECT sip.*, s.name AS source_name, s.mount_path AS source_mount
                FROM scan_ignore_paths sip
                LEFT JOIN sources s ON s.id = sip.source_id
                ORDER BY s.name ASC NULLS LAST, sip.path ASC';

        $rows = $this->db()->query($sql)->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @return list<array{prefix: string, source_mount: ?string}> */
    public function activePrefixes(): array
    {
        $sql = 'SELECT sip.path, s.mount_path AS source_mount
                FROM scan_ignore_paths sip
                LEFT JOIN sources s ON s.id = sip.source_id
                WHERE sip.active IS TRUE';

        $rows = $this->db()->query($sql)->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $prefixes = [];
        foreach ($rows as $row) {
            $prefix = self::resolvePrefix((string) $row['path'], $row['source_mount'] ?? null);
            if ($prefix !== '') {
                $prefixes[] = [
                    'prefix'        => $prefix,
                    'source_mount'  => isset($row['source_mount']) ? (string) $row['source_mount'] : null,
                ];
            }
        }

        return $prefixes;
    }

    public function create(?int $sourceId, string $path, string $notes = ''): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO scan_ignore_paths (source_id, path, notes, active)
             VALUES (?, ?, ?, true)
             RETURNING id'
        );
        $stmt->execute([$sourceId, self::normalizeInputPath($path), trim($notes)]);

        return (int) $stmt->fetchColumn();
    }

    public function update(int $id, ?int $sourceId, string $path, string $notes, bool $active): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE scan_ignore_paths SET source_id = ?, path = ?, notes = ?, active = ? WHERE id = ?'
        );

        return $stmt->execute([
            $sourceId,
            self::normalizeInputPath($path),
            trim($notes),
            $active,
            $id,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM scan_ignore_paths WHERE id = ?');

        return $stmt->execute([$id]);
    }

    public static function normalizeInputPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return rtrim($path, '/');
    }

    public static function resolvePrefix(string $path, ?string $sourceMount): string
    {
        $path = self::normalizeInputPath($path);
        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        if ($sourceMount === null || $sourceMount === '') {
            return $path;
        }

        return rtrim(str_replace('\\', '/', $sourceMount), '/') . '/' . $path;
    }
}
