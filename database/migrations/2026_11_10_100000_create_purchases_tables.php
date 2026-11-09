<?php

use App\Support\Database\TableChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-44 Purchases: supplier profiles and bank details, bills (and supplier credit notes) with lines, expense claims
 * with lines, recurring bills, payment runs, and the per-ledger purchases settings.
 *
 * Every table carries the owner pair (user_id XOR team_id) and its CHECK. Money is bigint minor units. Posted
 * bills are never edited in place (the application refuses it); the database holds the numbers a posting needs
 * (net, tax, claimable tax per line) so a posting never has to recalculate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_settings', function (Blueprint $table) {
            $this->owner($table);
            $table->id();
            // user-provided: is the ledger a registered VAT vendor? Null until someone says so (input VAT is then not claimed)
            $table->boolean('vat_registered')->nullable();
            $table->foreignId('vat_registered_set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('vat_registered_set_at')->nullable();
            // a bill or receipt at or above this amount with no document attached is listed as missing; null = the tax rule's "no invoice needed" amount
            $table->bigInteger('missing_document_threshold_cents')->nullable();
            $table->timestamps();

            $table->unique('user_id', 'purchase_settings_user_unique');
            $table->unique('team_id', 'purchase_settings_team_unique');
        });

        Schema::create('supplier_profiles', function (Blueprint $table) {
            $this->owner($table);
            $table->id();
            $table->foreignId('party_id')->constrained('parties');
            $table->unsignedBigInteger('default_gl_account_id')->nullable();
            $table->string('default_tax_code', 40)->nullable();
            $table->unsignedSmallInteger('early_payment_discount_bp')->nullable();
            $table->unsignedSmallInteger('early_payment_days')->nullable();
            $table->timestamps();

            $table->unique('party_id');
        });

        Schema::create('supplier_bank_details', function (Blueprint $table) {
            $this->owner($table);
            $table->id();
            $table->foreignId('party_id')->constrained('parties');
            $table->unsignedInteger('version');
            $table->string('status', 12)->default('pending');
            // the whole detail set, encrypted at rest by the model cast; the plain columns below carry only what is safe to show
            $table->text('payload');
            $table->string('bank_name', 120)->nullable();
            $table->string('account_last4', 4);
            // keyed hash of the account number: lets the app notice two suppliers sharing one account without storing it in the clear
            $table->string('account_hash', 64);
            $table->string('change_reason', 500);
            $table->boolean('verified_by_callback')->default(false);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->boolean('self_approved')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['party_id', 'version']);
            $table->index(['party_id', 'status']);
            $table->index('account_hash');
        });

        Schema::create('bills', function (Blueprint $table) {
            $this->owner($table);
            $table->id();
            $table->string('kind', 12)->default('bill');
            $table->foreignId('party_id')->constrained('parties');
            $table->string('number', 40)->nullable();
            $table->string('supplier_reference', 60)->nullable();
            $table->string('supplier_reference_norm', 60)->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('bill_date');
            $table->date('due_date')->nullable();
            $table->char('currency', 3);
            $table->bigInteger('fx_rate_e8')->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->bigInteger('claimable_tax_cents')->default(0);
            $table->bigInteger('base_total_cents')->nullable();
            $table->string('document_type', 20)->nullable();
            $table->string('supplier_vat_number', 30)->nullable();
            $table->json('vat_review')->nullable();
            $table->json('rule_versions')->nullable();
            $table->string('rounding_level', 10)->nullable();
            $table->string('tax_confidence', 8)->nullable();
            $table->foreignId('credit_for_bill_id')->nullable()->constrained('bills');
            $table->string('credit_reason', 255)->nullable();
            $table->unsignedSmallInteger('early_payment_discount_bp')->nullable();
            $table->unsignedSmallInteger('early_payment_days')->nullable();
            $table->unsignedBigInteger('posted_entry_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('void_entry_id')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->string('post_error', 255)->nullable();
            $table->string('rejection_reason', 1000)->nullable();
            $table->string('source', 12)->default('manual');
            $table->unsignedBigInteger('recurring_bill_id')->nullable();
            $table->string('recurring_period', 12)->nullable();
            $table->string('client_token', 60)->nullable();
            $table->json('duplicate_ack')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'client_token'], 'bills_user_token_unique');
            $table->unique(['team_id', 'client_token'], 'bills_team_token_unique');
            $table->unique(['recurring_bill_id', 'recurring_period'], 'bills_recurring_period_unique');
            $table->index(['user_id', 'status']);
            $table->index(['team_id', 'status']);
            $table->index('party_id');
            $table->index('due_date');
            $table->index('credit_for_bill_id');
            $table->index(['party_id', 'supplier_reference_norm'], 'bills_party_reference_index');
        });

        TableChecks::ownerRule('bills');
        TableChecks::add('bills', 'kind_values', "kind IN ('bill', 'credit_note')", ['kind'], 'bills: kind must be bill or credit_note');
        TableChecks::add('bills', 'status_values', "status IN ('draft', 'awaiting_approval', 'approved', 'open', 'partially_paid', 'paid', 'void')", ['status'], 'bills: unknown status');
        TableChecks::add('bills', 'currency_format', 'length(currency) = 3 AND currency = upper(currency)', ['currency'], 'bills: currency is three upper-case letters');
        TableChecks::add('bills', 'totals_not_negative', 'subtotal_cents >= 0 AND tax_cents >= 0 AND total_cents >= 0 AND claimable_tax_cents >= 0 AND claimable_tax_cents <= tax_cents', ['subtotal_cents', 'tax_cents', 'total_cents', 'claimable_tax_cents'], 'bills: amounts cannot be negative and claimable tax cannot exceed the tax');
        TableChecks::add('bills', 'fx_rate_positive', 'fx_rate_e8 IS NULL OR fx_rate_e8 > 0', ['fx_rate_e8'], 'bills: an exchange rate must be greater than zero');
        TableChecks::add('bills', 'credit_link_only_credit', "credit_for_bill_id IS NULL OR kind = 'credit_note'", ['credit_for_bill_id', 'kind'], 'bills: only a credit note can point at a bill it credits');

        Schema::create('bill_lines', function (Blueprint $table) {
            $this->owner($table);
            $table->id();
            $table->foreignId('bill_id')->constrained('bills')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('description', 255);
            $table->unsignedBigInteger('gl_account_id');
            $table->bigInteger('quantity_e4')->default(10000);
            $table->bigInteger('unit_price_cents')->default(0);
            $table->unsignedSmallInteger('discount_bp')->default(0);
            $table->string('tax_code', 40);
            $table->unsignedBigInteger('tax_code_id')->nullable();
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->string('dimension', 60)->nullable();
            $table->bigInteger('net_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('gross_cents')->default(0);
            $table->bigInteger('claimable_tax_cents')->default(0);
            $table->string('claim_reason', 40)->nullable();
            $table->timestamps();

            $table->unique(['bill_id', 'line_no']);
            $table->index('gl_account_id');
        });

        TableChecks::ownerRule('bill_lines');
        TableChecks::add('bill_lines', 'amounts_sane', 'net_cents >= 0 AND tax_cents >= 0 AND gross_cents >= 0 AND claimable_tax_cents >= 0 AND claimable_tax_cents <= tax_cents AND quantity_e4 > 0', ['net_cents', 'tax_cents', 'gross_cents', 'claimable_tax_cents', 'quantity_e4'], 'bill_lines: amounts cannot be negative and the quantity must be greater than zero');
        TableChecks::add('bill_lines', 'dimension_format', "dimension IS NULL OR dimension LIKE 'project:%' OR dimension LIKE 'department:%'", ['dimension'], 'bill_lines: a dimension is project:<id> or department:<id>');

        Schema::create('expense_claims', function (Blueprint $table) {
            $this->owner($table);
            $table->id();
            $table->foreignId('claimant_user_id')->constrained('users');
            $table->foreignId('party_id')->nullable()->constrained('parties');
            $table->string('title', 120);
            $table->string('number', 40)->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('claim_date');
            $table->char('currency', 3);
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->bigInteger('claimable_tax_cents')->default(0);
            $table->json('vat_review')->nullable();
            $table->json('rule_versions')->nullable();
            $table->unsignedBigInteger('posted_entry_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('void_entry_id')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->string('post_error', 255)->nullable();
            $table->string('rejection_reason', 1000)->nullable();
            $table->string('client_token', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'client_token'], 'claims_user_token_unique');
            $table->unique(['team_id', 'client_token'], 'claims_team_token_unique');
            $table->index(['user_id', 'status']);
            $table->index(['team_id', 'status']);
        });

        TableChecks::ownerRule('expense_claims');
        TableChecks::add('expense_claims', 'status_values', "status IN ('draft', 'awaiting_approval', 'approved', 'open', 'partially_paid', 'paid', 'void')", ['status'], 'expense_claims: unknown status');
        TableChecks::add('expense_claims', 'currency_format', 'length(currency) = 3 AND currency = upper(currency)', ['currency'], 'expense_claims: currency is three upper-case letters');
        TableChecks::add('expense_claims', 'totals_not_negative', 'subtotal_cents >= 0 AND tax_cents >= 0 AND total_cents >= 0 AND claimable_tax_cents >= 0 AND claimable_tax_cents <= tax_cents', ['subtotal_cents', 'tax_cents', 'total_cents', 'claimable_tax_cents'], 'expense_claims: amounts cannot be negative and claimable tax cannot exceed the tax');

        Schema::create('expense_claim_lines', function (Blueprint $table) {
            $this->owner($table);
            $table->id();
            $table->foreignId('expense_claim_id')->constrained('expense_claims')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->date('spent_on');
            $table->string('merchant', 120);
            $table->string('description', 255);
            $table->string('receipt_type', 20)->nullable();
            $table->string('merchant_vat_number', 30)->nullable();
            $table->string('receipt_reference', 60)->nullable();
            $table->unsignedBigInteger('gl_account_id');
            $table->string('tax_code', 40);
            $table->unsignedBigInteger('tax_code_id')->nullable();
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->string('dimension', 60)->nullable();
            $table->bigInteger('gross_cents')->default(0);
            $table->bigInteger('net_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('claimable_tax_cents')->default(0);
            $table->string('claim_reason', 40)->nullable();
            $table->timestamps();

            $table->unique(['expense_claim_id', 'line_no']);
            $table->index('gl_account_id');
        });

        TableChecks::ownerRule('expense_claim_lines');
        TableChecks::add('expense_claim_lines', 'amounts_sane', 'net_cents >= 0 AND tax_cents >= 0 AND gross_cents >= 0 AND claimable_tax_cents >= 0 AND claimable_tax_cents <= tax_cents', ['net_cents', 'tax_cents', 'gross_cents', 'claimable_tax_cents'], 'expense_claim_lines: amounts cannot be negative and claimable tax cannot exceed the tax');

        Schema::create('recurring_bills', function (Blueprint $table) {
            $this->owner($table);
            $table->id();
            $table->foreignId('party_id')->constrained('parties');
            $table->string('name', 120);
            $table->string('frequency', 10);
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->date('start_on');
            $table->date('next_run_on');
            $table->date('end_on')->nullable();
            $table->string('status', 10)->default('active');
            $table->unsignedSmallInteger('days_until_due')->default(30);
            $table->char('currency', 3);
            $table->boolean('tax_inclusive')->default(false);
            $table->string('document_type', 20)->nullable();
            $table->json('template');
            $table->date('last_generated_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'next_run_on']);
        });

        TableChecks::ownerRule('recurring_bills');
        TableChecks::add('recurring_bills', 'frequency_values', "frequency IN ('weekly', 'monthly', 'quarterly', 'yearly')", ['frequency'], 'recurring_bills: unknown frequency');
        TableChecks::add('recurring_bills', 'status_values', "status IN ('active', 'paused', 'ended')", ['status'], 'recurring_bills: unknown status');

        Schema::create('payment_runs', function (Blueprint $table) {
            $this->owner($table);
            $table->id();
            $table->string('name', 120);
            $table->date('due_by');
            $table->date('paid_on');
            $table->foreignId('cash_account_id')->constrained('accounts');
            $table->char('currency', 3);
            $table->string('status', 20)->default('draft');
            $table->string('idempotency_key', 80);
            $table->bigInteger('total_cents')->default(0);
            $table->unsignedInteger('supplier_count')->default(0);
            $table->timestamp('executed_at')->nullable();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('csv_exported_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key'], 'payment_runs_user_key_unique');
            $table->unique(['team_id', 'idempotency_key'], 'payment_runs_team_key_unique');
        });

        TableChecks::ownerRule('payment_runs');
        TableChecks::add('payment_runs', 'status_values', "status IN ('draft', 'awaiting_approval', 'approved', 'executed', 'cancelled')", ['status'], 'payment_runs: unknown status');
        TableChecks::add('payment_runs', 'total_not_negative', 'total_cents >= 0', ['total_cents'], 'payment_runs: the total cannot be negative');

        Schema::create('payment_run_items', function (Blueprint $table) {
            $this->owner($table);
            $table->id();
            $table->foreignId('payment_run_id')->constrained('payment_runs')->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties');
            $table->string('allocatable_type', 20);
            $table->unsignedBigInteger('allocatable_id');
            $table->bigInteger('amount_cents');
            $table->foreignId('payment_id')->nullable()->constrained('payments');
            $table->timestamps();

            $table->unique(['payment_run_id', 'allocatable_type', 'allocatable_id'], 'payment_run_items_unique');
            $table->index('party_id');
        });

        TableChecks::ownerRule('payment_run_items');
        TableChecks::add('payment_run_items', 'amount_positive', 'amount_cents > 0', ['amount_cents'], 'payment_run_items: an amount must be greater than zero');

        // The cash row of a supplier payment is found from the payment; the GL sweeper asks "is this transaction a settlement?" often.
        Schema::table('payments', function (Blueprint $table) {
            $table->index('transaction_id', 'payments_transaction_id_index');
        });

        // What an allocation relieved of the accounts-payable control account, in the ledger's base currency. Null means "the
        // same as amount_cents" (a base-currency settlement, and anything written before this column existed).
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->bigInteger('base_relief_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropColumn('base_relief_cents');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_transaction_id_index');
        });

        $rules = [
            'payment_run_items' => ['owner_rule', 'amount_positive'],
            'payment_runs' => ['owner_rule', 'status_values', 'total_not_negative'],
            'recurring_bills' => ['owner_rule', 'frequency_values', 'status_values'],
            'expense_claim_lines' => ['owner_rule', 'amounts_sane'],
            'expense_claims' => ['owner_rule', 'status_values', 'currency_format', 'totals_not_negative'],
            'bill_lines' => ['owner_rule', 'amounts_sane', 'dimension_format'],
            'bills' => ['owner_rule', 'kind_values', 'status_values', 'currency_format', 'totals_not_negative', 'fx_rate_positive', 'credit_link_only_credit'],
        ];

        foreach ($rules as $table => $names) {
            foreach ($names as $name) {
                TableChecks::drop($table, $name);
            }
            Schema::dropIfExists($table);
        }

        foreach (['supplier_bank_details', 'supplier_profiles', 'purchase_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function owner(Blueprint $table): void
    {
        $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
        $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
    }
};
