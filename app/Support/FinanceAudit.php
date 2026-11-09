<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Bank\BankRule;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Controls\AccessReview;
use App\Models\Controls\ApprovalPolicy;
use App\Models\Controls\ApprovalRequest;
use App\Models\Controls\LedgerMemberRole;
use App\Models\Controls\SpendLimit;
use App\Models\FinanceAuditLog;
use App\Models\Planning\Dimension;
use App\Models\Planning\PlanBudget;
use App\Models\Planning\Scenario;
use App\Models\Purchases\Bill;
use App\Models\Purchases\ExpenseClaim;
use App\Models\Purchases\PaymentRun;
use App\Models\Purchases\PurchaseSetting;
use App\Models\Purchases\RecurringBill;
use App\Models\Purchases\SupplierProfile;
use App\Models\RecurringRule;
use App\Models\Reporting\ReportPreset;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Models\Shared\PaymentAllocation;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Knows what to record about each model, and writes the rows.
 *
 * "Values" fields are logged with before/after because they are amounts, dates,
 * ids, enums: numbers an auditor needs and that hold nothing personal. "Text"
 * fields (descriptions, notes, institution) are free text the user typed; only
 * the fact that they changed is logged, never the words.
 */
class FinanceAudit
{
    private const SUBJECTS = [
        Transaction::class => [
            'type' => 'transaction',
            'values' => ['account_id', 'category_id', 'amount_cents', 'currency', 'type', 'date', 'transfer_direction', 'transfer_group', 'recurring_rule_id'],
            'text' => ['description', 'notes'],
        ],
        Account::class => [
            'type' => 'account',
            'values' => ['name', 'type', 'currency', 'opening_balance_cents', 'opening_balance_date'],
            'text' => ['institution'],
        ],
        Category::class => [
            'type' => 'category',
            'values' => ['name', 'type', 'color', 'icon'],
            'text' => [],
        ],
        Budget::class => [
            'type' => 'budget',
            'values' => ['category_id', 'amount_cents', 'currency', 'month', 'year'],
            'text' => [],
        ],
        RecurringRule::class => [
            'type' => 'recurring_rule',
            'values' => ['account_id', 'category_id', 'amount_cents', 'currency', 'type', 'recurrence_type', 'recurrence_interval', 'start_date', 'next_occurrence_date', 'end_date', 'paused_at', 'ended_at'],
            'text' => ['description', 'notes'],
        ],

        // ----- R-44 Controls (F3): roles, approvals, policies, limits, access reviews -----
        // Policies and limits are logged by the generic observer; role grants, approval requests and
        // access reviews are logged explicitly by their services (they carry an action and a context).
        LedgerMemberRole::class => [
            'type' => 'member_role',
            'values' => ['member_user_id', 'role'],
            'text' => [],
        ],
        ApprovalPolicy::class => [
            'type' => 'approval_policy',
            'values' => ['subject_type', 'threshold_cents', 'currency', 'approver_capability', 'min_role', 'steps_required', 'allow_self_approval', 'is_active'],
            'text' => ['name'],
        ],
        ApprovalRequest::class => [
            'type' => 'approval_request',
            'values' => ['subject_kind', 'amount_cents', 'currency', 'status', 'steps_required', 'steps_done'],
            'text' => ['reason', 'request_note'],
        ],
        SpendLimit::class => [
            'type' => 'spend_limit',
            'values' => ['member_user_id', 'role', 'currency', 'period', 'limit_cents'],
            'text' => [],
        ],
        AccessReview::class => [
            'type' => 'access_review',
            'values' => ['member_count'],
            'text' => ['notes'],
        ],
        // ----- end R-44 Controls -----
        // R-44 Bank: rules are audited per row; statements, matches and reconciliations are logged by their services.
        BankRule::class => [
            'type' => 'bank_rule',
            'values' => ['account_id', 'match_field', 'operator', 'direction', 'min_cents', 'max_cents', 'category_id', 'priority', 'is_active'],
            'text' => ['name', 'pattern', 'new_description'],
        ],
        // R-44 shared data layer (F2)
        Party::class => [
            'type' => 'party',
            'values' => ['type', 'name', 'currency', 'payment_terms_days', 'credit_limit_cents', 'status'],
            'text' => ['legal_name', 'email', 'phone', 'tax_number', 'registration_number', 'billing_address', 'shipping_address', 'external_ref', 'notes'],
        ],
        Payment::class => [
            'type' => 'payment',
            'values' => ['direction', 'party_id', 'cash_account_id', 'amount_cents', 'currency', 'fx_rate_e8', 'paid_on', 'method', 'status', 'gl_entry_id', 'transaction_id'],
            'text' => ['reference', 'reversal_reason', 'notes'],
        ],
        PaymentAllocation::class => [
            'type' => 'payment_alloc',
            'values' => ['payment_id', 'allocatable_type', 'allocatable_id', 'amount_cents', 'tax_cents', 'fx_diff_cents', 'released_at'],
            'text' => ['release_reason'],
        ],
        // ----- R-44 Purchases -----
        Bill::class => [
            'type' => 'bill',
            'values' => ['kind', 'party_id', 'number', 'status', 'bill_date', 'due_date', 'currency', 'total_cents', 'tax_cents', 'claimable_tax_cents', 'document_type', 'credit_for_bill_id'],
            'text' => ['supplier_reference', 'notes', 'credit_reason', 'void_reason', 'rejection_reason', 'post_error'],
        ],
        ExpenseClaim::class => [
            'type' => 'expense_claim',
            'values' => ['claimant_user_id', 'number', 'status', 'claim_date', 'total_cents', 'tax_cents', 'claimable_tax_cents'],
            'text' => ['title', 'notes', 'void_reason', 'rejection_reason', 'post_error'],
        ],
        PaymentRun::class => [
            'type' => 'payment_run',
            'values' => ['due_by', 'paid_on', 'cash_account_id', 'currency', 'status', 'total_cents', 'supplier_count'],
            'text' => ['name'],
        ],
        RecurringBill::class => [
            'type' => 'recurring_bill',
            'values' => ['party_id', 'frequency', 'interval_count', 'next_run_on', 'end_on', 'status', 'currency'],
            'text' => ['name'],
        ],
        PurchaseSetting::class => [
            'type' => 'purchase_setting',
            'values' => ['vat_registered', 'missing_document_threshold_cents'],
            'text' => [],
        ],
        SupplierProfile::class => [
            'type' => 'supplier_profile',
            'values' => ['party_id', 'default_gl_account_id', 'default_tax_code', 'early_payment_discount_bp', 'early_payment_days'],
            'text' => [],
        ],
        // ----- end R-44 Purchases -----
        // ----- R-44 Reporting and Planning -----
        PlanBudget::class => [
            'type' => 'plan_budget',
            'values' => ['kind', 'fiscal_year', 'starts_on', 'ends_on', 'currency', 'status', 'based_on_id', 'method', 'version'],
            'text' => ['name'],
        ],
        Scenario::class => [
            'type' => 'scenario',
            'values' => ['horizon'],
            'text' => ['name', 'assumptions'],
        ],
        Dimension::class => [
            'type' => 'dimension',
            'values' => ['ref', 'kind', 'is_active'],
            'text' => ['name'],
        ],
        ReportPreset::class => [
            'type' => 'report_preset',
            'values' => ['report_key'],
            'text' => ['name', 'params'],
        ],
        // ----- end R-44 Reporting and Planning -----
    ];

    private static int $muted = 0;

    private static ?string $contextOverride = null;

    /** Attach $context to every audit row written while $callback runs (e.g. the reason for an adjustment). */
    public static function withContext(string $context, callable $callback): mixed
    {
        $previous = self::$contextOverride;
        self::$contextOverride = $context;

        try {
            return $callback();
        } finally {
            self::$contextOverride = $previous;
        }
    }

    /** Run $callback without per-row observer logging (the CSV import logs one summary row instead). */
    public static function withoutRowLogging(callable $callback): mixed
    {
        self::$muted++;

        try {
            return $callback();
        } finally {
            self::$muted--;
        }
    }

    public static function isMuted(): bool
    {
        return self::$muted > 0;
    }

    public static function subjectType(Model $model): ?string
    {
        return self::SUBJECTS[$model::class]['type'] ?? null;
    }

    /** @return array{type: string, values: list<string>, text: list<string>}|null */
    public static function spec(Model $model): ?array
    {
        return self::SUBJECTS[$model::class] ?? null;
    }

    /**
     * Append one row. $changes is already shaped {field: {...}}.
     *
     * $owner is the context the change happened in ({user_id, team_id}, exactly one set); the
     * acting person is recorded separately, because a company's log holds many people's actions.
     *
     * @param  array{user_id: ?int, team_id: ?int}  $owner
     */
    public static function record(array $owner, string $action, string $subjectType, ?int $subjectId, ?string $label, ?array $changes = null, ?string $context = null, ?string $currency = null, ?int $actorUserId = null): FinanceAuditLog
    {
        $context ??= self::$contextOverride;
        $actorUserId ??= Auth::id();

        return FinanceAuditLog::create([
            'user_id' => $owner['user_id'],
            'team_id' => $owner['team_id'],
            'action' => $action,
            'actor' => $actorUserId !== null ? 'user' : 'system',
            'actor_user_id' => $actorUserId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $label !== null ? mb_substr($label, 0, 255) : null,
            'currency' => $currency,
            'changes' => $changes === [] ? null : $changes,
            'context' => $context !== null ? mb_substr($context, 0, 255) : null,
        ]);
    }

    /**
     * Someone took figures out of the system (a download, a spreadsheet, a print): who, what, for which period. The file itself is not
     * kept. $details are plain values such as the report key, the format and the period.
     *
     * @param  array{user_id: ?int, team_id: ?int}  $owner
     * @param  array<string, scalar|null>  $details
     */
    public static function exported(array $owner, string $label, array $details = []): FinanceAuditLog
    {
        $changes = [];
        foreach ($details as $field => $value) {
            $changes[$field] = ['after' => $value];
        }

        return self::record($owner, 'exported', 'report_export', null, $label, $changes);
    }

    /**
     * A label for the audit page that never contains free text: accounts and
     * categories are named (the name is a label, not a memo); a transaction or
     * rule is described by its shape.
     */
    public static function label(Model $model): string
    {
        return match (true) {
            $model instanceof Account, $model instanceof Category => (string) $model->name,
            $model instanceof Transaction => ucfirst($model->type).' '.Money::format((int) $model->amount_cents, $model->currency).' on '.$model->date?->toDateString(),
            $model instanceof RecurringRule => ucfirst($model->recurrence_type).' '.$model->type.' '.Money::format((int) $model->amount_cents, $model->currency),
            $model instanceof Party => 'Party '.$model->name,
            $model instanceof Payment => ucfirst($model->direction === 'in' ? 'received' : 'paid').' '.Money::format((int) $model->amount_cents, $model->currency).' on '.$model->paid_on?->toDateString(),
            $model instanceof PaymentAllocation => 'Allocation '.Money::format((int) $model->amount_cents),
            $model instanceof Budget => 'Budget '.sprintf('%04d-%02d', $model->year, $model->month).' '.$model->currency,
            // R-44 Controls models describe themselves without free text.
            method_exists($model, 'auditLabel') => $model->auditLabel(),
            default => class_basename($model),
        };
    }
}
