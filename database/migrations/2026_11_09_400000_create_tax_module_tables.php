<?php

use App\Support\Database\TableChecks;
use Database\Seeders\TaxHolidaysSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-44 Tax module: the financial profile, the tax calendar, VAT201 / EMP201 returns and their adjustments,
 * the income-tax working papers, payroll tax summaries, the document vault and correspondence log.
 *
 * Every ledger table carries the owner pair (user_id XOR team_id), RESTRICT foreign keys (a tax record is not
 * removed as a side effect of removing a person) and CHECKs that hold on PostgreSQL and SQLite alike.
 *
 * A return that a person has recorded as submitted to SARS (or paid) is frozen by the database itself, not only
 * by the models: a trigger on both engines refuses to change its figures, period, reference or status history,
 * and refuses to delete it. Adjustments can only be added while their return is still a draft.
 *
 * tax_holidays is global reference data (no owner), seeded by this migration (see TaxHolidays for its provenance).
 */
return new class extends Migration
{
    private const TABLES = [
        'tax_profiles', 'tax_returns', 'tax_deadlines', 'tax_reminders', 'tax_return_adjustments', 'tax_review_resolutions',
        'tax_adjustments', 'tax_income_estimates', 'tax_provisional_payments', 'tax_payroll_runs', 'tax_payroll_lines',
        'tax_documents', 'tax_correspondence', 'tax_checklist_ticks',
    ];

    public function up(): void
    {
        $this->profiles();
        $this->holidays();
        $this->returns();
        $this->deadlines();
        $this->reminders();
        $this->adjustments();
        $this->income();
        $this->payroll();
        $this->vault();

        foreach (self::TABLES as $table) {
            TableChecks::ownerRule($table);
        }

        $this->checks();
        $this->freezeReturns();

        TaxHolidaysSeeder::seed();
    }

    public function down(): void
    {
        $this->unfreezeReturns();

        // Dropping a table drops its CHECKs (PostgreSQL) and its triggers (SQLite).
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
        Schema::dropIfExists('tax_holidays');
    }

    private function owner(Blueprint $table): void
    {
        $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
        $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
    }

    private function profiles(): void
    {
        Schema::create('tax_profiles', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->string('jurisdiction', 8)->default('ZA');
            // sole_proprietor | company | close_corporation | trust | partnership | non_profit
            $table->string('legal_entity', 20);
            // standard | sbc | turnover_tax: how income tax is worked out (the entity type decides which are possible)
            $table->string('tax_regime', 20)->default('standard');
            $table->boolean('vat_registered')->default(false);
            $table->string('vat_number', 20)->nullable();
            $table->string('vat_category', 1)->nullable();
            $table->string('vat_basis', 10)->default('invoice');
            $table->date('vat_registered_on')->nullable();
            // efiling | manual; null = the ledger follows the jurisdiction default rule (D-19)
            $table->string('vat_filing_channel', 10)->nullable();
            $table->string('income_tax_ref', 30)->nullable();
            $table->string('paye_ref', 30)->nullable();
            $table->string('sdl_ref', 30)->nullable();
            $table->string('uif_ref', 30)->nullable();
            $table->string('coida_ref', 30)->nullable();
            // 1..12: the month the financial year ends (12 = December)
            $table->unsignedTinyInteger('financial_year_end_month')->default(2);
            $table->boolean('provisional_taxpayer')->default(false);
            $table->string('accounting_basis', 10)->default('accrual');
            $table->boolean('employs_staff')->default(false);
            // under65 | 65_to_74 | 75_plus: only for a sole proprietor (rebates)
            $table->string('individual_age_band', 10)->nullable();
            // for the small business corporation test; null = not stated
            $table->boolean('shareholders_natural_persons')->nullable();
            $table->timestamp('wizard_completed_at')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->unique('team_id');
        });
    }

    private function holidays(): void
    {
        Schema::create('tax_holidays', function (Blueprint $table) {
            $table->id();
            $table->string('jurisdiction', 8);
            $table->date('holiday_date');
            $table->string('name', 80);
            // statute | gazette | derived. Anything but a published list is labelled unverified in the UI.
            $table->string('source', 12);
            $table->boolean('verified')->default(false);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['jurisdiction', 'holiday_date']);
        });
    }

    private function returns(): void
    {
        Schema::create('tax_returns', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            // vat201 | emp201
            $table->string('kind', 10);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('period_label', 60);
            $table->unsignedSmallInteger('revision')->default(1);
            $table->foreignId('supersedes_return_id')->nullable()->constrained('tax_returns')->restrictOnDelete();
            // draft | prepared | submitted | paid
            $table->string('status', 10)->default('draft');
            // invoice | payments (VAT only)
            $table->string('basis', 10)->nullable();
            $table->string('channel', 10)->nullable();
            $table->jsonb('rule_versions')->nullable();
            // the boxes as computed when the return was prepared, with the line ids behind each
            $table->jsonb('figures')->nullable();
            $table->char('figures_hash', 64)->nullable();
            $table->bigInteger('output_tax_cents')->nullable();
            $table->bigInteger('input_tax_cents')->nullable();
            $table->bigInteger('payable_cents')->nullable();
            $table->jsonb('reconciliation')->nullable();
            $table->jsonb('checks')->nullable();
            $table->unsignedBigInteger('prepared_by_user_id')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->unsignedSmallInteger('export_count')->default(0);
            $table->timestamp('last_exported_at')->nullable();
            // recorded by a person after they filed it themselves on eFiling or at a branch
            $table->string('sars_reference', 60)->nullable();
            $table->date('submitted_on')->nullable();
            $table->unsignedBigInteger('submitted_by_user_id')->nullable();
            $table->unsignedBigInteger('submission_proof_attachment_id')->nullable();
            $table->string('submission_note', 500)->nullable();
            // payment, recorded by a person
            $table->bigInteger('paid_cents')->nullable();
            $table->date('paid_on')->nullable();
            $table->string('payment_reference', 60)->nullable();
            $table->unsignedBigInteger('paid_by_user_id')->nullable();
            $table->unsignedBigInteger('payment_proof_attachment_id')->nullable();
            $table->unsignedBigInteger('settlement_entry_id')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'kind', 'period_start', 'period_end', 'revision'], 'tax_returns_user_period_unique');
            $table->unique(['team_id', 'kind', 'period_start', 'period_end', 'revision'], 'tax_returns_team_period_unique');
            $table->index(['kind', 'status']);
        });
    }

    private function deadlines(): void
    {
        Schema::create('tax_deadlines', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            // vat201 | emp201 | irp6_1 | irp6_2 | irp6_3 | itr14 | itr12 | emp501_interim | emp501_annual | coida_roe
            $table->string('obligation', 24);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('period_label', 80);
            $table->date('base_due_date');
            $table->date('due_date');
            $table->date('payment_due_date')->nullable();
            $table->string('adjustment_note', 500)->nullable();
            // open | prepared | submitted | paid | waived (upcoming, due and overdue are worked out from the dates)
            $table->string('status', 10)->default('open');
            $table->foreignId('tax_return_id')->nullable()->constrained('tax_returns')->restrictOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->jsonb('rule_versions')->nullable();
            $table->string('confidence', 6)->default('high');
            $table->string('waived_reason', 255)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'obligation', 'period_start', 'period_end'], 'tax_deadlines_user_unique');
            $table->unique(['team_id', 'obligation', 'period_start', 'period_end'], 'tax_deadlines_team_unique');
            $table->index(['due_date', 'status']);
        });
    }

    private function reminders(): void
    {
        Schema::create('tax_reminders', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('tax_deadline_id')->constrained('tax_deadlines')->restrictOnDelete();
            // d14 | d7 | d3 | d1 | d0 | overdue
            $table->string('kind', 10);
            $table->date('remind_on');
            $table->string('title', 160);
            $table->string('body', 500);
            // info | warning | overdue
            $table->string('severity', 10)->default('info');
            // The Intelligence module reads rows with delivered_at NULL and stamps them when it has delivered.
            $table->timestamp('delivered_at')->nullable();
            $table->string('delivered_via', 30)->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->unsignedBigInteger('dismissed_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['tax_deadline_id', 'kind']);
            $table->index(['remind_on', 'delivered_at']);
        });
    }

    private function adjustments(): void
    {
        Schema::create('tax_return_adjustments', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('tax_return_id')->constrained('tax_returns')->restrictOnDelete();
            $table->string('box_code', 8);
            // bad_debt | bad_debt_recovered | credit_note | debit_note | apportionment | change_in_use | correction | other
            $table->string('kind', 24);
            // signed: it is added to the box as computed
            $table->bigInteger('amount_cents');
            $table->string('reason', 500);
            $table->unsignedBigInteger('attachment_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by_user_id')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index('tax_return_id');
        });

        Schema::create('tax_review_resolutions', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('journal_line_id')->constrained('journal_lines')->restrictOnDelete();
            $table->string('issue_code', 40);
            // accepted (looked at, left as booked) | corrected (fixed in the books) | ignored
            $table->string('resolution', 10);
            $table->string('note', 500);
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['journal_line_id', 'issue_code']);
        });
    }

    private function income(): void
    {
        Schema::create('tax_adjustments', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            // the date the tax year ends: 2027-02-28, or a company's financial year end
            $table->date('tax_year_end');
            // add_back | deduction | exempt_income | assessed_loss_bf | review_only
            $table->string('kind', 20);
            $table->string('category', 30)->nullable();
            $table->string('label', 160);
            $table->unsignedBigInteger('amount_cents')->default(0);
            $table->string('reason', 500);
            $table->foreignId('gl_account_id')->nullable()->constrained('gl_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('attachment_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by_user_id')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['tax_year_end', 'kind']);
        });

        Schema::create('tax_income_estimates', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->date('tax_year_end');
            $table->string('label', 120);
            $table->string('regime', 24);
            $table->jsonb('figures');
            $table->char('figures_hash', 64);
            $table->bigInteger('taxable_income_cents');
            $table->bigInteger('tax_cents');
            $table->jsonb('rule_versions')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tax_year_end', 'id']);
        });

        Schema::create('tax_provisional_payments', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->date('tax_year_end');
            $table->unsignedTinyInteger('period');
            $table->date('due_date');
            // what the person estimated (their own number) and the tax it produces
            $table->bigInteger('estimate_taxable_income_cents')->nullable();
            $table->bigInteger('basic_amount_cents')->nullable();
            $table->date('basic_amount_year_end')->nullable();
            $table->bigInteger('tax_on_estimate_cents')->nullable();
            $table->bigInteger('amount_due_cents')->nullable();
            $table->bigInteger('paid_cents')->default(0);
            $table->date('paid_on')->nullable();
            $table->string('payment_reference', 60)->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'tax_year_end', 'period'], 'tax_prov_user_unique');
            $table->unique(['team_id', 'tax_year_end', 'period'], 'tax_prov_team_unique');
        });
    }

    private function payroll(): void
    {
        Schema::create('tax_payroll_runs', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->date('period_month');
            // manual | hr_import
            $table->string('source', 10);
            $table->string('hr_run_id', 64)->nullable();
            // draft | posted
            $table->string('status', 8)->default('draft');
            $table->unsignedBigInteger('gross_pay_cents')->default(0);
            $table->unsignedBigInteger('paye_cents')->default(0);
            $table->unsignedBigInteger('uif_employee_cents')->default(0);
            $table->unsignedBigInteger('uif_employer_cents')->default(0);
            $table->unsignedBigInteger('sdl_cents')->default(0);
            $table->unsignedBigInteger('eti_claimed_cents')->default(0);
            $table->unsignedInteger('employee_count')->default(0);
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by_user_id')->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->unsignedBigInteger('entered_by_user_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'period_month'], 'tax_payroll_user_month_unique');
            $table->unique(['team_id', 'period_month'], 'tax_payroll_team_month_unique');
            $table->unique(['user_id', 'hr_run_id'], 'tax_payroll_user_hr_unique');
            $table->unique(['team_id', 'hr_run_id'], 'tax_payroll_team_hr_unique');
        });

        Schema::create('tax_payroll_lines', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('tax_payroll_run_id')->constrained('tax_payroll_runs')->restrictOnDelete();
            $table->string('employee_ref', 64);
            // sensitive: stored encrypted, shown only to people who may see payroll detail
            $table->text('employee_name')->nullable();
            $table->unsignedBigInteger('gross_cents')->default(0);
            $table->unsignedBigInteger('paye_cents')->default(0);
            $table->unsignedBigInteger('uif_employee_cents')->default(0);
            $table->unsignedBigInteger('uif_employer_cents')->default(0);
            $table->unsignedBigInteger('sdl_cents')->default(0);
            $table->unsignedBigInteger('eti_cents')->default(0);
            $table->timestamps();

            $table->unique(['tax_payroll_run_id', 'employee_ref']);
        });
    }

    private function vault(): void
    {
        Schema::create('tax_documents', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->string('category', 24);
            $table->string('title', 160);
            $table->string('period_label', 60)->nullable();
            $table->foreignId('tax_deadline_id')->nullable()->constrained('tax_deadlines')->restrictOnDelete();
            $table->unsignedBigInteger('attachment_id')->nullable();
            $table->date('document_date')->nullable();
            $table->date('retention_until');
            $table->boolean('legal_hold')->default(false);
            $table->string('retention_basis', 255);
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('added_by_user_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['category', 'document_date']);
        });

        Schema::create('tax_correspondence', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            // received | sent
            $table->string('direction', 8);
            // efiling | email | letter | phone | branch | other
            $table->string('channel', 10);
            $table->string('reference', 80)->nullable();
            $table->string('subject', 200);
            $table->text('summary')->nullable();
            $table->date('occurred_on');
            $table->date('response_due_on')->nullable();
            // open | responded | closed
            $table->string('status', 10)->default('open');
            $table->unsignedBigInteger('attachment_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'response_due_on']);
        });

        Schema::create('tax_checklist_ticks', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('tax_deadline_id')->constrained('tax_deadlines')->restrictOnDelete();
            $table->string('item_key', 60);
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('ticked_by_user_id')->nullable();
            $table->timestamp('ticked_at');

            $table->unique(['tax_deadline_id', 'item_key']);
        });
    }

    private function checks(): void
    {
        TableChecks::add('tax_profiles', 'entity_values', "legal_entity IN ('sole_proprietor','company','close_corporation','trust','partnership','non_profit')", ['legal_entity'], 'tax_profiles: unknown legal entity');
        TableChecks::add('tax_profiles', 'regime_values', "tax_regime IN ('standard','sbc','turnover_tax')", ['tax_regime'], 'tax_profiles: unknown tax regime');
        TableChecks::add('tax_profiles', 'category_values', "vat_category IS NULL OR vat_category IN ('A','B','C','D','E','F')", ['vat_category'], 'tax_profiles: unknown VAT category');
        TableChecks::add('tax_profiles', 'basis_values', "vat_basis IN ('invoice','payments')", ['vat_basis'], 'tax_profiles: VAT basis must be invoice or payments');
        TableChecks::add('tax_profiles', 'channel_values', "vat_filing_channel IS NULL OR vat_filing_channel IN ('efiling','manual')", ['vat_filing_channel'], 'tax_profiles: unknown filing channel');
        TableChecks::add('tax_profiles', 'fye_month', 'financial_year_end_month BETWEEN 1 AND 12', ['financial_year_end_month'], 'tax_profiles: month must be 1 to 12');
        TableChecks::add('tax_profiles', 'acct_basis_values', "accounting_basis IN ('accrual','cash')", ['accounting_basis'], 'tax_profiles: accounting basis must be accrual or cash');
        TableChecks::add('tax_profiles', 'age_values', "individual_age_band IS NULL OR individual_age_band IN ('under65','65_to_74','75_plus')", ['individual_age_band'], 'tax_profiles: unknown age band');

        TableChecks::add('tax_deadlines', 'status_values', "status IN ('open','prepared','submitted','paid','waived')", ['status'], 'tax_deadlines: unknown status');
        TableChecks::add('tax_deadlines', 'period_range', 'period_end >= period_start', ['period_start', 'period_end'], 'tax_deadlines: period end before start');

        TableChecks::add('tax_returns', 'kind_values', "kind IN ('vat201','emp201')", ['kind'], 'tax_returns: unknown return kind');
        TableChecks::add('tax_returns', 'status_values', "status IN ('draft','prepared','submitted','paid')", ['status'], 'tax_returns: unknown status');
        TableChecks::add('tax_returns', 'period_range', 'period_end >= period_start', ['period_start', 'period_end'], 'tax_returns: period end before start');
        TableChecks::add('tax_returns', 'submission_complete', "status NOT IN ('submitted','paid') OR (sars_reference IS NOT NULL AND length(trim(sars_reference)) > 0 AND submitted_on IS NOT NULL AND submitted_by_user_id IS NOT NULL)", ['status', 'sars_reference', 'submitted_on', 'submitted_by_user_id'], 'tax_returns: a submitted return needs the SARS reference, the date and who recorded it');
        TableChecks::add('tax_returns', 'prepared_complete', "status = 'draft' OR (figures_hash IS NOT NULL AND prepared_at IS NOT NULL)", ['status', 'figures_hash', 'prepared_at'], 'tax_returns: a prepared return has frozen figures');
        TableChecks::add('tax_returns', 'paid_complete', "status <> 'paid' OR (paid_cents IS NOT NULL AND paid_on IS NOT NULL)", ['status', 'paid_cents', 'paid_on'], 'tax_returns: a paid return needs the amount and the date');

        TableChecks::add('tax_return_adjustments', 'amount_nonzero', 'amount_cents <> 0', ['amount_cents'], 'tax_return_adjustments: an adjustment cannot be zero');

        TableChecks::add('tax_review_resolutions', 'resolution_values', "resolution IN ('accepted','corrected','ignored')", ['resolution'], 'tax_review_resolutions: unknown resolution');

        TableChecks::add('tax_adjustments', 'kind_values', "kind IN ('add_back','deduction','exempt_income','assessed_loss_bf','review_only')", ['kind'], 'tax_adjustments: unknown kind');
        TableChecks::add('tax_provisional_payments', 'period_values', 'period IN (1,2,3)', ['period'], 'tax_provisional_payments: period must be 1, 2 or 3');

        TableChecks::add('tax_payroll_runs', 'status_values', "status IN ('draft','posted')", ['status'], 'tax_payroll_runs: unknown status');
        TableChecks::add('tax_payroll_runs', 'source_values', "source IN ('manual','hr_import')", ['source'], 'tax_payroll_runs: unknown source');
        TableChecks::add('tax_payroll_runs', 'month_start', 'period_month IS NOT NULL', ['period_month'], 'tax_payroll_runs: month required');

        TableChecks::add('tax_correspondence', 'direction_values', "direction IN ('received','sent')", ['direction'], 'tax_correspondence: unknown direction');
        TableChecks::add('tax_correspondence', 'status_values', "status IN ('open','responded','closed')", ['status'], 'tax_correspondence: unknown status');
    }

    /** Frozen once submitted: the figures, the period, the reference and the history of statuses. */
    private function freezeReturns(): void
    {
        $columns = ['kind', 'period_start', 'period_end', 'revision', 'basis', 'figures_hash', 'output_tax_cents', 'input_tax_cents', 'payable_cents', 'sars_reference', 'submitted_on', 'submitted_by_user_id', 'prepared_at'];
        $msg = 'a return recorded as submitted is frozen: its figures, period and SARS reference never change (record a revision instead)';

        if (DB::getDriverName() === 'sqlite') {
            $same = collect($columns)->map(fn ($c) => "OLD.{$c} IS NEW.{$c}")->implode(' AND ');
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS tax_returns_frozen_update BEFORE UPDATE ON tax_returns WHEN OLD.status IN ('submitted','paid') AND (NOT ({$same}) OR NEW.status NOT IN ('submitted','paid') OR (OLD.status = 'paid' AND NEW.status <> 'paid')) BEGIN SELECT RAISE(ABORT, '{$msg}'); END");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS tax_returns_frozen_delete BEFORE DELETE ON tax_returns WHEN OLD.status <> 'draft' BEGIN SELECT RAISE(ABORT, 'only a draft return can be deleted'); END");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS tax_returns_prepared_figures BEFORE UPDATE ON tax_returns WHEN OLD.status = 'prepared' AND NEW.status = 'prepared' AND NOT (OLD.figures_hash IS NEW.figures_hash) BEGIN SELECT RAISE(ABORT, 'a prepared return has frozen figures: withdraw the preparation to change them'); END");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS tax_return_adjustments_draft_only_insert BEFORE INSERT ON tax_return_adjustments WHEN (SELECT status FROM tax_returns WHERE id = NEW.tax_return_id) <> 'draft' BEGIN SELECT RAISE(ABORT, 'adjustments can only be added to a draft return'); END");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS tax_return_adjustments_draft_only_update BEFORE UPDATE ON tax_return_adjustments WHEN (SELECT status FROM tax_returns WHERE id = NEW.tax_return_id) <> 'draft' BEGIN SELECT RAISE(ABORT, 'adjustments can only change on a draft return'); END");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS tax_return_adjustments_no_delete BEFORE DELETE ON tax_return_adjustments BEGIN SELECT RAISE(ABORT, 'adjustments are voided with a reason, never deleted'); END");

            return;
        }

        $distinct = collect($columns)->map(fn ($c) => "OLD.{$c} IS DISTINCT FROM NEW.{$c}")->implode(' OR ');
        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION tax_returns_frozen() RETURNS trigger AS \$\$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status <> 'draft' THEN
            RAISE EXCEPTION 'only a draft return can be deleted' USING ERRCODE = 'integrity_constraint_violation';
        END IF;
        RETURN OLD;
    END IF;
    IF OLD.status IN ('submitted','paid') AND ({$distinct} OR NEW.status NOT IN ('submitted','paid') OR (OLD.status = 'paid' AND NEW.status <> 'paid')) THEN
        RAISE EXCEPTION '{$msg}' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    IF OLD.status = 'prepared' AND NEW.status = 'prepared' AND OLD.figures_hash IS DISTINCT FROM NEW.figures_hash THEN
        RAISE EXCEPTION 'a prepared return has frozen figures: withdraw the preparation to change them' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    RETURN NEW;
END;
\$\$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER tax_returns_frozen_trg BEFORE UPDATE OR DELETE ON tax_returns FOR EACH ROW EXECUTE FUNCTION tax_returns_frozen()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION tax_return_adjustments_guard() RETURNS trigger AS $$
DECLARE s text;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'adjustments are voided with a reason, never deleted' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    SELECT status INTO s FROM tax_returns WHERE id = NEW.tax_return_id;
    IF s <> 'draft' THEN
        RAISE EXCEPTION 'adjustments can only be added or changed on a draft return' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER tax_return_adjustments_guard_trg BEFORE INSERT OR UPDATE OR DELETE ON tax_return_adjustments FOR EACH ROW EXECUTE FUNCTION tax_return_adjustments_guard()');
    }

    private function unfreezeReturns(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['tax_returns_frozen_update', 'tax_returns_frozen_delete', 'tax_returns_prepared_figures', 'tax_return_adjustments_draft_only_insert', 'tax_return_adjustments_draft_only_update', 'tax_return_adjustments_no_delete'] as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }

            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS tax_returns_frozen_trg ON tax_returns');
        DB::unprepared('DROP TRIGGER IF EXISTS tax_return_adjustments_guard_trg ON tax_return_adjustments');
        DB::unprepared('DROP FUNCTION IF EXISTS tax_returns_frozen()');
        DB::unprepared('DROP FUNCTION IF EXISTS tax_return_adjustments_guard()');
    }
};
