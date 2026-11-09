<?php

namespace App\Models\Planning;

use App\Casts\DateOnly;
use App\Models\Accounting\GlAccount;
use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One cell of a plan: an income or expense account, an optional dimension, a month, an amount. Income cells are revenue targets. */
class PlanBudgetLine extends Model
{
    use HasLedgerContext;

    protected $fillable = ['user_id', 'team_id', 'plan_budget_id', 'gl_account_id', 'dimension', 'month', 'amount_cents'];

    protected function casts(): array
    {
        return ['month' => DateOnly::class, 'amount_cents' => 'integer'];
    }

    public function ledgerInheritsFrom(): ?string
    {
        return 'plan_budget_id';
    }

    public function ledgerReferences(): array
    {
        return ['plan_budget_id' => PlanBudget::class, 'gl_account_id' => GlAccount::class];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(PlanBudget::class, 'plan_budget_id');
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class);
    }
}
