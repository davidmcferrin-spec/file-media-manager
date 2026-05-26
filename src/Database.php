<?php

declare(strict_types=1);

namespace MediaManager;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $dbPath = dirname(__DIR__) . '/' . env('DB_PATH', 'data/media-manager.db');
        $dbDir  = dirname($dbPath);

        if (!is_dir($dbDir) && !mkdir($dbDir, 0755, true)) {
            throw new RuntimeException("Cannot create database directory: {$dbDir}");
        }

        try {
            $pdo = new PDO("sqlite:{$dbPath}");
            $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,   false);

            // SQLite performance + safety pragmas
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');

            self::$instance = $pdo;
            return $pdo;

        } catch (PDOException $e) {
            throw new RuntimeException("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Run all pending migrations from sql/migrations/
     */
    public static function migrate(): void
    {
        $pdo = self::connection();

        // Create migrations tracking table
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS migrations (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                filename   TEXT NOT NULL UNIQUE,
                applied_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\'))
            )
        ');

        $migrationDir = dirname(__DIR__) . '/sql/migrations';
        if (!is_dir($migrationDir)) {
            return;
        }

        $files = glob($migrationDir . '/*.sql');
        if ($files === false) {
            return;
        }

        sort($files);

        foreach ($files as $file) {
            $filename = basename($file);

            // Check if already applied
            $stmt = $pdo->prepare('SELECT id FROM migrations WHERE filename = ?');
            $stmt->execute([$filename]);
            if ($stmt->fetch()) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Cannot read migration file: {$file}");
            }

            $pdo->exec($sql);

            $stmt = $pdo->prepare('INSERT INTO migrations (filename) VALUES (?)');
            $stmt->execute([$filename]);

            error_log("[migrate] Applied: {$filename}");
        }
    }

    /**
     * Prevent instantiation — use static methods only.
     */
    private function __construct() {}
}
