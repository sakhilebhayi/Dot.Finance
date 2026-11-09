<?php

namespace App\Models\Planning;

use App\Casts\DateOnly;
use App\Contracts\Approvable;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Controls\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A plan for a fiscal year (or part of one): monthly amounts per income or expense account and optional dimension.
 * Income cells are revenue targets, expense cells are spending limits.
 *
 * It is not part of the books. Status: draft -> pending -> approved (or rejected, back to draft to edit); an
 * approved plan is never edited in place, a new version or a re-forecast is made from it. When an approval
 * policy covers budgets (Controls), submitting asks the approvers; otherwise submitting approves it.
 */
class PlanBudget extends Model implements Approvable
{
    use HasLedgerContext;

    public const STATUSES = ['draft', 'pending', 'approved', 'rejected', 'archived'];

    protected $fillable = [
        'user_id', 'team_id', 'name', 'kind', 'fiscal_year', 'starts_on', 'ends_on', 'currency', 'status', 'based_on_id', 'method',
        'as_of', 'version', 'created_by', 'submitted_at', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'starts_on' => DateOnly::class,
            'ends_on' => DateOnly::class,
            'as_of' => DateOnly::class,
            'version' => 'integer',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function ledgerReferences(): array
    {
        return ['based_on_id' => self::class];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PlanBudgetLine::class);
    }

    /** The plan's cells, read for this plan's own ledger whether or not a ledger is active (a console job or a test has none). */
    public function cells(): Builder
    {
        return PlanBudgetLine::query()->forContext($this->ledgerContext())->where('plan_budget_lines.plan_budget_id', $this->id);
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }

    public function isReforecast(): bool
    {
        return $this->kind === 'reforecast';
    }

    // ---- Approvable ----------------------------------------------------------------------

    public function approvalSubjectKind(): string
    {
        return 'budget';
    }

    /** The spending the plan commits to: the sum of its expense cells (revenue targets are not spend). */
    public function approvalAmountCents(): int
    {
        return (int) PlanBudgetLine::query()->forContext($this->ledgerContext())->where('plan_budget_lines.plan_budget_id', $this->id)
            ->join('gl_accounts as g', 'g.id', '=', 'plan_budget_lines.gl_account_id')->where('g.class', 'expense')->sum('plan_budget_lines.amount_cents');
    }

    public function approvalCurrency(): ?string
    {
        return $this->currency;
    }

    public function approvalLabel(): string
    {
        return 'Budget '.$this->fiscal_year.' v'.$this->version;
    }

    public function onApprovalDecided(ApprovalRequest $request): void
    {
        $this->forceFill(match ($request->status) {
            'approved' => ['status' => 'approved', 'decided_at' => now(), 'decided_by' => $request->decided_by],
            'rejected' => ['status' => 'rejected', 'decided_at' => now(), 'decided_by' => $request->decided_by],
            default => ['status' => 'draft', 'submitted_at' => null],
        })->save();
    }

    public function auditLabel(): string
    {
        return $this->approvalLabel();
    }
}
