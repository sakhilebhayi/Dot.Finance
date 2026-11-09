<?php

use App\Support\Database\Checks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-44 accounting core: the double-entry layer beside the cash ledger.
 *
 * Every table carries the owner pair (user_id XOR team_id) with the same rule as the rest of the
 * ledger. Journal rows are append-only: on both engines a trigger refuses UPDATE and DELETE of a
 * posted line, and refuses every change to an entry except the single one-way move that links a
 * reversal (status posted to reversed, reversed_by_entry_id from NULL). On PostgreSQL a deferred
 * constraint trigger additionally refuses to commit an entry that does not balance or has fewer
 * than two lines; SQLite cannot defer a check to commit, so there the poster's own check and
 * `finance:gl:verify` carry the guarantee.
 *
 * Foreign keys to users and teams are RESTRICT: an accounting ledger is not removed as a side effect
 * of removing a person. (The delete-account flow tells the person to export and close first.)
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->settings();
        $this->glAccounts();
        $this->periods();
        $this->entries();
        $this->lines();
        $this->backfillRuns();
        $this->mappingColumns();

        foreach (['ledger_settings', 'gl_accounts', 'accounting_periods', 'journal_entries', 'journal_lines', 'gl_backfill_runs'] as $table) {
            Checks::ownerRule($table);
        }

        $this->checks();
        $this->immutability();
    }

    public function down(): void
    {
        $this->dropImmutability();

        foreach (['accounts', 'categories'] as $table) {
            if (Schema::hasColumn($table, 'gl_account_id')) {
                if (DB::getDriverName() === 'sqlite') {
                    Schema::table($table, function (Blueprint $t) use ($table) {
                        $t->dropIndex("{$table}_gl_account_id_index");
                        $t->dropColumn('gl_account_id');
                    });
                } else {
                    Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('gl_account_id'));
                }
            }
        }

        foreach (['gl_backfill_runs', 'journal_lines', 'journal_entries', 'accounting_periods', 'gl_accounts', 'ledger_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function owner(Blueprint $table): void
    {
        $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
        $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
    }

    private function settings(): void
    {
        Schema::create('ledger_settings', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->string('base_currency', 3);
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->string('chart_template', 20)->nullable();
            $table->timestamp('accounting_enabled_at')->nullable();
            $table->date('accounting_start_date')->nullable();
            $table->date('books_closed_through')->nullable();
            $table->unsignedSmallInteger('posting_rule_version')->default(1);
            $table->timestamps();

            $table->unique('user_id');
            $table->unique('team_id');
        });
    }

    private function glAccounts(): void
    {
        Schema::create('gl_accounts', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('class', 10);
            $table->string('subtype', 40)->nullable();
            $table->string('normal_side', 6);
            $table->foreignId('parent_id')->nullable()->constrained('gl_accounts')->restrictOnDelete();
            $table->string('currency', 3)->nullable();
            $table->string('system_key', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_postable')->default(true);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'code']);
            $table->unique(['team_id', 'code']);
            $table->unique(['user_id', 'system_key']);
            $table->unique(['team_id', 'system_key']);
        });
    }

    private function periods(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('fiscal_year');
            $table->boolean('is_year_end')->default(false);
            $table->string('status', 8)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by_user_id')->nullable();
            $table->unsignedBigInteger('closing_entry_id')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->unsignedBigInteger('reopened_by_user_id')->nullable();
            $table->string('reopen_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'start_date']);
            $table->unique(['team_id', 'start_date']);
        });
    }

    private function entries(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->unsignedBigInteger('entry_number');
            $table->date('entry_date');
            $table->foreignId('period_id')->constrained('accounting_periods')->restrictOnDelete();
            $table->string('status', 10)->default('posted');
            $table->string('source_type', 30);
            $table->string('source_id', 64);
            $table->unsignedInteger('source_version')->default(1);
            $table->string('memo', 500);
            $table->string('currency', 3);
            $table->unsignedBigInteger('total_cents');
            $table->foreignId('reverses_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversed_by_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->unsignedBigInteger('posted_by_user_id')->nullable();
            $table->timestamp('posted_at');
            $table->unsignedSmallInteger('posting_rule_version')->default(1);
            $table->char('entry_hash', 64);
            $table->char('prev_hash', 64)->nullable();

            $table->unique(['user_id', 'entry_number']);
            $table->unique(['team_id', 'entry_number']);
            $table->unique(['user_id', 'source_type', 'source_id', 'source_version'], 'journal_entries_user_source_unique');
            $table->unique(['team_id', 'source_type', 'source_id', 'source_version'], 'journal_entries_team_source_unique');
            $table->index(['user_id', 'entry_date']);
            $table->index(['team_id', 'entry_date']);
            $table->index('period_id');
            $table->index(['source_type', 'source_id']);
        });
    }

    private function lines(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->date('entry_date');
            $table->foreignId('gl_account_id')->constrained('gl_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('debit_cents')->default(0);
            $table->unsignedBigInteger('credit_cents')->default(0);
            $table->string('txn_currency', 3)->nullable();
            $table->unsignedBigInteger('txn_amount_cents')->nullable();
            $table->unsignedBigInteger('fx_rate_e8')->nullable();
            $table->unsignedBigInteger('party_id')->nullable();
            $table->foreignId('cash_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->unsignedBigInteger('tax_code_id')->nullable();
            $table->string('dimension', 80)->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['journal_entry_id', 'line_no']);
            $table->index(['gl_account_id', 'entry_date']);
            $table->index('cash_account_id');
            $table->index('category_id');
            $table->index('party_id');
            $table->index('dimension');
        });
    }

    private function backfillRuns(): void
    {
        Schema::create('gl_backfill_runs', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->string('mode', 10);
            $table->string('status', 12)->default('running');
            $table->string('cursor_phase', 20)->nullable();
            $table->unsignedBigInteger('cursor_id')->nullable();
            $table->unsignedBigInteger('started_by_user_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('sources_total')->default(0);
            $table->unsignedInteger('entries_posted')->default(0);
            $table->unsignedInteger('lines_posted')->default(0);
            $table->json('exceptions')->nullable();
            $table->json('report')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->index(['team_id', 'id']);
        });
    }

    private function mappingColumns(): void
    {
        foreach (['accounts', 'categories'] as $table) {
            if (Schema::hasColumn($table, 'gl_account_id')) {
                continue;
            }

            if (DB::getDriverName() === 'sqlite') {
                // SQLite cannot add a foreign key to an existing table without rebuilding it, and a rebuild would
                // silently drop the owner-rule and CHECK triggers the table carries. The column is added plain
                // there (tests); PostgreSQL (production) gets the real foreign key.
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('gl_account_id')->nullable()->index());

                continue;
            }

            Schema::table($table, fn (Blueprint $t) => $t->foreignId('gl_account_id')->nullable()->constrained('gl_accounts')->restrictOnDelete());
        }
    }

    private function checks(): void
    {
        Checks::add('ledger_settings', 'ledger_settings_currency_format', ['pgsql' => "base_currency ~ '^[A-Z]{3}\$'", 'sqlite' => "length(base_currency) = 3 AND base_currency GLOB '[A-Z][A-Z][A-Z]'"], ['base_currency']);
        Checks::add('ledger_settings', 'ledger_settings_fiscal_month', 'fiscal_year_start_month BETWEEN 1 AND 12', ['fiscal_year_start_month']);

        Checks::add('gl_accounts', 'gl_accounts_class', "class IN ('asset','liability','equity','income','expense')", ['class']);
        Checks::add('gl_accounts', 'gl_accounts_side', "normal_side IN ('debit','credit')", ['normal_side']);
        Checks::add('gl_accounts', 'gl_accounts_currency_format', Checks::currency('currency'), ['currency']);

        Checks::add('accounting_periods', 'accounting_periods_status', "status IN ('open','closed')", ['status']);
        Checks::add('accounting_periods', 'accounting_periods_range', 'end_date >= start_date', ['start_date', 'end_date']);

        Checks::add('journal_entries', 'journal_entries_status', "status IN ('posted','reversed')", ['status']);
        Checks::add('journal_entries', 'journal_entries_number', 'entry_number >= 1', ['entry_number']);
        Checks::add('journal_entries', 'journal_entries_total', 'total_cents > 0', ['total_cents']);
        Checks::add('journal_entries', 'journal_entries_currency_format', ['pgsql' => "currency ~ '^[A-Z]{3}\$'", 'sqlite' => "length(currency) = 3 AND currency GLOB '[A-Z][A-Z][A-Z]'"], ['currency']);

        Checks::add('journal_lines', 'journal_lines_one_side', '(debit_cents = 0 AND credit_cents > 0) OR (credit_cents = 0 AND debit_cents > 0)', ['debit_cents', 'credit_cents']);
        Checks::add('journal_lines', 'journal_lines_txn_pair', '(txn_currency IS NULL AND txn_amount_cents IS NULL) OR (txn_currency IS NOT NULL AND txn_amount_cents IS NOT NULL AND txn_amount_cents > 0)', ['txn_currency', 'txn_amount_cents']);
        Checks::add('journal_lines', 'journal_lines_currency_format', Checks::currency('txn_currency'), ['txn_currency']);
    }

    private function immutability(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $msg = 'journal rows are append-only: post a reversing entry instead';
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS journal_lines_no_update BEFORE UPDATE ON journal_lines BEGIN SELECT RAISE(ABORT, '{$msg}'); END");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS journal_lines_no_delete BEFORE DELETE ON journal_lines BEGIN SELECT RAISE(ABORT, '{$msg}'); END");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS journal_entries_no_delete BEFORE DELETE ON journal_entries BEGIN SELECT RAISE(ABORT, '{$msg}'); END");

            // The only change an entry may ever undergo: being marked reversed, once, by its reversal.
            $same = collect(['user_id', 'team_id', 'entry_number', 'entry_date', 'period_id', 'source_type', 'source_id', 'source_version', 'memo', 'currency', 'total_cents', 'reverses_entry_id', 'posted_by_user_id', 'posted_at', 'posting_rule_version', 'entry_hash', 'prev_hash'])
                ->map(fn ($c) => "OLD.{$c} IS NEW.{$c}")->implode(' AND ');
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS journal_entries_guard_update BEFORE UPDATE ON journal_entries WHEN NOT (OLD.status = 'posted' AND NEW.status = 'reversed' AND OLD.reversed_by_entry_id IS NULL AND NEW.reversed_by_entry_id IS NOT NULL AND {$same}) BEGIN SELECT RAISE(ABORT, '{$msg}'); END");

            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION journal_rows_immutable() RETURNS trigger AS $$
BEGIN
    IF TG_TABLE_NAME = 'journal_entries' AND TG_OP = 'UPDATE'
       AND OLD.status = 'posted' AND NEW.status = 'reversed'
       AND OLD.reversed_by_entry_id IS NULL AND NEW.reversed_by_entry_id IS NOT NULL
       AND (to_jsonb(NEW) - 'status' - 'reversed_by_entry_id') = (to_jsonb(OLD) - 'status' - 'reversed_by_entry_id') THEN
        RETURN NEW;
    END IF;

    RAISE EXCEPTION 'journal rows are append-only: % on % is not allowed (post a reversing entry instead)', TG_OP, TG_TABLE_NAME
        USING ERRCODE = 'integrity_constraint_violation';
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER journal_lines_immutable BEFORE UPDATE OR DELETE ON journal_lines FOR EACH ROW EXECUTE FUNCTION journal_rows_immutable()');
        DB::unprepared('CREATE TRIGGER journal_entries_immutable BEFORE UPDATE OR DELETE ON journal_entries FOR EACH ROW EXECUTE FUNCTION journal_rows_immutable()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION journal_entry_must_balance() RETURNS trigger AS $$
DECLARE
    eid bigint;
    d numeric;
    c numeric;
    n integer;
BEGIN
    IF TG_TABLE_NAME = 'journal_entries' THEN
        eid := NEW.id;
    ELSE
        eid := NEW.journal_entry_id;
    END IF;

    SELECT COALESCE(SUM(debit_cents), 0), COALESCE(SUM(credit_cents), 0), COUNT(*) INTO d, c, n
    FROM journal_lines WHERE journal_entry_id = eid;

    IF n < 2 OR d <> c THEN
        RAISE EXCEPTION 'journal entry % does not balance (debits %, credits %, % line(s))', eid, d, c, n
            USING ERRCODE = 'integrity_constraint_violation';
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE CONSTRAINT TRIGGER journal_lines_balance AFTER INSERT ON journal_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION journal_entry_must_balance()');
        DB::unprepared('CREATE CONSTRAINT TRIGGER journal_entries_balance AFTER INSERT ON journal_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION journal_entry_must_balance()');
    }

    private function dropImmutability(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['journal_lines_no_update', 'journal_lines_no_delete', 'journal_entries_no_delete', 'journal_entries_guard_update'] as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }

            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_balance ON journal_lines');
        DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_balance ON journal_entries');
        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_immutable ON journal_lines');
        DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_immutable ON journal_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS journal_entry_must_balance()');
        DB::unprepared('DROP FUNCTION IF EXISTS journal_rows_immutable()');
    }
};
