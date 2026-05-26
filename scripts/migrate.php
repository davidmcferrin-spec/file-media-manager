#!/usr/bin/env php
<?php

declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../src/bootstrap.php';

use MediaManager\Database;

/** @return list<string> */
function migrationFiles(): array
{
    $files = glob(__DIR__ . '/../sql/migrations/*.sql') ?: [];
    sort($files, SORT_STRING);

    return $files;
}

/** @return list<string> */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $current = '';
    $length = strlen($sql);
    $index = 0;
    $inSingleQuote = false;
    $dollarTag = null;

    while ($index < $length) {
        $char = $sql[$index];

        if ($dollarTag !== null) {
            $tagLength = strlen($dollarTag);
            if ($char === '$' && substr($sql, $index, $tagLength) === $dollarTag) {
                $current .= $dollarTag;
                $index += $tagLength;
                $dollarTag = null;
                continue;
            }

            $current .= $char;
            $index++;
            continue;
        }

        if ($inSingleQuote) {
            $current .= $char;
            if ($char === "'") {
                if ($index + 1 < $length && $sql[$index + 1] === "'") {
                    $current .= "'";
                    $index += 2;
                    continue;
                }
                $inSingleQuote = false;
            }
            $index++;
            continue;
        }

        if ($char === '-' && $index + 1 < $length && $sql[$index + 1] === '-') {
            while ($index < $length && $sql[$index] !== "\n") {
                $index++;
            }
            continue;
        }

        if ($char === '$') {
            if (preg_match('/\G(\$[a-zA-Z0-9_]*\$)/', substr($sql, $index), $matches) === 1) {
                $dollarTag = $matches[1];
                $current .= $dollarTag;
                $index += strlen($dollarTag);
                continue;
            }
        }

        if ($char === "'") {
            $inSingleQuote = true;
            $current .= $char;
            $index++;
            continue;
        }

        if ($char === ';') {
            $statement = trim($current);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $current = '';
            $index++;
            continue;
        }

        $current .= $char;
        $index++;
    }

    $statement = trim($current);
    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}

function migrationVersion(string $path): ?string
{
    if (preg_match('/(\d{3}_[^.]+)\.sql$/', $path, $matches) !== 1) {
        return null;
    }

    return $matches[1];
}

function migrationsTableExists(\PDO $pdo): bool
{
    $statement = $pdo->query("SELECT to_regclass('public.schema_migrations')");
    if ($statement === false) {
        return false;
    }

    return $statement->fetchColumn() !== null;
}

function isApplied(\PDO $pdo, string $version): bool
{
    if (!migrationsTableExists($pdo)) {
        return false;
    }

    $statement = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version LIMIT 1');
    $statement->execute(['version' => $version]);

    return $statement->fetchColumn() !== false;
}

$pdo = Database::connection();
$applied = 0;
$skipped = 0;

foreach (migrationFiles() as $file) {
    $version = migrationVersion($file);

    if ($version !== null && isApplied($pdo, $version)) {
        $skipped++;
        continue;
    }

    echo 'Applying ' . basename($file) . PHP_EOL;

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, 'Unable to read migration file: ' . $file . PHP_EOL);
        exit(1);
    }

    try {
        foreach (splitSqlStatements($sql) as $statement) {
            $pdo->exec($statement);
        }
    } catch (\PDOException $exception) {
        fwrite(STDERR, 'Migration failed at ' . basename($file) . ': ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }

    $applied++;
}

echo 'Migrations complete. Applied: ' . $applied . ', skipped: ' . $skipped . PHP_EOL;
