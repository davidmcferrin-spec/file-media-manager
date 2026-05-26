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
}
