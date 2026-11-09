<?php

namespace App\Models\Controls;

use App\Enums\FinanceRole;
use App\Models\Concerns\HasLedgerContext;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The most an approver may approve: per approval, or in total per day / week / month, for one
 * person or for everyone holding a role, in one currency. See SpendLimits for how they combine.
 */
class SpendLimit extends Model
{
    use HasLedgerContext;

    public const PERIODS = ['per_approval' => 'Per approval', 'daily' => 'Per day', 'weekly' => 'Per week', 'monthly' => 'Per month'];

    protected $fillable = ['user_id', 'team_id', 'member_user_id', 'role', 'currency', 'period', 'limit_cents', 'created_by'];

    protected function casts(): array
    {
        return ['limit_cents' => 'integer'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_user_id');
    }

    public function roleEnum(): ?FinanceRole
    {
        return $this->role ? FinanceRole::tryFrom($this->role) : null;
    }

    public function auditLabel(): string
    {
        return 'Limit '.Money::format((int) $this->limit_cents, $this->currency).' '.strtolower(self::PERIODS[$this->period] ?? $this->period);
    }
}
