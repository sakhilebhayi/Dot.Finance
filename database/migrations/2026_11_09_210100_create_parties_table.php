<?php

use App\Support\Database\TableChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-44 shared data layer, part 2: parties (customers and suppliers).
 *
 * One table for both, because the same business is often both. Owned by exactly one
 * ledger (user_id XOR team_id). Parties are archived (soft-deleted), never destroyed:
 * invoices, bills and payments will point at them.
 *
 * Constraints beyond the owner rule:
 *  - type and status are closed lists; currency, when set, is three upper-case letters;
 *  - payment terms are 0..3650 days; a credit limit cannot be negative;
 *  - a tax (VAT) number is unique among a ledger's live parties, and so is an
 *    external reference (partial unique indexes: archived parties do not block reuse).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->string('type', 10);
            $table->string('name', 255);
            $table->string('legal_name', 255)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('tax_number', 50)->nullable();
            $table->string('registration_number', 50)->nullable();
            $table->json('billing_address')->nullable();
            $table->json('shipping_address')->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->bigInteger('credit_limit_cents')->nullable();
            $table->string('status', 10)->default('active');
            $table->string('external_ref', 100)->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'name'], 'parties_user_name_index');
            $table->index(['team_id', 'name'], 'parties_team_name_index');
            $table->index(['team_id', 'type'], 'parties_team_type_index');
            $table->index(['user_id', 'type'], 'parties_user_type_index');
        });

        TableChecks::ownerRule('parties');
        TableChecks::add('parties', 'type_values', "type IN ('customer', 'supplier', 'both')", ['type'], 'parties: type must be customer, supplier or both');
        TableChecks::add('parties', 'status_values', "status IN ('active', 'archived')", ['status'], 'parties: status must be active or archived');
        TableChecks::add('parties', 'currency_format', 'currency IS NULL OR (length(currency) = 3 AND currency = upper(currency))', ['currency'], 'parties: currency is three upper-case letters');
        TableChecks::add('parties', 'terms_range', 'payment_terms_days IS NULL OR payment_terms_days <= 3650', ['payment_terms_days'], 'parties: payment terms are at most 3650 days');
        TableChecks::add('parties', 'credit_limit_non_negative', 'credit_limit_cents IS NULL OR credit_limit_cents >= 0', ['credit_limit_cents'], 'parties: a credit limit cannot be negative');
        TableChecks::add('parties', 'name_not_blank', 'length(trim(name)) > 0', ['name'], 'parties: a name is required');

        foreach (['user_id', 'team_id'] as $owner) {
            DB::statement("CREATE UNIQUE INDEX parties_{$owner}_tax_number_unique ON parties ({$owner}, tax_number) WHERE {$owner} IS NOT NULL AND tax_number IS NOT NULL AND deleted_at IS NULL");
            DB::statement("CREATE UNIQUE INDEX parties_{$owner}_external_ref_unique ON parties ({$owner}, external_ref) WHERE {$owner} IS NOT NULL AND external_ref IS NOT NULL AND deleted_at IS NULL");
        }
    }

    public function down(): void
    {
        foreach (['owner_rule', 'type_values', 'status_values', 'currency_format', 'terms_range', 'credit_limit_non_negative', 'name_not_blank'] as $rule) {
            TableChecks::drop('parties', $rule);
        }

        Schema::dropIfExists('parties');
    }
};
