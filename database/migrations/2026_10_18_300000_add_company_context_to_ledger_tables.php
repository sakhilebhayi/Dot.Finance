<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every ledger row now belongs to exactly one OWNER CONTEXT: a person (user_id) or a
 * company (team_id of an InfoDot company team).
 *
 *  - team_id is added next to user_id on every ledger table, and user_id becomes
 *    nullable so a company row has no personal owner.
 *  - A database rule makes "exactly one of user_id, team_id" impossible to break:
 *    a CHECK constraint on PostgreSQL, equivalent triggers on SQLite (which cannot
 *    add a CHECK to an existing table).
 *  - Existing rows are not touched. They keep their user_id and get team_id NULL,
 *    so every legacy row stays in its owner's personal ledger, exactly as before.
 *  - The unique rules that protected one user (an import fingerprint, one weekly
 *    summary per ISO week) now protect a company the same way.
 *  - The audit log also records WHO acted (actor_user_id), because a company's log
 *    holds the actions of many people. Legacy rows keep it NULL: history is not rewritten.
 *
 * Idempotent: every step checks what is already there, so re-running is harmless.
 *
 * Reversible only while no company data exists. Rolling back past company rows would
 * delete a company's books, so down() refuses and leaves everything as it was.
 *
 * BACK UP THE DATABASE BEFORE RUNNING THIS. On SQLite and PostgreSQL the "user_id
 * becomes nullable" step rewrites or alters the ledger tables.
 *
 * Not wrapped in a transaction ($withinTransaction = false), on purpose. SQLite can only
 * make a column nullable by rebuilding the table, and the rebuild only keeps child rows
 * (a company's transactions under its accounts) when foreign keys can be switched off, which
 * SQLite refuses inside a transaction. Without that, dropping the old accounts table would
 * cascade-delete every transaction. The migration is idempotent instead: if it stops half
 * way, run it again. (PostgreSQL alters the column in place and has no such problem.)
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var list<string> */
    private const TABLES = ['accounts', 'categories', 'budgets', 'transactions', 'recurring_rules', 'finance_audit_log', 'ai_insights'];

    public function up(): void
    {
        $this->refuseToRebuildInsideATransaction();

        foreach (self::TABLES as $table) {
            $this->addTeamColumn($table);
        }

        foreach (self::TABLES as $table) {
            $this->makeUserNullable($table);
        }

        $this->addUniqueRules();
        $this->addIndexes();
        $this->addActorColumn();

        foreach (self::TABLES as $table) {
            $this->installOwnerRule($table);
        }
    }

    public function down(): void
    {
        $this->refuseToRebuildInsideATransaction();

        $company = 0;
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'team_id')) {
                $company += DB::table($table)->whereNotNull('team_id')->count();
            }
        }

        if ($company > 0) {
            throw new RuntimeException("Refusing to roll back: {$company} company ledger row(s) exist and would be destroyed. Export or archive the company ledgers first (this migration cannot be rolled back over company data).");
        }

        foreach (self::TABLES as $table) {
            $this->dropOwnerRule($table);
        }

        $this->dropActorColumn();
        $this->dropIndexes();
        $this->dropUniqueRules();

        foreach (array_reverse(self::TABLES) as $table) {
            $this->dropTeamColumn($table);
        }

        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->whereNull('user_id')->doesntExist()) {
                $this->makeUserRequired($table);
            }
        }
    }

    /**
     * On SQLite the column changes rebuild the table, and inside a transaction SQLite keeps
     * foreign keys on, so the rebuild would cascade-delete child rows. Empty tables are safe
     * (nothing to lose); anything else must be run outside a transaction.
     */
    private function refuseToRebuildInsideATransaction(): void
    {
        if (DB::getDriverName() !== 'sqlite' || DB::transactionLevel() === 0) {
            return;
        }

        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Refusing to run inside a transaction: on SQLite this rebuilds the ledger tables and would cascade-delete rows ({$table} has data). Run it with `php artisan migrate`, outside a transaction.");
            }
        }
    }

    // ── columns ──────────────────────────────────────────────────────────

    private function addTeamColumn(string $table): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'team_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            // restrict, never cascade: deleting a team must not silently delete a company's books.
            $blueprint->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
        });
    }

    private function dropTeamColumn(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'team_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropConstrainedForeignId('team_id');
        });
    }

    private function makeUserNullable(string $table): void
    {
        if (! Schema::hasTable($table) || ! $this->userIsRequired($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    private function makeUserRequired(string $table): void
    {
        if (! Schema::hasColumn($table, 'user_id') || $this->userIsRequired($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }

    private function userIsRequired(string $table): bool
    {
        foreach (Schema::getColumns($table) as $column) {
            if ($column['name'] === 'user_id') {
                return ! $column['nullable'];
            }
        }

        return false;
    }

    private function addActorColumn(): void
    {
        if (Schema::hasTable('finance_audit_log') && ! Schema::hasColumn('finance_audit_log', 'actor_user_id')) {
            Schema::table('finance_audit_log', function (Blueprint $table) {
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    private function dropActorColumn(): void
    {
        if (Schema::hasTable('finance_audit_log') && Schema::hasColumn('finance_audit_log', 'actor_user_id')) {
            Schema::table('finance_audit_log', function (Blueprint $table) {
                $table->dropConstrainedForeignId('actor_user_id');
            });
        }
    }

    // ── uniqueness and indexes ───────────────────────────────────────────

    private function addUniqueRules(): void
    {
        if (! $this->hasIndex('transactions', 'transactions_team_id_import_fingerprint_unique')) {
            Schema::table('transactions', fn (Blueprint $t) => $t->unique(['team_id', 'import_fingerprint']));
        }

        if (! $this->hasIndex('ai_insights', 'ai_insights_team_id_iso_week_unique')) {
            Schema::table('ai_insights', fn (Blueprint $t) => $t->unique(['team_id', 'iso_week']));
        }
    }

    private function dropUniqueRules(): void
    {
        if ($this->hasIndex('transactions', 'transactions_team_id_import_fingerprint_unique')) {
            Schema::table('transactions', fn (Blueprint $t) => $t->dropUnique(['team_id', 'import_fingerprint']));
        }

        if ($this->hasIndex('ai_insights', 'ai_insights_team_id_iso_week_unique')) {
            Schema::table('ai_insights', fn (Blueprint $t) => $t->dropUnique(['team_id', 'iso_week']));
        }
    }

    /** @return array<string, list<string>> index name => columns */
    private function indexes(): array
    {
        return [
            'transactions_team_id_date_index' => ['transactions', ['team_id', 'date']],
            'accounts_team_id_index' => ['accounts', ['team_id']],
            'categories_team_id_index' => ['categories', ['team_id']],
            'budgets_team_id_index' => ['budgets', ['team_id']],
            'recurring_rules_team_id_index' => ['recurring_rules', ['team_id']],
            'finance_audit_log_team_id_created_at_index' => ['finance_audit_log', ['team_id', 'created_at']],
        ];
    }

    private function addIndexes(): void
    {
        foreach ($this->indexes() as $name => [$table, $columns]) {
            if (! $this->hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    private function dropIndexes(): void
    {
        foreach ($this->indexes() as $name => [$table, $columns]) {
            if ($this->hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        return collect(Schema::getIndexes($table))->contains(fn (array $index) => $index['name'] === $name);
    }

    // ── the owner rule ───────────────────────────────────────────────────

    private const RULE = '(user_id IS NOT NULL AND team_id IS NULL) OR (user_id IS NULL AND team_id IS NOT NULL)';

    private function installOwnerRule(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            // SQLite cannot add a CHECK to an existing table, so equivalent triggers do the same job.
            $message = "{$table}: a ledger row belongs to exactly one owner (user_id or team_id)";
            $when = 'NOT ((NEW.user_id IS NOT NULL AND NEW.team_id IS NULL) OR (NEW.user_id IS NULL AND NEW.team_id IS NOT NULL))';

            DB::unprepared("CREATE TRIGGER IF NOT EXISTS {$table}_owner_rule_insert BEFORE INSERT ON {$table} WHEN {$when} BEGIN SELECT RAISE(ABORT, '{$message}'); END");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS {$table}_owner_rule_update BEFORE UPDATE OF user_id, team_id ON {$table} WHEN {$when} BEGIN SELECT RAISE(ABORT, '{$message}'); END");

            return;
        }

        $constraint = "{$table}_owner_rule";
        $exists = DB::selectOne('SELECT 1 AS present FROM pg_constraint WHERE conname = ?', [$constraint]);

        if ($exists === null) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK (".self::RULE.')');
        }
    }

    private function dropOwnerRule(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_owner_rule_insert");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_owner_rule_update");

            return;
        }

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_owner_rule");
    }
};
