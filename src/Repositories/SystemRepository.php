<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

final class SystemRepository extends BaseRepository
{
    /** @return array<string, string> */
    public function allSettings(): array
    {
        $rows = $this->db()->query('SELECT key, value FROM system_settings')->fetchAll();
        $settings = [];
        if (!is_array($rows)) {
            return $settings;
        }
        foreach ($rows as $row) {
            $settings[(string) $row['key']] = (string) $row['value'];
        }

        return $settings;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->db()->prepare('SELECT value FROM system_settings WHERE key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (string) $value : $default;
    }
}
