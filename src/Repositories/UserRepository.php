<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

final class UserRepository extends BaseRepository
{
    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->db()->query(
            'SELECT id, email, display_name, role, auth_source, active, created_at, last_login_at
             FROM users ORDER BY email ASC'
        )->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM users WHERE lower(email) = lower(?) LIMIT 1'
        );
        $stmt->execute([trim($email)]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function createLocal(string $email, string $password, string $displayName, string $role): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO users (email, password_hash, display_name, role, auth_source, active)
             VALUES (?, ?, ?, ?, \'local\', true)
             RETURNING id'
        );
        $stmt->execute([
            trim($email),
            password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            trim($displayName),
            $role,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function update(int $id, string $displayName, string $role, bool $active): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE users SET display_name = ?, role = ?, active = ? WHERE id = ?'
        );

        return $stmt->execute([trim($displayName), $role, $active, $id]);
    }

    public function updatePassword(int $id, string $password): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE users SET password_hash = ? WHERE id = ? AND auth_source = \'local\''
        );

        return $stmt->execute([
            password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            $id,
        ]);
    }

    public function upsertLdapUser(string $email, string $displayName, string $role): array
    {
        $existing = $this->findByEmail($email);
        if ($existing !== null) {
            $stmt = $this->db()->prepare(
                'UPDATE users SET
                    display_name = ?,
                    role = ?,
                    auth_source = \'ldap\',
                    active = true,
                    last_login_at = now()
                 WHERE id = ?
                 RETURNING *'
            );
            $stmt->execute([$displayName, $role, $existing['id']]);
            $row = $stmt->fetch();

            return is_array($row) ? $row : $existing;
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO users (email, password_hash, display_name, role, auth_source, active)
             VALUES (?, ?, ?, ?, \'ldap\', true)
             RETURNING *'
        );
        $stmt->execute([
            trim($email),
            password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 12]),
            $displayName,
            $role,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : [];
    }
}
