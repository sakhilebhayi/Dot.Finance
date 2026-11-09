<?php

use App\Support\Database\TableChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-44 Sales: one document family (quote, pro forma, tax invoice, credit note, debit note) with lines,
 * the applications that settle them, refunds, write-offs, the append-only event trail, signed share
 * links, reminder log, recurring templates and the business profile.
 *
 * Immutability: once a document is numbered (sealed_at set) the database itself refuses to change what
 * was issued (its figures, parties, dates, lines) or to delete it. Only status-like columns may move.
 * Both engines: PostgreSQL gets trigger functions, SQLite gets the equivalent triggers.
 */
return new class extends Migration
{
    /** Columns that can never change once a document is sealed. */
    private const SEALED = [
        'user_id', 'team_id', 'kind', 'number_series', 'number', 'party_id', 'parent_id', 'source_document_id',
        'issue_date', 'supply_date', 'due_date', 'valid_until', 'currency', 'base_currency', 'fx_rate_e8', 'fx_rate_as_of',
        'fx_rate_source', 'fx_rate_recorded_at', 'prices_include_tax', 'tax_jurisdiction', 'tax_rounding_level',
        'tax_rule_versions', 'tax_confidence', 'vat_registered', 'net_cents', 'discount_cents', 'tax_cents', 'total_cents',
        'base_net_cents', 'base_tax_cents', 'base_total_cents', 'reference', 'reason', 'notes', 'terms',
        'customer_snapshot', 'supplier_snapshot', 'sealed_at', 'issued_at', 'issued_by', 'created_by', 'gl_entry_id',
        'recurring_template_id', 'recurring_period',
    ];

    public function up(): void
    {
        $this->profiles();
        $this->recurringTemplates();
        $this->documents();
        $this->lines();
        $this->applications();
        $this->refundsAndWriteOffs();
        $this->trailAndLinks();
        $this->remindersAndRecurring();

        Schema::table('transactions', function (Blueprint $table) {
            // "payment:12": the cash-ledger row is the cash leg of a document settlement, posted by that settlement
            // and not by the generic cash-ledger rules (see PostingRules::skipReason).
            $table->string('settlement_ref', 40)->nullable()->index();
        });

        $this->sealing();
    }

    private function owner(Blueprint $table): void
    {
        $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
        $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
    }

    private function profiles(): void
    {
        Schema::create('sales_profiles', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->string('legal_name', 255)->nullable();
            $table->string('trading_name', 255)->nullable();
            $table->boolean('vat_registered')->default(false);
            $table->string('vat_number', 30)->nullable();
            $table->string('registration_number', 50)->nullable();
            $table->char('tax_jurisdiction', 2)->default('ZA');
            $table->jsonb('address')->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account_holder', 150)->nullable();
            $table->text('bank_account_number')->nullable();       // encrypted at rest
            $table->string('bank_branch_code', 20)->nullable();
            $table->string('bank_account_type', 30)->nullable();
            $table->text('payment_instructions')->nullable();
            $table->string('logo_disk', 20)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('logo_mime', 50)->nullable();
            $table->string('logo_sha256', 64)->nullable();
            $table->string('brand_color', 7)->nullable();
            $table->text('invoice_footer')->nullable();
            $table->text('default_notes')->nullable();
            $table->text('default_terms')->nullable();
            $table->unsignedSmallInteger('default_terms_days')->default(30);
            $table->unsignedSmallInteger('quote_validity_days')->default(30);
            $table->boolean('prices_include_tax')->default(false);
            $table->boolean('reminders_enabled')->default(false);
            $table->unsignedSmallInteger('reminder_before_days')->default(3);
            $table->jsonb('reminder_after_days')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique('user_id');
            $table->unique('team_id');
        });
        TableChecks::ownerRule('sales_profiles');
        TableChecks::add('sales_profiles', 'terms_range', 'default_terms_days <= 365 AND quote_validity_days <= 365', ['default_terms_days', 'quote_validity_days'], 'sales_profiles: terms are 0 to 365 days');

        Schema::create('sales_party_prefs', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->boolean('reminders_opt_out')->default(false);
            $table->timestamp('opted_out_at')->nullable();
            $table->timestamp('last_statement_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'party_id']);
            $table->unique(['team_id', 'party_id']);
        });
        TableChecks::ownerRule('sales_party_prefs');
    }

    private function recurringTemplates(): void
    {
        Schema::create('sales_recurring_templates', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('party_id')->constrained('parties');
            $table->string('name', 150);
            $table->string('frequency', 10);                     // weekly | monthly | quarterly | yearly
            $table->unsignedSmallInteger('every')->default(1);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_run_date');
            $table->string('mode', 8)->default('draft');         // draft | issue
            $table->char('currency', 3);
            $table->unsignedSmallInteger('terms_days')->default(30);
            $table->boolean('prices_include_tax')->default(false);
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('lines');
            $table->string('status', 8)->default('active');      // active | paused | ended
            $table->date('last_run_for')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'next_run_date']);
        });
        TableChecks::ownerRule('sales_recurring_templates');
        TableChecks::add('sales_recurring_templates', 'frequency_values', "frequency IN ('weekly', 'monthly', 'quarterly', 'yearly')", ['frequency'], 'sales_recurring_templates: unknown frequency');
        TableChecks::add('sales_recurring_templates', 'mode_values', "mode IN ('draft', 'issue')", ['mode'], 'sales_recurring_templates: unknown mode');
        TableChecks::add('sales_recurring_templates', 'status_values', "status IN ('active', 'paused', 'ended')", ['status'], 'sales_recurring_templates: unknown status');
        TableChecks::add('sales_recurring_templates', 'every_positive', 'every >= 1 AND every <= 52', ['every'], 'sales_recurring_templates: every is 1 to 52');
    }

    private function documents(): void
    {
        Schema::create('sales_documents', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->string('kind', 12);
            $table->string('status', 20)->default('draft');
            $table->string('number_series', 20)->nullable();
            $table->string('number', 40)->nullable();
            $table->foreignId('party_id')->constrained('parties');
            $table->foreignId('parent_id')->nullable()->constrained('sales_documents');
            $table->foreignId('source_document_id')->nullable()->constrained('sales_documents');
            $table->unsignedBigInteger('converted_to_id')->nullable();
            $table->date('issue_date');
            $table->date('supply_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('valid_until')->nullable();
            $table->char('currency', 3);
            $table->char('base_currency', 3);
            $table->bigInteger('fx_rate_e8')->nullable();
            $table->date('fx_rate_as_of')->nullable();
            $table->string('fx_rate_source', 30)->nullable();
            $table->timestamp('fx_rate_recorded_at')->nullable();
            $table->boolean('fx_rate_locked')->default(false);
            $table->boolean('prices_include_tax')->default(false);
            $table->char('tax_jurisdiction', 2)->default('ZA');
            $table->string('tax_rounding_level', 10)->nullable();
            $table->jsonb('tax_rule_versions')->nullable();
            $table->string('tax_confidence', 6)->nullable();
            $table->boolean('vat_registered')->default(false);
            $table->bigInteger('net_cents')->default(0);
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->bigInteger('base_net_cents')->default(0);
            $table->bigInteger('base_tax_cents')->default(0);
            $table->bigInteger('base_total_cents')->default(0);
            $table->string('reference', 100)->nullable();
            $table->string('reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->text('internal_note')->nullable();
            $table->jsonb('customer_snapshot')->nullable();
            $table->jsonb('supplier_snapshot')->nullable();
            $table->unsignedBigInteger('gl_entry_id')->nullable();
            $table->timestamp('sealed_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->unsignedBigInteger('void_entry_id')->nullable();
            $table->bigInteger('written_off_cents')->default(0);
            $table->bigInteger('written_off_base_cents')->default(0);
            $table->string('approval_note', 255)->nullable();
            $table->foreignId('recurring_template_id')->nullable()->constrained('sales_recurring_templates')->nullOnDelete();
            $table->string('recurring_period', 10)->nullable();
            $table->string('client_token', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'number_series', 'number'], 'sales_documents_user_number_unique');
            $table->unique(['team_id', 'number_series', 'number'], 'sales_documents_team_number_unique');
            $table->unique(['user_id', 'client_token'], 'sales_documents_user_token_unique');
            $table->unique(['team_id', 'client_token'], 'sales_documents_team_token_unique');
            $table->unique(['recurring_template_id', 'recurring_period'], 'sales_documents_recurring_unique');
            $table->index(['user_id', 'kind', 'status'], 'sales_documents_user_kind_status_index');
            $table->index(['team_id', 'kind', 'status'], 'sales_documents_team_kind_status_index');
            $table->index(['party_id', 'status']);
            $table->index('due_date');
            $table->index('parent_id');
        });

        TableChecks::ownerRule('sales_documents');
        TableChecks::add('sales_documents', 'kind_values', "kind IN ('quote', 'pro_forma', 'tax_invoice', 'credit_note', 'debit_note')", ['kind'], 'sales_documents: unknown document kind');
        TableChecks::add('sales_documents', 'status_values', "status IN ('draft', 'awaiting_approval', 'issued', 'partially_paid', 'paid', 'overdue', 'void', 'accepted', 'declined', 'expired')", ['status'], 'sales_documents: unknown status');
        TableChecks::add('sales_documents', 'currency_format', 'length(currency) = 3 AND currency = upper(currency) AND length(base_currency) = 3', ['currency', 'base_currency'], 'sales_documents: currency is three upper-case letters');
        TableChecks::add('sales_documents', 'totals_add_up', 'total_cents = net_cents + tax_cents AND base_total_cents = base_net_cents + base_tax_cents', ['total_cents', 'net_cents', 'tax_cents', 'base_total_cents', 'base_net_cents', 'base_tax_cents'], 'sales_documents: net plus tax must equal the total');
        TableChecks::add('sales_documents', 'totals_not_negative', 'net_cents >= 0 AND total_cents >= 0 AND base_total_cents >= 0 AND written_off_cents >= 0 AND written_off_base_cents >= 0', ['net_cents', 'total_cents', 'base_total_cents', 'written_off_cents', 'written_off_base_cents'], 'sales_documents: amounts are never negative');
        TableChecks::add('sales_documents', 'numbered_when_sealed', '(sealed_at IS NULL) = (number IS NULL) AND (status IN (\'draft\', \'awaiting_approval\') OR sealed_at IS NOT NULL)', ['sealed_at', 'number', 'status'], 'sales_documents: a document is numbered and sealed exactly when it leaves draft');
        TableChecks::add('sales_documents', 'notes_need_parent', "kind NOT IN ('credit_note', 'debit_note') OR parent_id IS NOT NULL", ['kind', 'parent_id'], 'sales_documents: a credit or debit note names the invoice it adjusts');
        TableChecks::add('sales_documents', 'fx_rate_positive', 'fx_rate_e8 IS NULL OR fx_rate_e8 > 0', ['fx_rate_e8'], 'sales_documents: an exchange rate must be greater than zero');
        TableChecks::add('sales_documents', 'void_pair', "(status = 'void') = (voided_at IS NOT NULL)", ['status', 'voided_at'], 'sales_documents: a void document has a void time and no other does');
    }

    private function lines(): void
    {
        Schema::create('sales_document_lines', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('document_id')->constrained('sales_documents')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('description', 500);
            $table->string('unit', 20)->nullable();
            $table->bigInteger('quantity_e4');                  // quantity x 10,000: no floats
            $table->bigInteger('unit_cents');
            $table->unsignedSmallInteger('discount_bp')->default(0);
            $table->bigInteger('discount_cents')->default(0);
            $table->string('tax_code', 40)->nullable();
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes');
            $table->unsignedInteger('rate_bp')->default(0);
            $table->bigInteger('before_discount_cents')->default(0);
            $table->bigInteger('line_discount_cents')->default(0);
            $table->bigInteger('net_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('gross_cents')->default(0);
            $table->foreignId('income_account_id')->nullable()->constrained('gl_accounts');
            $table->foreignId('parent_line_id')->nullable()->constrained('sales_document_lines');
            $table->string('box_output', 10)->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'position']);
            $table->index('parent_line_id');
        });
        TableChecks::ownerRule('sales_document_lines');
        TableChecks::add('sales_document_lines', 'quantity_positive', 'quantity_e4 > 0', ['quantity_e4'], 'sales_document_lines: a quantity is greater than zero');
        TableChecks::add('sales_document_lines', 'unit_not_negative', 'unit_cents >= 0', ['unit_cents'], 'sales_document_lines: a unit price is not negative');
        TableChecks::add('sales_document_lines', 'discount_range', 'discount_bp <= 10000 AND discount_cents >= 0', ['discount_bp', 'discount_cents'], 'sales_document_lines: a discount is 0 to 100 percent');
        TableChecks::add('sales_document_lines', 'line_adds_up', 'gross_cents = net_cents + tax_cents', ['gross_cents', 'net_cents', 'tax_cents'], 'sales_document_lines: net plus tax must equal the line total');
    }

    private function applications(): void
    {
        Schema::create('sales_applications', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->string('kind', 8);                          // payment | credit
            $table->foreignId('payment_id')->nullable()->constrained('payments');
            $table->foreignId('allocation_id')->nullable()->unique()->constrained('payment_allocations');
            $table->foreignId('source_document_id')->nullable()->constrained('sales_documents');   // the credit note
            $table->foreignId('target_document_id')->constrained('sales_documents');               // the invoice or debit note
            $table->bigInteger('amount_cents');                 // in the document currency
            $table->bigInteger('target_base_cents');            // base value cleared from the invoice
            $table->bigInteger('source_base_cents');            // base value used from the payment or credit note
            $table->bigInteger('fx_diff_base_cents')->default(0);   // source - target: positive is a gain
            $table->unsignedBigInteger('fx_entry_id')->nullable();
            $table->date('applied_on');
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('release_reason', 255)->nullable();
            $table->timestamps();

            $table->index('target_document_id');
            $table->index('source_document_id');
            $table->index('payment_id');
        });
        TableChecks::ownerRule('sales_applications');
        TableChecks::add('sales_applications', 'kind_values', "kind IN ('payment', 'credit')", ['kind'], 'sales_applications: unknown kind');
        TableChecks::add('sales_applications', 'amount_positive', 'amount_cents > 0 AND target_base_cents > 0 AND source_base_cents > 0', ['amount_cents', 'target_base_cents', 'source_base_cents'], 'sales_applications: an application is greater than zero');
        TableChecks::add('sales_applications', 'diff_adds_up', 'fx_diff_base_cents = source_base_cents - target_base_cents', ['fx_diff_base_cents', 'source_base_cents', 'target_base_cents'], 'sales_applications: the exchange difference is source minus target');
        TableChecks::add('sales_applications', 'kind_shape', "(kind = 'payment' AND payment_id IS NOT NULL AND allocation_id IS NOT NULL AND source_document_id IS NULL) OR (kind = 'credit' AND source_document_id IS NOT NULL AND payment_id IS NULL AND allocation_id IS NULL)", ['kind', 'payment_id', 'allocation_id', 'source_document_id'], 'sales_applications: a payment application names a payment, a credit application names a credit note');
    }

    private function refundsAndWriteOffs(): void
    {
        Schema::create('sales_refunds', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('party_id')->constrained('parties');
            $table->foreignId('payment_id')->unique()->constrained('payments');          // the money going out
            $table->string('source_kind', 12);                  // payment | credit_note
            $table->foreignId('source_payment_id')->nullable()->constrained('payments');
            $table->foreignId('source_document_id')->nullable()->constrained('sales_documents');
            $table->char('currency', 3);
            $table->bigInteger('amount_cents');
            $table->bigInteger('source_base_cents');            // the base value carried by the credit that is given back
            $table->bigInteger('bank_base_cents');              // the base value that left the bank
            $table->string('reason', 255)->nullable();
            $table->string('status', 10)->default('recorded');
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        TableChecks::ownerRule('sales_refunds');
        TableChecks::add('sales_refunds', 'source_values', "source_kind IN ('payment', 'credit_note')", ['source_kind'], 'sales_refunds: unknown source');
        TableChecks::add('sales_refunds', 'status_values', "status IN ('recorded', 'reversed')", ['status'], 'sales_refunds: unknown status');
        TableChecks::add('sales_refunds', 'amount_positive', 'amount_cents > 0 AND source_base_cents > 0 AND bank_base_cents > 0', ['amount_cents', 'source_base_cents', 'bank_base_cents'], 'sales_refunds: a refund is greater than zero');
        TableChecks::add('sales_refunds', 'source_shape', "(source_kind = 'payment' AND source_payment_id IS NOT NULL AND source_document_id IS NULL) OR (source_kind = 'credit_note' AND source_document_id IS NOT NULL AND source_payment_id IS NULL)", ['source_kind', 'source_payment_id', 'source_document_id'], 'sales_refunds: a refund names exactly one source');

        Schema::create('sales_write_offs', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('document_id')->constrained('sales_documents');
            $table->bigInteger('amount_cents');
            $table->bigInteger('base_cents')->default(0);
            $table->string('reason', 255);
            $table->string('status', 10)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('gl_entry_id')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index('document_id');
        });
        TableChecks::ownerRule('sales_write_offs');
        TableChecks::add('sales_write_offs', 'status_values', "status IN ('pending', 'applied', 'rejected', 'cancelled')", ['status'], 'sales_write_offs: unknown status');
        TableChecks::add('sales_write_offs', 'amount_positive', 'amount_cents > 0', ['amount_cents'], 'sales_write_offs: a write-off is greater than zero');
        TableChecks::add('sales_write_offs', 'reason_required', 'length(reason) > 0', ['reason'], 'sales_write_offs: a write-off needs a reason');
    }

    private function trailAndLinks(): void
    {
        Schema::create('sales_document_events', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('document_id')->constrained('sales_documents');
            $table->string('kind', 30);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['document_id', 'id']);
        });
        TableChecks::ownerRule('sales_document_events');

        Schema::create('sales_share_links', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->string('kind', 10);                          // document | statement
            $table->foreignId('document_id')->nullable()->constrained('sales_documents');
            $table->foreignId('party_id')->nullable()->constrained('parties');
            $table->jsonb('options')->nullable();                // statement period
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('document_id');
        });
        TableChecks::ownerRule('sales_share_links');
        TableChecks::add('sales_share_links', 'kind_values', "kind IN ('document', 'statement')", ['kind'], 'sales_share_links: unknown kind');
        TableChecks::add('sales_share_links', 'kind_shape', "(kind = 'document' AND document_id IS NOT NULL) OR (kind = 'statement' AND party_id IS NOT NULL)", ['kind', 'document_id', 'party_id'], 'sales_share_links: a link names a document or a customer');
    }

    private function remindersAndRecurring(): void
    {
        Schema::create('sales_reminders', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('document_id')->constrained('sales_documents');
            $table->foreignId('party_id')->constrained('parties');
            $table->string('stage', 12);                         // before_due | on_due | after_due
            $table->smallInteger('offset_days');
            $table->date('scheduled_for');
            $table->string('outcome', 20)->default('pending');   // pending | handed_to_mailer | failed | skipped_opt_out | skipped_no_email | skipped_paid
            $table->string('to_address', 255)->nullable();
            $table->string('detail', 255)->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            // one reminder per document, stage and offset: running the command twice sends once
            $table->unique(['document_id', 'stage', 'offset_days'], 'sales_reminders_once');
            $table->index(['scheduled_for']);
        });
        TableChecks::ownerRule('sales_reminders');
        TableChecks::add('sales_reminders', 'stage_values', "stage IN ('before_due', 'on_due', 'after_due')", ['stage'], 'sales_reminders: unknown stage');

    }

    /** The database-level promise: a numbered document and its lines cannot be changed or deleted. */
    private function sealing(): void
    {
        $message = 'an issued sales document cannot be changed or deleted: void it or issue a credit note';

        if (DB::getDriverName() === 'sqlite') {
            $changed = collect(self::SEALED)->map(fn ($c) => "NEW.{$c} IS NOT OLD.{$c}")->implode(' OR ');
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS sales_documents_sealed_update BEFORE UPDATE ON sales_documents WHEN OLD.sealed_at IS NOT NULL AND (({$changed}) OR NEW.status IN ('draft', 'awaiting_approval')) BEGIN SELECT RAISE(ABORT, '{$message}'); END");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS sales_documents_sealed_delete BEFORE DELETE ON sales_documents WHEN OLD.sealed_at IS NOT NULL BEGIN SELECT RAISE(ABORT, '{$message}'); END");
            foreach (['INSERT' => 'NEW', 'UPDATE' => 'OLD', 'DELETE' => 'OLD'] as $op => $row) {
                $guard = $op === 'UPDATE' ? '(SELECT sealed_at FROM sales_documents WHERE id = OLD.document_id) IS NOT NULL OR (SELECT sealed_at FROM sales_documents WHERE id = NEW.document_id) IS NOT NULL' : "(SELECT sealed_at FROM sales_documents WHERE id = {$row}.document_id) IS NOT NULL";
                DB::unprepared('CREATE TRIGGER IF NOT EXISTS sales_document_lines_sealed_'.strtolower($op)." BEFORE {$op} ON sales_document_lines WHEN {$guard} BEGIN SELECT RAISE(ABORT, '{$message}'); END");
            }
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS sales_document_events_no_update BEFORE UPDATE ON sales_document_events BEGIN SELECT RAISE(ABORT, 'the document trail is append-only'); END");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS sales_document_events_no_delete BEFORE DELETE ON sales_document_events BEGIN SELECT RAISE(ABORT, 'the document trail is append-only'); END");

            return;
        }

        $changed = collect(self::SEALED)->map(fn ($c) => "NEW.{$c} IS DISTINCT FROM OLD.{$c}")->implode(' OR ');
        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION sales_documents_sealed() RETURNS trigger AS \$\$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.sealed_at IS NOT NULL THEN
            RAISE EXCEPTION '{$message}' USING ERRCODE = 'integrity_constraint_violation';
        END IF;
        RETURN OLD;
    END IF;
    IF OLD.sealed_at IS NOT NULL AND (({$changed}) OR NEW.status IN ('draft', 'awaiting_approval')) THEN
        RAISE EXCEPTION '{$message}' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    RETURN NEW;
END;
\$\$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER sales_documents_sealed BEFORE UPDATE OR DELETE ON sales_documents FOR EACH ROW EXECUTE FUNCTION sales_documents_sealed()');

        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION sales_document_lines_sealed() RETURNS trigger AS \$\$
DECLARE sealed timestamp;
BEGIN
    IF TG_OP IN ('UPDATE', 'DELETE') THEN
        SELECT sealed_at INTO sealed FROM sales_documents WHERE id = OLD.document_id;
        IF sealed IS NOT NULL THEN
            RAISE EXCEPTION '{$message}' USING ERRCODE = 'integrity_constraint_violation';
        END IF;
    END IF;
    IF TG_OP IN ('INSERT', 'UPDATE') THEN
        SELECT sealed_at INTO sealed FROM sales_documents WHERE id = NEW.document_id;
        IF sealed IS NOT NULL THEN
            RAISE EXCEPTION '{$message}' USING ERRCODE = 'integrity_constraint_violation';
        END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
\$\$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER sales_document_lines_sealed BEFORE INSERT OR UPDATE OR DELETE ON sales_document_lines FOR EACH ROW EXECUTE FUNCTION sales_document_lines_sealed()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION sales_document_events_append_only() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'the document trail is append-only' USING ERRCODE = 'integrity_constraint_violation';
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER sales_document_events_immutable BEFORE UPDATE OR DELETE ON sales_document_events FOR EACH ROW EXECUTE FUNCTION sales_document_events_append_only()');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['sales_documents_sealed_update', 'sales_documents_sealed_delete', 'sales_document_lines_sealed_insert', 'sales_document_lines_sealed_update', 'sales_document_lines_sealed_delete', 'sales_document_events_no_update', 'sales_document_events_no_delete'] as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }
        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS sales_documents_sealed ON sales_documents');
            DB::unprepared('DROP TRIGGER IF EXISTS sales_document_lines_sealed ON sales_document_lines');
            DB::unprepared('DROP TRIGGER IF EXISTS sales_document_events_immutable ON sales_document_events');
            DB::unprepared('DROP FUNCTION IF EXISTS sales_documents_sealed()');
            DB::unprepared('DROP FUNCTION IF EXISTS sales_document_lines_sealed()');
            DB::unprepared('DROP FUNCTION IF EXISTS sales_document_events_append_only()');
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['settlement_ref']);
            $table->dropColumn('settlement_ref');
        });

        foreach (['sales_recurring_templates', 'sales_reminders', 'sales_share_links', 'sales_document_events', 'sales_write_offs', 'sales_refunds', 'sales_applications', 'sales_document_lines', 'sales_documents', 'sales_party_prefs', 'sales_profiles'] as $table) {
            $this->dropChecks($table);
            Schema::dropIfExists($table);
        }
    }

    private function dropChecks(string $table): void
    {
        $rules = [
            'sales_profiles' => ['owner_rule', 'terms_range'],
            'sales_party_prefs' => ['owner_rule'],
            'sales_documents' => ['owner_rule', 'kind_values', 'status_values', 'currency_format', 'totals_add_up', 'totals_not_negative', 'numbered_when_sealed', 'notes_need_parent', 'fx_rate_positive', 'void_pair'],
            'sales_document_lines' => ['owner_rule', 'quantity_positive', 'unit_not_negative', 'discount_range', 'line_adds_up'],
            'sales_applications' => ['owner_rule', 'kind_values', 'amount_positive', 'diff_adds_up', 'kind_shape'],
            'sales_refunds' => ['owner_rule', 'source_values', 'status_values', 'amount_positive', 'source_shape'],
            'sales_write_offs' => ['owner_rule', 'status_values', 'amount_positive', 'reason_required'],
            'sales_document_events' => ['owner_rule'],
            'sales_share_links' => ['owner_rule', 'kind_values', 'kind_shape'],
            'sales_reminders' => ['owner_rule', 'stage_values'],
            'sales_recurring_templates' => ['owner_rule', 'frequency_values', 'mode_values', 'status_values', 'every_positive'],
        ];

        foreach ($rules[$table] ?? [] as $rule) {
            TableChecks::drop($table, $rule);
        }
    }
};
