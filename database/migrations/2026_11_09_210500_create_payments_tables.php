<?php

use App\Support\Database\TableChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-44 shared data layer, part 6: payments and payment allocations (tables only).
 *
 * A payment is money that moved: direction in (received) or out (paid). It is recorded
 * once, with an idempotency key that is unique per ledger so a retried request cannot
 * record it twice, and never edited: it is reversed, with a reason. An allocation applies
 * part of a payment to something that is owed (an invoice or a bill, any "allocatable").
 *
 * These tables post nothing. The journal entry (gl_entry_id) and the cash-ledger row
 * (transaction_id) are written by Sales and Purchases. gl_entry_id has no foreign key
 * here on purpose: the journal tables belong to another migration set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->string('direction', 3);
            $table->foreignId('party_id')->nullable()->constrained('parties');
            $table->foreignId('cash_account_id')->constrained('accounts');
            $table->bigInteger('amount_cents');
            $table->char('currency', 3);
            // the rate to the ledger's base currency when the payment is in another currency
            $table->bigInteger('fx_rate_e8')->nullable();
            $table->bigInteger('base_amount_cents')->nullable();
            $table->date('paid_on');
            $table->string('method', 20)->default('eft');
            $table->string('reference', 100)->nullable();
            $table->string('idempotency_key', 80);
            $table->string('status', 10)->default('recorded');
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('gl_entry_id')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key'], 'payments_user_idempotency_unique');
            $table->unique(['team_id', 'idempotency_key'], 'payments_team_idempotency_unique');
            $table->index(['user_id', 'paid_on'], 'payments_user_paid_on_index');
            $table->index(['team_id', 'paid_on'], 'payments_team_paid_on_index');
            $table->index('party_id');
        });

        TableChecks::ownerRule('payments');
        TableChecks::add('payments', 'direction_values', "direction IN ('in', 'out')", ['direction'], 'payments: direction must be in or out');
        TableChecks::add('payments', 'amount_positive', 'amount_cents > 0', ['amount_cents'], 'payments: an amount must be greater than zero');
        TableChecks::add('payments', 'status_values', "status IN ('recorded', 'reversed')", ['status'], 'payments: status must be recorded or reversed');
        TableChecks::add('payments', 'method_values', "method IN ('eft', 'card', 'cash', 'cheque', 'debit_order', 'other')", ['method'], 'payments: unknown payment method');
        TableChecks::add('payments', 'currency_format', 'length(currency) = 3 AND currency = upper(currency)', ['currency'], 'payments: currency is three upper-case letters');
        TableChecks::add('payments', 'fx_rate_positive', 'fx_rate_e8 IS NULL OR fx_rate_e8 > 0', ['fx_rate_e8'], 'payments: an exchange rate must be greater than zero');
        TableChecks::add('payments', 'reversal_pair', "(status = 'reversed') = (reversed_at IS NOT NULL)", ['status', 'reversed_at'], 'payments: a reversed payment has a reversal time, a recorded one does not');
        TableChecks::add('payments', 'idempotency_key_required', 'length(idempotency_key) > 0', ['idempotency_key'], 'payments: an idempotency key is required');

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('payments');
            $table->string('allocatable_type', 60);
            $table->unsignedBigInteger('allocatable_id');
            $table->bigInteger('amount_cents');
            // the part of amount_cents that is tax, and the exchange difference realised by this settlement
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('fx_diff_cents')->default(0);
            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('release_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['allocatable_type', 'allocatable_id'], 'payment_allocations_allocatable_index');
            $table->index('payment_id');
        });

        TableChecks::ownerRule('payment_allocations');
        TableChecks::add('payment_allocations', 'amount_positive', 'amount_cents > 0', ['amount_cents'], 'payment_allocations: an allocation must be greater than zero');
        TableChecks::add('payment_allocations', 'tax_within_amount', 'tax_cents >= 0 AND tax_cents <= amount_cents', ['tax_cents', 'amount_cents'], 'payment_allocations: the tax part is between zero and the allocated amount');
        TableChecks::add('payment_allocations', 'release_pair', 'released_at IS NOT NULL OR (released_by IS NULL AND release_reason IS NULL)', ['released_at', 'released_by', 'release_reason'], 'payment_allocations: release details need a release time');
    }

    public function down(): void
    {
        foreach (['owner_rule', 'amount_positive', 'tax_within_amount', 'release_pair'] as $rule) {
            TableChecks::drop('payment_allocations', $rule);
        }
        Schema::dropIfExists('payment_allocations');

        foreach (['owner_rule', 'direction_values', 'amount_positive', 'status_values', 'method_values', 'currency_format', 'fx_rate_positive', 'reversal_pair', 'idempotency_key_required'] as $rule) {
            TableChecks::drop('payments', $rule);
        }
        Schema::dropIfExists('payments');
    }
};
