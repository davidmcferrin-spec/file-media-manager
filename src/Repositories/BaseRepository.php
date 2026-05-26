<?php

declare(strict_types=1);

namespace MediaManager\Repositories;

use MediaManager\Database;
use PDO;

abstract class BaseRepository
{
    protected function db(): PDO
    {
        return Database::connection();
    }

    /** PDO+pgsql binds PHP false as '' which PostgreSQL rejects for BOOLEAN columns. */
    protected function pgBool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
