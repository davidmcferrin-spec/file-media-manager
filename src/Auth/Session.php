<?php

declare(strict_types=1);

namespace MediaManager\Auth;

class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $sessionName     = env('SESSION_NAME', 'media_manager_session');
        $sessionLifetime = (int) env('SESSION_LIFETIME', 28800);

        session_name($sessionName);

        session_set_cookie_params([
            'lifetime' => $sessionLifetime,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
        self::$started = true;

        // Regenerate session ID periodically to prevent fixation
        if (!isset($_SESSION['_initiated'])) {
            session_regenerate_id(true);
            $_SESSION['_initiated'] = true;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        self::$started = false;
    }

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    /**
     * Generate and store a CSRF token.
     */
    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token']    = bin2hex(random_bytes(32));
            $_SESSION['_csrf_token_at'] = time();
        }

        // Rotate if expired
        $ttl = (int) env('CSRF_TTL_SECONDS', 3600);
        if ((time() - ($_SESSION['_csrf_token_at'] ?? 0)) > $ttl) {
            $_SESSION['_csrf_token']    = bin2hex(random_bytes(32));
            $_SESSION['_csrf_token_at'] = time();
        }

        return $_SESSION['_csrf_token'];
    }

    /**
     * Validate a submitted CSRF token.
     */
    public static function validateCsrf(string $token): bool
    {
        $stored = $_SESSION['_csrf_token'] ?? '';
        return $stored !== '' && hash_equals($stored, $token);
    }
}
