<?php

use App\Support\Database\TableChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-44 Bank: statement import and reconciliation.
 *
 *   bank_statements            one imported statement file for one cash account (dates, balances, file hash)
 *   bank_statement_lines       what the bank says, one row per line, signed minor units, with a fingerprint
 *                              that makes re-importing the same or an overlapping statement safe
 *   bank_reconciliations       a session for one account up to a statement date; completed only at difference zero
 *   bank_matches               a group of statement lines and cash-ledger rows that say the same thing
 *                              (one to one, one to many, many to one); released, never deleted
 *   bank_reconciliation_events append-only history of everything done in a session
 *   bank_adjustments           bank charges, interest and authorised variance entries made during a session
 *   bank_rules                 "if the narrative contains X then category Y" suggestions
 *
 * plus three nullable columns on transactions (cleared_at, bank_match_id, reconciliation_id) that mark a cash-ledger
 * row as cleared (matched to a statement line) and reconciled (its session completed). Nothing that read the cash
 * ledger before reads these.
 *
 * Every table carries the owner pair (user_id XOR team_id). On SQLite (tests) the foreign keys on the three new
 * transactions columns are left out, for the reason the accounting migration records: adding a foreign key to an
 * existing SQLite table rebuilds it and drops its triggers. PostgreSQL (production) gets the real keys.
 */
return new class extends Migration
{
    /** The columns of a matched cash-ledger row that may not change (written out here so the migration never depends on app code). */
    private const LOCKED = ['account_id', 'amount_cents', 'date', 'type', 'transfer_direction', 'transfer_group', 'currency', 'deleted_at'];

    private const TABLES = ['bank_statements', 'bank_reconciliations', 'bank_matches', 'bank_statement_lines', 'bank_reconciliation_events', 'bank_adjustments', 'bank_rules'];

    public function up(): void
    {
        $this->statements();
        $this->reconciliations();
        $this->matches();
        $this->lines();
        $this->events();
        $this->adjustments();
        $this->rules();
        $this->transactionColumns();

        foreach (self::TABLES as $table) {
            TableChecks::ownerRule($table);
        }

        $this->checks();
        $this->immutability();
    }

    public function down(): void
    {
        $this->dropImmutability();

        if (Schema::hasColumn('transactions', 'bank_match_id')) {
            if (DB::getDriverName() === 'sqlite') {
                Schema::table('transactions', function (Blueprint $t) {
                    $t->dropIndex('transactions_bank_match_id_index');
                    $t->dropIndex('transactions_reconciliation_id_index');
                    $t->dropIndex('transactions_cleared_at_index');
                    $t->dropColumn(['cleared_at', 'bank_match_id', 'reconciliation_id']);
                });
            } else {
                Schema::table('transactions', function (Blueprint $t) {
                    $t->dropForeign(['bank_match_id']);
                    $t->dropForeign(['reconciliation_id']);
                    $t->dropIndex('transactions_bank_match_id_index');
                    $t->dropIndex('transactions_reconciliation_id_index');
                    $t->dropIndex('transactions_cleared_at_index');
                    $t->dropColumn(['cleared_at', 'bank_match_id', 'reconciliation_id']);
                });
            }
        }

        foreach (['bank_reconciliations_one_draft', 'bank_reconciliations_one_completed_per_date'] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }

        $this->dropChecks();

        // Break the circular reference (lines point at matches, matches at reconciliations) before dropping.
        foreach (['bank_rules', 'bank_adjustments', 'bank_reconciliation_events', 'bank_statement_lines', 'bank_matches', 'bank_reconciliations', 'bank_statements'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function owner(Blueprint $table): void
    {
        $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
        $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
    }

    private function statements(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('source', 10);
            $table->string('original_name', 150);
            $table->char('file_sha256', 64);
            $table->char('currency', 3);
            $table->date('date_from');
            $table->date('date_to');
            $table->bigInteger('opening_balance_cents')->nullable();
            $table->bigInteger('closing_balance_cents')->nullable();
            // whether opening + the lines' net equals closing; null when either balance is not known
            $table->boolean('balances_foot')->nullable();
            $table->unsignedInteger('line_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->bigInteger('in_cents')->default(0);
            $table->bigInteger('out_cents')->default(0);
            $table->text('mapping')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'account_id', 'file_sha256'], 'bank_statements_user_file_unique');
            $table->unique(['team_id', 'account_id', 'file_sha256'], 'bank_statements_team_file_unique');
            $table->index(['account_id', 'date_to']);
        });
    }

    private function reconciliations(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('bank_statement_id')->nullable()->constrained('bank_statements')->restrictOnDelete();
            $table->string('status', 10)->default('draft');
            $table->date('statement_date');
            // On an account's first reconciliation, entries dated before this are taken as already agreed with the bank (the opening-balance
            // check shows whether they are). Null on every later session: those start from what the last one cleared.
            $table->date('baseline_date')->nullable();
            $table->bigInteger('statement_opening_cents')->nullable();
            $table->bigInteger('statement_closing_cents');
            $table->char('currency', 3);
            // set when the session completes: the ledger's cleared balance, and statement minus ledger (zero at completion)
            $table->bigInteger('ledger_cleared_cents')->nullable();
            $table->bigInteger('difference_cents')->nullable();
            $table->bigInteger('variance_cents')->default(0);
            $table->string('variance_reason', 255)->nullable();
            $table->foreignId('variance_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('variance_transaction_id')->nullable();
            // statement lines still unmatched when the session completed (only allowed together with a variance)
            $table->unsignedInteger('unmatched_accepted')->default(0);
            $table->text('snapshot')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedSmallInteger('reopened_count')->default(0);
            $table->timestamp('last_reopened_at')->nullable();
            $table->string('last_reopen_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status', 'statement_date']);
        });

        // One open session per account, and one completed session per account and statement date.
        DB::statement("CREATE UNIQUE INDEX bank_reconciliations_one_draft ON bank_reconciliations (account_id) WHERE status = 'draft'");
        DB::statement("CREATE UNIQUE INDEX bank_reconciliations_one_completed_per_date ON bank_reconciliations (account_id, statement_date) WHERE status = 'completed'");
    }

    private function matches(): void
    {
        Schema::create('bank_matches', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('bank_reconciliation_id')->constrained('bank_reconciliations')->restrictOnDelete();
            // correlates a bulk insert with the ids it produced (a uuid per match)
            $table->char('ref', 36);
            $table->string('method', 12);
            $table->string('shape', 14);
            $table->unsignedSmallInteger('confidence')->nullable();
            $table->string('reason', 500);
            $table->bigInteger('amount_cents');
            $table->unsignedSmallInteger('line_count');
            $table->unsignedSmallInteger('transaction_count');
            $table->string('state', 10)->default('active');
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('matched_at');
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('release_reason', 255)->nullable();
            // the ids the match held, kept when it is released so the history still says what was matched
            $table->text('snapshot')->nullable();
            $table->timestamps();

            $table->unique('ref');
            $table->index(['bank_reconciliation_id', 'state']);
            $table->index(['account_id', 'state']);
        });
    }

    private function lines(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('bank_statement_id')->constrained('bank_statements')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->unsignedInteger('line_no');
            $table->date('posted_on');
            // signed minor units: money in is positive, money out negative; never zero
            $table->bigInteger('amount_cents');
            $table->char('currency', 3);
            $table->string('description', 255);
            $table->string('reference', 100)->nullable();
            $table->string('external_id', 100)->nullable();
            $table->bigInteger('balance_after_cents')->nullable();
            $table->char('fingerprint', 64);
            $table->foreignId('bank_match_id')->nullable()->constrained('bank_matches')->restrictOnDelete();
            $table->foreignId('reconciliation_id')->nullable()->constrained('bank_reconciliations')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'account_id', 'fingerprint'], 'bank_lines_user_fingerprint_unique');
            $table->unique(['team_id', 'account_id', 'fingerprint'], 'bank_lines_team_fingerprint_unique');
            $table->index(['account_id', 'posted_on']);
            $table->index(['bank_statement_id', 'line_no']);
            $table->index('bank_match_id');
            $table->index(['account_id', 'reconciliation_id']);
        });
    }

    private function events(): void
    {
        Schema::create('bank_reconciliation_events', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('bank_reconciliation_id')->constrained('bank_reconciliations')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('event', 30);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('data')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['bank_reconciliation_id', 'id'], 'bank_events_session_index');
        });
    }

    private function adjustments(): void
    {
        Schema::create('bank_adjustments', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('bank_reconciliation_id')->constrained('bank_reconciliations')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('kind', 12);
            $table->string('direction', 3);
            $table->bigInteger('amount_cents');
            $table->foreignId('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->foreignId('bank_statement_line_id')->nullable()->constrained('bank_statement_lines')->restrictOnDelete();
            $table->string('reason', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('bank_reconciliation_id');
        });
    }

    private function rules(): void
    {
        Schema::create('bank_rules', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('name', 80);
            $table->string('match_field', 12)->default('any');
            $table->string('operator', 12)->default('contains');
            $table->string('pattern', 120);
            $table->string('direction', 3)->default('any');
            $table->bigInteger('min_cents')->nullable();
            $table->bigInteger('max_cents')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('new_description', 120)->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('times_applied')->default(0);
            $table->timestamp('last_applied_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'priority']);
        });
    }

    private function transactionColumns(): void
    {
        if (Schema::hasColumn('transactions', 'bank_match_id')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('transactions', function (Blueprint $t) {
                $t->timestamp('cleared_at')->nullable()->index();
                $t->unsignedBigInteger('bank_match_id')->nullable()->index();
                $t->unsignedBigInteger('reconciliation_id')->nullable()->index();
            });

            return;
        }

        Schema::table('transactions', function (Blueprint $t) {
            $t->timestamp('cleared_at')->nullable()->index();
            $t->foreignId('bank_match_id')->nullable()->constrained('bank_matches')->restrictOnDelete();
            $t->foreignId('reconciliation_id')->nullable()->constrained('bank_reconciliations')->restrictOnDelete();
            $t->index('bank_match_id');
            $t->index('reconciliation_id');
        });
    }

    private function checks(): void
    {
        $currency = fn (string $table) => TableChecks::add($table, 'currency_format', 'length(currency) = 3 AND currency = upper(currency)', ['currency'], "{$table}: currency is three upper-case letters");

        foreach (['bank_statements', 'bank_reconciliations', 'bank_statement_lines'] as $table) {
            $currency($table);
        }

        TableChecks::add('bank_statements', 'source_values', "source IN ('csv', 'ofx', 'qif', 'manual')", ['source'], 'bank_statements: unknown source');
        TableChecks::add('bank_statements', 'dates_ordered', 'date_from <= date_to', ['date_from', 'date_to'], 'bank_statements: the statement starts after it ends');

        TableChecks::add('bank_statement_lines', 'amount_nonzero', 'amount_cents <> 0', ['amount_cents'], 'bank_statement_lines: a statement line is never zero');
        TableChecks::add('bank_statement_lines', 'fingerprint_required', 'length(fingerprint) = 64', ['fingerprint'], 'bank_statement_lines: a fingerprint is required');

        TableChecks::add('bank_reconciliations', 'status_values', "status IN ('draft', 'completed')", ['status'], 'bank_reconciliations: status is draft or completed');
        // A session completes only at difference zero (the variance entry, when there is one, is already inside the ledger figure).
        TableChecks::add('bank_reconciliations', 'completed_at_zero', "status <> 'completed' OR (coalesce(difference_cents, -1) = 0 AND completed_at IS NOT NULL AND ledger_cleared_cents IS NOT NULL)", ['status', 'difference_cents', 'completed_at', 'ledger_cleared_cents'], 'bank_reconciliations: a completed reconciliation has a difference of exactly zero');
        TableChecks::add('bank_reconciliations', 'variance_has_reason', "variance_cents = 0 OR (variance_reason IS NOT NULL AND length(coalesce(variance_reason, '')) > 0)", ['variance_cents', 'variance_reason'], 'bank_reconciliations: a variance needs a reason');

        TableChecks::add('bank_matches', 'method_values', "method IN ('auto', 'manual', 'rule', 'created', 'transfer', 'variance')", ['method'], 'bank_matches: unknown method');
        TableChecks::add('bank_matches', 'shape_values', "shape IN ('one_to_one', 'one_to_many', 'many_to_one', 'many_to_many', 'ledger_only')", ['shape'], 'bank_matches: unknown shape');
        TableChecks::add('bank_matches', 'state_values', "state IN ('active', 'released')", ['state'], 'bank_matches: state is active or released');
        TableChecks::add('bank_matches', 'release_pair', "(state = 'released') = (released_at IS NOT NULL)", ['state', 'released_at'], 'bank_matches: a released match has a release time, an active one does not');
        TableChecks::add('bank_matches', 'confidence_range', 'confidence IS NULL OR (confidence >= 0 AND confidence <= 100)', ['confidence'], 'bank_matches: confidence is 0 to 100');

        TableChecks::add('bank_adjustments', 'kind_values', "kind IN ('bank_charge', 'interest', 'variance', 'other')", ['kind'], 'bank_adjustments: unknown kind');
        TableChecks::add('bank_adjustments', 'direction_values', "direction IN ('in', 'out')", ['direction'], 'bank_adjustments: direction is in or out');
        TableChecks::add('bank_adjustments', 'amount_positive', 'amount_cents > 0', ['amount_cents'], 'bank_adjustments: an adjustment is greater than zero');
        TableChecks::add('bank_adjustments', 'reason_required', 'length(reason) > 0', ['reason'], 'bank_adjustments: an adjustment needs a reason');

        TableChecks::add('bank_rules', 'match_field_values', "match_field IN ('description', 'reference', 'any')", ['match_field'], 'bank_rules: unknown match field');
        TableChecks::add('bank_rules', 'operator_values', "operator IN ('contains', 'starts_with', 'equals')", ['operator'], 'bank_rules: unknown operator');
        TableChecks::add('bank_rules', 'direction_values', "direction IN ('in', 'out', 'any')", ['direction'], 'bank_rules: direction is in, out or any');
        TableChecks::add('bank_rules', 'pattern_required', 'length(pattern) > 0', ['pattern'], 'bank_rules: a rule needs something to look for');
        TableChecks::add('bank_rules', 'amount_band', 'min_cents IS NULL OR max_cents IS NULL OR min_cents <= max_cents', ['min_cents', 'max_cents'], 'bank_rules: the smallest amount is above the largest');
    }

    /** @return array<string, list<string>> */
    private function ruleNames(): array
    {
        return [
            'bank_statements' => ['owner_rule', 'currency_format', 'source_values', 'dates_ordered'],
            'bank_statement_lines' => ['owner_rule', 'currency_format', 'amount_nonzero', 'fingerprint_required'],
            'bank_reconciliations' => ['owner_rule', 'currency_format', 'status_values', 'completed_at_zero', 'variance_has_reason'],
            'bank_matches' => ['owner_rule', 'method_values', 'shape_values', 'state_values', 'release_pair', 'confidence_range'],
            'bank_adjustments' => ['owner_rule', 'kind_values', 'direction_values', 'amount_positive', 'reason_required'],
            'bank_rules' => ['owner_rule', 'match_field_values', 'operator_values', 'direction_values', 'pattern_required', 'amount_band'],
            'bank_reconciliation_events' => ['owner_rule'],
        ];
    }

    private function dropChecks(): void
    {
        foreach ($this->ruleNames() as $table => $names) {
            foreach ($names as $name) {
                TableChecks::drop($table, $name);
            }
        }
    }

    /**
     * History is append-only: events and adjustments can be added, never changed or removed. A statement line is raw
     * bank data: only the two link columns (match, reconciliation) may ever change, and a line that is matched or
     * reconciled cannot be deleted.
     */
    private function immutability(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $msg = 'bank history is append-only';
            foreach (['bank_reconciliation_events', 'bank_adjustments'] as $table) {
                DB::unprepared("CREATE TRIGGER IF NOT EXISTS {$table}_no_update BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, '{$msg}'); END");
                DB::unprepared("CREATE TRIGGER IF NOT EXISTS {$table}_no_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, '{$msg}'); END");
            }

            $same = collect(['user_id', 'team_id', 'bank_statement_id', 'account_id', 'line_no', 'posted_on', 'amount_cents', 'currency', 'description', 'reference', 'external_id', 'balance_after_cents', 'fingerprint', 'created_at'])
                ->map(fn ($c) => "OLD.{$c} IS NEW.{$c}")->implode(' AND ');
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS bank_statement_lines_guard_update BEFORE UPDATE ON bank_statement_lines WHEN NOT ({$same}) BEGIN SELECT RAISE(ABORT, 'a bank statement line is what the bank said and cannot be edited'); END");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS bank_statement_lines_guard_delete BEFORE DELETE ON bank_statement_lines WHEN OLD.bank_match_id IS NOT NULL OR OLD.reconciliation_id IS NOT NULL BEGIN SELECT RAISE(ABORT, 'a matched or reconciled bank statement line cannot be deleted'); END");

            // A cash-ledger row the bank has vouched for keeps the figures it was matched on (the model refuses first, with words).
            $moved = collect(self::LOCKED)->map(fn ($c) => "NEW.{$c} IS NOT OLD.{$c}")->implode(' OR ');
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS transactions_cleared_lock_update BEFORE UPDATE ON transactions WHEN (OLD.bank_match_id IS NOT NULL OR OLD.reconciliation_id IS NOT NULL) AND ({$moved}) BEGIN SELECT RAISE(ABORT, 'a transaction matched to a bank statement line cannot be changed or deleted'); END");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS transactions_cleared_lock_delete BEFORE DELETE ON transactions WHEN OLD.bank_match_id IS NOT NULL OR OLD.reconciliation_id IS NOT NULL BEGIN SELECT RAISE(ABORT, 'a transaction matched to a bank statement line cannot be changed or deleted'); END");

            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION bank_history_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'bank history is append-only: % on % is not allowed', TG_OP, TG_TABLE_NAME
        USING ERRCODE = 'integrity_constraint_violation';
END;
$$ LANGUAGE plpgsql
SQL);
        foreach (['bank_reconciliation_events', 'bank_adjustments'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION bank_history_immutable()");
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION bank_lines_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.bank_match_id IS NOT NULL OR OLD.reconciliation_id IS NOT NULL THEN
            RAISE EXCEPTION 'a matched or reconciled bank statement line cannot be deleted'
                USING ERRCODE = 'integrity_constraint_violation';
        END IF;
        RETURN OLD;
    END IF;

    IF (to_jsonb(NEW) - 'bank_match_id' - 'reconciliation_id') <> (to_jsonb(OLD) - 'bank_match_id' - 'reconciliation_id') THEN
        RAISE EXCEPTION 'a bank statement line is what the bank said and cannot be edited'
            USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER bank_statement_lines_guard BEFORE UPDATE OR DELETE ON bank_statement_lines FOR EACH ROW EXECUTE FUNCTION bank_lines_guard()');

        $moved = collect(self::LOCKED)->map(fn ($c) => "NEW.{$c} IS DISTINCT FROM OLD.{$c}")->implode(' OR ');
        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION bank_cleared_transaction_lock() RETURNS trigger AS \$\$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.bank_match_id IS NOT NULL OR OLD.reconciliation_id IS NOT NULL THEN
            RAISE EXCEPTION 'a transaction matched to a bank statement line cannot be changed or deleted'
                USING ERRCODE = 'integrity_constraint_violation';
        END IF;
        RETURN OLD;
    END IF;

    IF (OLD.bank_match_id IS NOT NULL OR OLD.reconciliation_id IS NOT NULL) AND ({$moved}) THEN
        RAISE EXCEPTION 'a transaction matched to a bank statement line cannot be changed or deleted'
            USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    RETURN NEW;
END;
\$\$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER transactions_cleared_lock BEFORE UPDATE OR DELETE ON transactions FOR EACH ROW EXECUTE FUNCTION bank_cleared_transaction_lock()');
    }

    private function dropImmutability(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['bank_reconciliation_events', 'bank_adjustments'] as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
            }
            DB::unprepared('DROP TRIGGER IF EXISTS bank_statement_lines_guard_update');
            DB::unprepared('DROP TRIGGER IF EXISTS bank_statement_lines_guard_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS transactions_cleared_lock_update');
            DB::unprepared('DROP TRIGGER IF EXISTS transactions_cleared_lock_delete');

            return;
        }

        foreach (['bank_reconciliation_events', 'bank_adjustments'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable ON {$table}");
        }
        DB::unprepared('DROP TRIGGER IF EXISTS bank_statement_lines_guard ON bank_statement_lines');
        DB::unprepared('DROP TRIGGER IF EXISTS transactions_cleared_lock ON transactions');
        DB::unprepared('DROP FUNCTION IF EXISTS bank_cleared_transaction_lock()');
        DB::unprepared('DROP FUNCTION IF EXISTS bank_history_immutable()');
        DB::unprepared('DROP FUNCTION IF EXISTS bank_lines_guard()');
    }
};
