<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

final class LdapRepository extends BaseRepository
{
    public function getSettings(): array
    {
        $row = $this->db()->query('SELECT * FROM ldap_settings WHERE id = 1 LIMIT 1')->fetch();

        return is_array($row) ? $row : [
            'enabled' => false,
            'host' => '',
            'port' => 389,
            'bind_dn_pattern' => '',
            'search_base_dn' => '',
            'user_search_filter' => '(sAMAccountName={username})',
        ];
    }

    public function isEnabled(): bool
    {
        $settings = $this->getSettings();

        return (bool) ($settings['enabled'] ?? false);
    }

    public function saveSettings(array $data): void
    {
        $stmt = $this->db()->prepare(
            'UPDATE ldap_settings SET
                enabled = ?,
                host = ?,
                port = ?,
                bind_dn_pattern = ?,
                search_base_dn = ?,
                user_search_filter = ?,
                updated_at = now()
             WHERE id = 1'
        );
        $stmt->execute([
            (bool) ($data['enabled'] ?? false),
            trim((string) ($data['host'] ?? '')),
            (int) ($data['port'] ?? 389),
            trim((string) ($data['bind_dn_pattern'] ?? '')),
            trim((string) ($data['search_base_dn'] ?? '')),
            trim((string) ($data['user_search_filter'] ?? '(sAMAccountName={username})')),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function groupRoles(): array
    {
        $rows = $this->db()->query('SELECT * FROM ldap_group_roles ORDER BY ldap_group ASC')->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function addGroupRole(string $ldapGroup, string $role): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO ldap_group_roles (ldap_group, role) VALUES (?, ?) RETURNING id'
        );
        $stmt->execute([trim($ldapGroup), $role]);

        return (int) $stmt->fetchColumn();
    }

    public function deleteGroupRole(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM ldap_group_roles WHERE id = ?');

        return $stmt->execute([$id]);
    }

    /** @return list<array<string, mixed>> */
    public function roleMappings(): array
    {
        return $this->groupRoles();
    }
}
