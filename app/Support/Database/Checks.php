<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CHECK constraints that behave the same on PostgreSQL (production) and SQLite (tests).
 *
 * PostgreSQL gets a real CHECK constraint. SQLite cannot add a CHECK to an existing table,
 * so the same rule is installed as a pair of BEFORE INSERT / BEFORE UPDATE triggers that
 * abort the statement. An expression is written once with plain column names; the SQLite
 * form prefixes them with NEW. Use the per-driver array form when the syntax differs
 * (a regular expression, for example).
 *
 * On PostgreSQL a constraint added to a table that already holds rows is created NOT VALID
 * (it guards every new write at once) and validated straight away only when no existing
 * row breaks it, so a migration never fails on historical data it did not create.
 */
final class Checks
{
    /** Three upper-case letters, or NULL. */
    public static function currency(string $column): array
    {
        return [
            'pgsql' => "{$column} IS NULL OR {$column} ~ '^[A-Z]{3}\$'",
            'sqlite' => "{$column} IS NULL OR (length({$column}) = 3 AND {$column} GLOB '[A-Z][A-Z][A-Z]')",
        ];
    }

    /**
     * @param  string|array{pgsql: string, sqlite: string}  $expression
     * @param  list<string>  $columns  the columns the rule reads (a SQLite UPDATE trigger fires only when one of them is set)
     */
    public static function add(string $table, string $name, string|array $expression, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            self::addSqlite($table, $name, is_array($expression) ? $expression['sqlite'] : $expression, $columns);

            return;
        }

        $sql = is_array($expression) ? $expression['pgsql'] : $expression;

        if (self::pgConstraintExists($name)) {
            return;
        }

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$sql}) NOT VALID");

        $violations = (int) DB::selectOne("SELECT COUNT(*) AS n FROM {$table} WHERE NOT ({$sql})")->n;

        if ($violations === 0) {
            DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$name}");
        }
    }

    /** The one-owner rule every ledger table carries: exactly one of user_id, team_id. */
    public static function ownerRule(string $table): void
    {
        self::add($table, "{$table}_owner_rule", '(user_id IS NOT NULL AND team_id IS NULL) OR (user_id IS NULL AND team_id IS NOT NULL)', ['user_id', 'team_id']);
    }

    public static function drop(string $table, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}_insert");
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}_update");

            return;
        }

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
    }

    /**
     * Remove every check this class installed on a table, in a form a test can undo with rollback
     * (used to build a deliberately broken legacy row).
     */
    public static function dropAll(string $table, array $names): void
    {
        foreach ($names as $name) {
            self::drop($table, $name);
        }
    }

    private static function addSqlite(string $table, string $name, string $expression, array $columns): void
    {
        $message = str_replace("'", "''", "{$table}: rule {$name} violated");
        $when = 'NOT ('.preg_replace('/\b('.implode('|', array_map('preg_quote', $columns)).')\b/', 'NEW.$1', $expression).')';
        $of = implode(', ', $columns);

        DB::unprepared("CREATE TRIGGER IF NOT EXISTS {$name}_insert BEFORE INSERT ON {$table} WHEN {$when} BEGIN SELECT RAISE(ABORT, '{$message}'); END");
        DB::unprepared("CREATE TRIGGER IF NOT EXISTS {$name}_update BEFORE UPDATE OF {$of} ON {$table} WHEN {$when} BEGIN SELECT RAISE(ABORT, '{$message}'); END");
    }

    private static function pgConstraintExists(string $name): bool
    {
        return DB::selectOne('SELECT 1 AS present FROM pg_constraint WHERE conname = ?', [$name]) !== null;
    }
}
