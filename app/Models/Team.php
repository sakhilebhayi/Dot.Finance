<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamDeleted;
use Laravel\Jetstream\Events\TeamUpdated;
use Laravel\Jetstream\Team as JetstreamTeam;

class Team extends JetstreamTeam
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'personal_team',
    ];

    /**
     * A company, as far as the ledger is concerned: a non-personal team that InfoDot knows
     * (it has an InfoDot uuid). Only a company can own a company ledger; a personal team or
     * a hand-made team never can.
     */
    public function isCompany(): bool
    {
        return ! $this->personal_team && filled($this->infodot_team_uuid);
    }

    /** Tables whose rows can belong to a company ledger. */
    public const LEDGER_TABLES = [
        'accounts', 'categories', 'budgets', 'transactions', 'recurring_rules', 'finance_audit_log', 'ai_insights',
        // R-44 accounting: journals are append-only books of record and are never removed with a team.
        'ledger_settings', 'gl_accounts', 'accounting_periods', 'journal_entries', 'journal_lines', 'gl_backfill_runs',
        // R-44 controls
        'access_reviews', 'approval_decisions', 'approval_policies', 'approval_requests', 'ledger_member_roles', 'spend_limits',
        // R-44 bank
        'bank_adjustments', 'bank_matches', 'bank_reconciliation_events', 'bank_reconciliations', 'bank_rules', 'bank_statement_lines', 'bank_statements',
        // R-44 purchases
        'bill_lines', 'bills', 'expense_claim_lines', 'expense_claims', 'payment_run_items', 'payment_runs', 'purchase_settings', 'recurring_bills',
        'supplier_bank_details', 'supplier_profiles',
        // R-44 sales
        'sales_applications', 'sales_document_events', 'sales_document_lines', 'sales_documents', 'sales_party_prefs', 'sales_profiles',
        'sales_recurring_templates', 'sales_refunds', 'sales_reminders', 'sales_share_links', 'sales_transaction_links', 'sales_write_offs',
        // R-44 tax
        'tax_adjustments', 'tax_checklist_ticks', 'tax_correspondence', 'tax_deadlines', 'tax_documents', 'tax_income_estimates', 'tax_payroll_lines',
        'tax_payroll_runs', 'tax_profiles', 'tax_provisional_payments', 'tax_reminders', 'tax_return_adjustments', 'tax_returns', 'tax_review_resolutions',
        // R-44 reporting and planning
        'dimensions', 'plan_budget_lines', 'plan_budgets', 'plan_scenarios', 'report_exports', 'report_presets',
        // R-44 intelligence and integrations
        'assistant_drafts', 'assistant_messages', 'finance_insights', 'finance_notifications', 'notification_preferences', 'emall_sale_lines', 'integration_inbound_events',
    ];

    /**
     * R-44 shared data layer (parties, numbering, attachments, payments) and a company's own tax/FX overrides.
     * Kept apart from LEDGER_TABLES so other R-44 modules can add theirs without touching one line.
     */
    public const SHARED_DATA_TABLES = ['parties', 'document_sequences', 'attachments', 'payments', 'payment_allocations', 'tax_codes', 'tax_rules', 'tax_rates', 'fx_rates'];

    /**
     * Tables whose user_id key RESTRICTS deleting a person: the rows of a person's personal ledger that are books of record or
     * module history and are never removed as a side effect of deleting the person (R-44 integration, D-1205). The other
     * personal-ledger tables cascade with the person, as they always did. A test reads the database schema and fails when a
     * table with a restricting user_id key is missing here, so a new module cannot slip past DeleteUser.
     */
    public const USER_RESTRICTED_TABLES = [
        'accounting_periods', 'gl_accounts', 'gl_backfill_runs', 'journal_entries', 'journal_lines', 'ledger_settings',
        'bank_adjustments', 'bank_matches', 'bank_reconciliation_events', 'bank_reconciliations', 'bank_rules', 'bank_statement_lines', 'bank_statements',
        'dimensions', 'plan_budget_lines', 'plan_budgets', 'plan_scenarios', 'report_exports', 'report_presets',
        'tax_adjustments', 'tax_checklist_ticks', 'tax_correspondence', 'tax_deadlines', 'tax_documents', 'tax_income_estimates', 'tax_payroll_lines',
        'tax_payroll_runs', 'tax_profiles', 'tax_provisional_payments', 'tax_reminders', 'tax_return_adjustments', 'tax_returns', 'tax_review_resolutions',
    ];

    /** Whether this person's personal ledger holds any row in a table that restricts deleting them (USER_RESTRICTED_TABLES). */
    public static function personHasRestrictedData(User $user): bool
    {
        foreach (self::USER_RESTRICTED_TABLES as $table) {
            if (DB::table($table)->where('user_id', $user->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this company has any ledger rows here. A company's books are never deleted along
     * with a team or a person (the database refuses it too: the team_id keys restrict deletes).
     */
    public function hasLedgerData(): bool
    {
        foreach ([...self::LEDGER_TABLES, ...self::SHARED_DATA_TABLES] as $table) {
            if (DB::table($table)->where('team_id', $this->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => TeamCreated::class,
        'updated' => TeamUpdated::class,
        'deleted' => TeamDeleted::class,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
        ];
    }
}
