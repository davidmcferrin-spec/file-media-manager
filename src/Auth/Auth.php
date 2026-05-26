<?php

declare(strict_types=1);

namespace MediaManager\Auth;

use MediaManager\Database;
use PDO;

class Auth
{
    /**
     * Attempt login. Returns user array on success, null on failure.
     */
    public static function attempt(string $email, string $password, string $ip): ?array
    {
        if (self::isRateLimited($ip)) {
            return null;
        }

        $pdo  = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND active = 1 LIMIT 1');
        $stmt->execute([trim($email)]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            self::recordAttempt($ip);
            return null;
        }

        // Update last login
        $pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')
            ->execute([gmdate('Y-m-d\TH:i:s\Z'), $user['id']]);

        // Store in session
        Session::set('user_id',    $user['id']);
        Session::set('user_email', $user['email']);
        Session::set('user_name',  $user['display_name']);
        Session::set('user_role',  $user['role']);

        session_regenerate_id(true);

        return $user;
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

    private static function isRateLimited(string $ip): bool
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
            'INSERT INTO users (email, password_hash, display_name, role)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$email, $hash, $displayName, $role]);

        return (int) $pdo->lastInsertId();
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
