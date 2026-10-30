<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOStatement;

final class PostgresPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(PostgresDialect::translate($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $query = PostgresDialect::translate($query);
        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec(PostgresDialect::translate($statement));
    }
}
