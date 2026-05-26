<?php

declare(strict_types=1);

// ── Autoloader ───────────────────────────────────────────────
spl_autoload_register(function (string $class): void {
    $prefix = 'MediaManager\\';
    $base   = __DIR__ . '/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = $base . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// ── Load .env ────────────────────────────────────────────────
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if ($key !== '' && !isset($_ENV[$key])) {
                $_ENV[$key]    = $value;
                putenv("{$key}={$value}");
            }
        }
    }
}

// ── Config helper ────────────────────────────────────────────
function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false) {
        return $default;
    }
    return match (strtolower((string) $value)) {
        'true'  => true,
        'false' => false,
        'null'  => null,
        default => $value,
    };
}

// ── Error handling ───────────────────────────────────────────
if (env('APP_DEBUG', false) === true) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    $logPath = dirname(__DIR__) . '/' . env('STORAGE_LOGS', 'storage/logs') . '/app.log';
    ini_set('error_log', $logPath);
}

// ── Timezone ─────────────────────────────────────────────────
date_default_timezone_set('America/New_York');
