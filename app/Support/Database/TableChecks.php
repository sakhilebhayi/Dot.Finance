<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table-level rules that hold on both databases the app runs on.
 *
 * PostgreSQL gets a real CHECK constraint. SQLite cannot add a CHECK to an existing
 * table, so it gets a pair of triggers (insert and update) that abort with the same
 * message, exactly as the owner rule of the original ledger tables does. A rule is
 * written once, as a SQL boolean expression over bare column names, and each
 * database gets its own form.
 *
 * A CHECK passes when its expression is NULL (unknown); the triggers keep that
 * semantic by aborting only when `NOT (expression)` is true.
 *
 * Writing an expression: use only the columns you list in $columns, never name a
 * column inside a quoted string, and prefer portable functions (length, upper, coalesce).
 */
final class TableChecks
{
    /** The one-owner rule every ledger table carries (user_id XOR team_id). */
    public const OWNER_RULE = '(user_id IS NOT NULL AND team_id IS NULL) OR (user_id IS NULL AND team_id IS NOT NULL)';

    /** For tables that may also hold global rows (no owner at all) but never two owners. */
    public const OWNER_OR_GLOBAL_RULE = 'user_id IS NULL OR team_id IS NULL';

    /**
     * @param  list<string>  $columns  every column the expression reads
     */
    public static function add(string $table, string $name, string $expression, array $columns, ?string $message = null): void
    {
        $message ??= "{$table}: rule {$name} violated";

        if (DB::getDriverName() === 'sqlite') {
            $new = self::qualify($expression, $columns);
            $safe = str_replace("'", "''", $message);

            DB::unprepared("CREATE TRIGGER IF NOT EXISTS {$table}_{$name}_insert BEFORE INSERT ON {$table} WHEN NOT ({$new}) BEGIN SELECT RAISE(ABORT, '{$safe}'); END");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS {$table}_{$name}_update BEFORE UPDATE ON {$table} WHEN NOT ({$new}) BEGIN SELECT RAISE(ABORT, '{$safe}'); END");

            return;
        }

        $constraint = "{$table}_{$name}";
        $exists = DB::selectOne('SELECT 1 AS present FROM pg_constraint WHERE conname = ?', [$constraint]);

        if ($exists === null) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$expression})");
        }
    }

    public static function drop(string $table, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_{$name}_insert");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_{$name}_update");

            return;
        }

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_{$name}");
    }

    /** Exactly one owner. */
    public static function ownerRule(string $table): void
    {
        self::add($table, 'owner_rule', self::OWNER_RULE, ['user_id', 'team_id'], "{$table}: a ledger row belongs to exactly one owner (user_id or team_id)");
    }

    /** At most one owner: a row with neither is a global (shared reference) row. */
    public static function ownerOrGlobalRule(string $table): void
    {
        self::add($table, 'owner_rule', self::OWNER_OR_GLOBAL_RULE, ['user_id', 'team_id'], "{$table}: a row has at most one owner (user_id or team_id); neither means global");
    }

    /** Prefix each listed column with NEW. for a SQLite trigger body. */
    private static function qualify(string $expression, array $columns): string
    {
        $alternatives = implode('|', array_map(fn (string $c) => preg_quote($c, '/'), $columns));

        // Skip anything inside single quotes, so a literal that happens to equal a column name stays a literal.
        return (string) preg_replace_callback(
            "/'(?:[^']|'')*'|(?<![\\w.])({$alternatives})(?![\\w(])/",
            fn (array $m) => isset($m[1]) ? 'NEW.'.$m[1] : $m[0],
            $expression,
        );
    }
}
