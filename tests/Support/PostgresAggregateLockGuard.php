<?php

namespace Tests\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use RuntimeException;

/** Apply PostgreSQL's aggregate-lock restriction to SQLite workflow tests. */
class PostgresAggregateLockGuard extends SQLiteGrammar
{
    public array $lockedTables = [];

    public function compileSelect(Builder $query)
    {
        if ($query->aggregate !== null && $query->lock) {
            throw new RuntimeException('PostgreSQL does not allow FOR UPDATE with aggregate functions.');
        }
        if ($query->lock) {
            $this->lockedTables[] = $query->from;
        }

        return parent::compileSelect($query);
    }
}
