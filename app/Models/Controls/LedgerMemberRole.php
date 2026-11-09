<?php

namespace App\Models\Controls;

use App\Enums\FinanceRole;
use App\Models\Concerns\HasLedgerContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A local role grant: this person holds $role in this company ledger, refining the baseline
 * InfoDot gives them. Never above what InfoDot allows (FinanceRole::grantable) and never above
 * the grantor's own role; see App\Services\Controls\LedgerRoles. Audit rows are written by that
 * service (with the member in the context), not by the generic observer.
 */
class LedgerMemberRole extends Model
{
    use HasLedgerContext;

    protected $fillable = ['user_id', 'team_id', 'member_user_id', 'role', 'granted_by', 'granted_at'];

    protected function casts(): array
    {
        return ['role' => FinanceRole::class, 'granted_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_user_id');
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
