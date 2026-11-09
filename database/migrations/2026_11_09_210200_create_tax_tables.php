<?php

use App\Support\Database\TableChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-44 shared data layer, part 3: tax codes, tax rules and tax rates.
 *
 * Rules as data. A row with no owner is global (it applies to everyone in that
 * jurisdiction); a row owned by one ledger is an override for that ledger only, e.g. a tax
 * practitioner's documented value. Rows are versioned by date: a new value is a new row
 * with a later valid_from, and the previous row's valid_to is closed. A value is never
 * edited in place (the models enforce it), so a return can always be re-explained with the
 * version that was used.
 *
 * Every rule and rate carries its provenance: source_url, accessed_at and a confidence
 * (high | medium | low), plus a legal_status so a proposal is never calculated with.
 *
 * Uniqueness uses COALESCE(owner, 0) so two global rows for the same version collide
 * (NULLs are otherwise all distinct).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->string('jurisdiction', 8);
            $table->string('code', 40);
            $table->string('name', 120);
            $table->text('description')->nullable();
            // standard | zero | exempt | out_of_scope | reverse_charge
            $table->string('kind', 20);
            // sales | purchases | both: the direction a code can be used in
            $table->string('applies_to', 10)->default('both');
            // names the tax_rates.rate_key that gives this code its rate on a date; null = no tax charged
            $table->string('rate_ref', 40)->nullable();
            // the return box a sale / a purchase with this code is reported in (VAT201 field numbers)
            $table->string('box_output', 10)->nullable();
            $table->string('box_input', 10)->nullable();
            $table->string('deductible_policy', 30)->default('not_applicable');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['jurisdiction', 'code'], 'tax_codes_jurisdiction_code_index');
        });

        TableChecks::ownerOrGlobalRule('tax_codes');
        TableChecks::add('tax_codes', 'kind_values', "kind IN ('standard', 'zero', 'exempt', 'out_of_scope', 'reverse_charge')", ['kind'], 'tax_codes: unknown kind');
        TableChecks::add('tax_codes', 'applies_values', "applies_to IN ('sales', 'purchases', 'both')", ['applies_to'], 'tax_codes: applies_to must be sales, purchases or both');
        TableChecks::add('tax_codes', 'policy_values', "deductible_policy IN ('allowed', 'denied_entertainment', 'denied_motor_car', 'denied_other', 'apportion', 'not_applicable')", ['deductible_policy'], 'tax_codes: unknown deductible policy');
        TableChecks::add('tax_codes', 'valid_range', 'valid_to IS NULL OR valid_to >= valid_from', ['valid_to', 'valid_from'], 'tax_codes: valid_to cannot be before valid_from');
        DB::statement('CREATE UNIQUE INDEX tax_codes_version_unique ON tax_codes (jurisdiction, code, valid_from, COALESCE(user_id, 0), COALESCE(team_id, 0))');

        Schema::create('tax_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->string('jurisdiction', 8);
            $table->string('tax_type', 20);
            $table->string('rule_key', 80);
            // narrows a rule that has several flavours at once: a vat category 'A', an entity 'company', an age band
            $table->string('qualifier', 60)->default('');
            // integer | money_cents | basis_points | text | boolean | json
            $table->string('value_type', 15);
            $table->json('value');
            $table->string('unit', 20)->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            // enacted | announced | policy | proposed | draft | repealed. Only enacted, announced and policy (a Dot.Finance default, not law) are ever calculated with
            $table->string('legal_status', 12)->default('enacted');
            $table->string('source_url', 500);
            $table->string('source_ref', 255)->nullable();
            $table->date('accessed_at');
            $table->string('confidence', 6);
            $table->text('note')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('tax_rules')->nullOnDelete();
            $table->string('reviewed_by', 120)->nullable();
            $table->date('review_due')->nullable();
            $table->timestamps();

            $table->index(['jurisdiction', 'rule_key', 'valid_from'], 'tax_rules_lookup_index');
        });

        TableChecks::ownerOrGlobalRule('tax_rules');
        TableChecks::add('tax_rules', 'confidence_values', "confidence IN ('high', 'medium', 'low')", ['confidence'], 'tax_rules: confidence must be high, medium or low');
        TableChecks::add('tax_rules', 'legal_status_values', "legal_status IN ('enacted', 'announced', 'policy', 'proposed', 'draft', 'repealed')", ['legal_status'], 'tax_rules: unknown legal status');
        TableChecks::add('tax_rules', 'value_type_values', "value_type IN ('integer', 'money_cents', 'basis_points', 'text', 'boolean', 'json')", ['value_type'], 'tax_rules: unknown value type');
        TableChecks::add('tax_rules', 'valid_range', 'valid_to IS NULL OR valid_to >= valid_from', ['valid_to', 'valid_from'], 'tax_rules: valid_to cannot be before valid_from');
        TableChecks::add('tax_rules', 'source_required', 'length(trim(source_url)) > 0', ['source_url'], 'tax_rules: every rule names its source');
        DB::statement('CREATE UNIQUE INDEX tax_rules_version_unique ON tax_rules (jurisdiction, rule_key, qualifier, valid_from, COALESCE(user_id, 0), COALESCE(team_id, 0))');

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->string('jurisdiction', 8);
            // matches tax_codes.rate_ref
            $table->string('rate_key', 40);
            // 1500 = 15.00 %
            $table->unsignedInteger('rate_bp');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('legal_status', 12)->default('enacted');
            $table->string('source_url', 500);
            $table->string('source_ref', 255)->nullable();
            $table->date('accessed_at');
            $table->string('confidence', 6);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['jurisdiction', 'rate_key', 'valid_from'], 'tax_rates_lookup_index');
        });

        TableChecks::ownerOrGlobalRule('tax_rates');
        TableChecks::add('tax_rates', 'rate_range', 'rate_bp <= 100000', ['rate_bp'], 'tax_rates: a rate cannot exceed 1000 percent');
        TableChecks::add('tax_rates', 'confidence_values', "confidence IN ('high', 'medium', 'low')", ['confidence'], 'tax_rates: confidence must be high, medium or low');
        TableChecks::add('tax_rates', 'legal_status_values', "legal_status IN ('enacted', 'announced', 'policy', 'proposed', 'draft', 'repealed')", ['legal_status'], 'tax_rates: unknown legal status');
        TableChecks::add('tax_rates', 'valid_range', 'valid_to IS NULL OR valid_to >= valid_from', ['valid_to', 'valid_from'], 'tax_rates: valid_to cannot be before valid_from');
        TableChecks::add('tax_rates', 'source_required', 'length(trim(source_url)) > 0', ['source_url'], 'tax_rates: every rate names its source');
        DB::statement('CREATE UNIQUE INDEX tax_rates_version_unique ON tax_rates (jurisdiction, rate_key, valid_from, COALESCE(user_id, 0), COALESCE(team_id, 0))');
    }

    public function down(): void
    {
        foreach (['tax_rates' => ['owner_rule', 'rate_range', 'confidence_values', 'legal_status_values', 'valid_range', 'source_required'],
            'tax_rules' => ['owner_rule', 'confidence_values', 'legal_status_values', 'value_type_values', 'valid_range', 'source_required'],
            'tax_codes' => ['owner_rule', 'kind_values', 'applies_values', 'policy_values', 'valid_range']] as $table => $rules) {
            foreach ($rules as $rule) {
                TableChecks::drop($table, $rule);
            }
            Schema::dropIfExists($table);
        }
    }
};
