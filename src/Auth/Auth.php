<?php

declare(strict_types=1);

namespace MediaManager\Auth;

use MediaManager\Database;
use MediaManager\Auth\LdapService;
use MediaManager\Repositories\LdapRepository;
use MediaManager\Repositories\UserRepository;
use PDO;

class Auth
{
    /**
     * Attempt login. Returns user array on success, null on failure.
     * Tries LDAP first when enabled, then local password auth.
     */
    public static function attempt(string $email, string $password, string $ip): ?array
    {
        if (self::isRateLimited($ip)) {
            return null;
        }

        $ldapRepo = new LdapRepository();
        if ($ldapRepo->isEnabled()) {
            $ldapUser = (new LdapService())->authenticate($email, $password);
            if ($ldapUser !== null) {
                $user = (new UserRepository())->upsertLdapUser(
                    $ldapUser['email'],
                    $ldapUser['name'],
                    $ldapUser['role']
                );
                self::loginSession($user);
                return $user;
            }
        }

        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT * FROM users WHERE lower(email) = lower(?) AND active IS TRUE LIMIT 1'
        );
        $stmt->execute([trim($email)]);
        $user = $stmt->fetch();

        if (!$user || ($user['auth_source'] ?? 'local') === 'ldap') {
            self::recordAttempt($ip);
            return null;
        }

        if (!password_verify($password, $user['password_hash'])) {
            self::recordAttempt($ip);
            return null;
        }

        $pdo->prepare('UPDATE users SET last_login_at = now() WHERE id = ?')
            ->execute([$user['id']]);

        self::loginSession($user);

        return $user;
    }

    /** @param array<string, mixed> $user */
    private static function loginSession(array $user): void
    {
        Session::set('user_id',    $user['id']);
        Session::set('user_email', $user['email']);
        Session::set('user_name',  $user['display_name']);
        Session::set('user_role',  $user['role']);

        session_regenerate_id(true);
    }

    public static function logout(): void
    {
        Session::destroy();
    }

    public static function check(): bool
    {
        return Session::has('user_id');
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }

        return [
            'id'    => Session::get('user_id'),
            'email' => Session::get('user_email'),
            'name'  => Session::get('user_name'),
            'role'  => Session::get('user_role'),
        ];
    }

    public static function id(): ?int
    {
        $id = Session::get('user_id');
        return $id !== null ? (int) $id : null;
    }

    public static function role(): string
    {
        return Session::get('user_role', '');
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    public static function isEditor(): bool
    {
        return in_array(self::role(), ['admin', 'editor'], true);
    }

    /**
     * Require login — redirect to /login if not authenticated.
     */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /login');
            exit;
        }
    }

    /**
     * Require admin role — redirect to dashboard if insufficient.
     */
    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            header('Location: /dashboard?error=unauthorized');
            exit;
        }
    }

    // ── Rate limiting ────────────────────────────────────────

    public static function isRateLimited(string $ip): bool
    {
        $window   = (int) env('AUTH_RATE_LIMIT_WINDOW_SECONDS', 300);
        $maxTries = (int) env('AUTH_RATE_LIMIT_MAX_ATTEMPTS', 5);

        $since = gmdate('Y-m-d\TH:i:s\Z', time() - $window);

        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM auth_attempts WHERE ip_address = ? AND attempted_at > ?'
        );
        $stmt->execute([$ip, $since]);

        return (int) $stmt->fetchColumn() >= $maxTries;
    }

    private static function recordAttempt(string $ip): void
    {
        Database::connection()
            ->prepare('INSERT INTO auth_attempts (ip_address) VALUES (?)')
            ->execute([$ip]);
    }

    // ── User management ──────────────────────────────────────

    public static function createUser(
        string $email,
        string $password,
        string $displayName,
        string $role = 'editor'
    ): int {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo  = Database::connection();

        $stmt = $pdo->prepare(
            'INSERT INTO users (email, password_hash, display_name, role, auth_source)
             VALUES (?, ?, ?, ?, \'local\')
             RETURNING id'
        );
        $stmt->execute([$email, $hash, $displayName, $role]);

        return (int) $stmt->fetchColumn();
    }

    public static function updatePassword(int $userId, string $newPassword): void
    {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        Database::connection()
            ->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([$hash, $userId]);
    }

    public static function email(): string
    {
        return Session::get('user_email', '');
    }
}
