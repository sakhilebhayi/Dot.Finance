<?php

use App\Support\Database\Checks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * R-44 Phase 0: integrity fixes that do not depend on any accounting.
 *
 *  - transactions.version: an optimistic-lock counter. Every update increments it and the
 *    edit form carries the version it was opened at, so two people editing one entry cannot
 *    silently overwrite each other.
 *  - A partial unique index on (recurring_rule_id, date) for live rows: the scheduler can
 *    never write one occurrence twice, whatever runs it. If duplicates already exist the
 *    index is skipped (and logged) rather than failing the deploy; the reconciler reports
 *    them as 'recurring_duplicate' and the app-level guard in catchUp still holds.
 *  - CHECK constraints: amount_cents > 0, month 1..12, three-letter upper-case currency.
 *  - An index on transactions(category_id), used by category filters and counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transactions', 'version')) {
            Schema::table('transactions', fn (Blueprint $table) => $table->unsignedInteger('version')->default(1));
        }

        $this->recurringIndex();

        if (! $this->hasIndex('transactions', 'transactions_category_id_index')) {
            Schema::table('transactions', fn (Blueprint $table) => $table->index('category_id', 'transactions_category_id_index'));
        }

        Checks::add('transactions', 'transactions_amount_positive', 'amount_cents > 0', ['amount_cents']);
        Checks::add('recurring_rules', 'recurring_rules_amount_positive', 'amount_cents > 0', ['amount_cents']);
        Checks::add('budgets', 'budgets_amount_positive', 'amount_cents > 0', ['amount_cents']);
        Checks::add('budgets', 'budgets_month_range', 'month BETWEEN 1 AND 12', ['month']);

        foreach (['accounts', 'transactions', 'recurring_rules', 'budgets'] as $table) {
            Checks::add($table, "{$table}_currency_format", Checks::currency('currency'), ['currency']);
        }
    }

    public function down(): void
    {
        Checks::drop('transactions', 'transactions_amount_positive');
        Checks::drop('recurring_rules', 'recurring_rules_amount_positive');
        Checks::drop('budgets', 'budgets_amount_positive');
        Checks::drop('budgets', 'budgets_month_range');

        foreach (['accounts', 'transactions', 'recurring_rules', 'budgets'] as $table) {
            Checks::drop($table, "{$table}_currency_format");
        }

        DB::statement('DROP INDEX IF EXISTS transactions_rule_date_unique');

        if ($this->hasIndex('transactions', 'transactions_category_id_index')) {
            Schema::table('transactions', fn (Blueprint $table) => $table->dropIndex('transactions_category_id_index'));
        }

        if (Schema::hasColumn('transactions', 'version')) {
            Schema::table('transactions', fn (Blueprint $table) => $table->dropColumn('version'));
        }
    }

    private function recurringIndex(): void
    {
        if ($this->hasIndex('transactions', 'transactions_rule_date_unique')) {
            return;
        }

        $duplicates = DB::table('transactions')
            ->whereNotNull('recurring_rule_id')->whereNull('deleted_at')
            ->select('recurring_rule_id', 'date')
            ->groupBy('recurring_rule_id', 'date')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicates > 0) {
            Log::warning("transactions_rule_date_unique not created: {$duplicates} recurring occurrence(s) already exist twice. Resolve them (see the reconciler finding recurring_duplicate) and re-run php artisan finance:ensure-recurring-index.");

            return;
        }

        DB::statement('CREATE UNIQUE INDEX transactions_rule_date_unique ON transactions (recurring_rule_id, date) WHERE recurring_rule_id IS NOT NULL AND deleted_at IS NULL');
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $index) => $index['name'] === $name);
    }
};
