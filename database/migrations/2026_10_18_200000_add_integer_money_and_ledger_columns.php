<?php

use App\Services\LegacyMoneyBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money becomes integer cents; account balance becomes derived from the ledger.
 *
 * The old decimal columns (accounts.balance, transactions.amount, budgets.amount)
 * are deliberately KEPT, not dropped:
 *  - transactions.amount and budgets.amount are mirrored from the cents columns
 *    on every write, so a code rollback keeps working and so a deploy that runs
 *    this migration before the new code is live does not break the old code.
 *  - accounts.balance is no longer read or written by anything. It survives only
 *    as the record of what the user typed before this migration. It can be dropped
 *    in a later release once the owner has checked `finance:check-balances`.
 *
 * Backfill (see LegacyMoneyBackfill) turns each existing typed balance into an
 * opening balance dated the day the migration runs. History before that day is not
 * reconstructed: the ledger can only be trusted from the opening date forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->bigInteger('opening_balance_cents')->default(0)->after('currency');
            $table->date('opening_balance_date')->nullable()->after('opening_balance_cents');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->bigInteger('amount_cents')->default(0)->after('amount');
            $table->string('currency', 3)->nullable()->after('amount_cents');
            // 'out' or 'in' on the two legs of a transfer; null on everything else
            // (and on legacy transfer rows, which never had a destination).
            $table->string('transfer_direction', 3)->nullable()->after('type');
            $table->string('transfer_group', 36)->nullable()->after('transfer_direction');
            $table->index('transfer_group');
        });

        Schema::table('budgets', function (Blueprint $table) {
            $table->bigInteger('amount_cents')->default(0)->after('amount');
            $table->string('currency', 3)->default('ZAR')->after('amount_cents');
        });

        // A budget is per currency now, so the uniqueness that stops duplicates
        // has to include it (one ZAR and one USD "Groceries" budget may coexist).
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'category_id', 'month', 'year']);
            $table->unique(['user_id', 'category_id', 'currency', 'month', 'year']);
        });

        (new LegacyMoneyBackfill)->run();
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'category_id', 'currency', 'month', 'year']);
        });

        Schema::table('budgets', function (Blueprint $table) {
            $table->unique(['user_id', 'category_id', 'month', 'year']);
            $table->dropColumn(['amount_cents', 'currency']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['transfer_group']);
            $table->dropColumn(['amount_cents', 'currency', 'transfer_direction', 'transfer_group']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['opening_balance_cents', 'opening_balance_date']);
        });
    }
};
