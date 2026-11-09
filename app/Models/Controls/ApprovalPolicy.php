<?php

namespace App\Models\Controls;

use App\Enums\ApprovalSubject;
use App\Enums\Capability;
use App\Enums\FinanceRole;
use App\Models\Concerns\HasLedgerContext;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * "Bills over R5,000 need the owner." Off by default: a ledger with no policy rows asks for no
 * approvals at all. The amount rule is strict: approval is needed when the amount is GREATER
 * than threshold_cents; a NULL threshold means every amount.
 */
class ApprovalPolicy extends Model
{
    use HasLedgerContext;

    protected $fillable = [
        'user_id', 'team_id', 'subject_type', 'name', 'threshold_cents', 'currency', 'approver_capability',
        'min_role', 'steps_required', 'allow_self_approval', 'is_active', 'preset_key', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'subject_type' => ApprovalSubject::class,
            'approver_capability' => Capability::class,
            'min_role' => FinanceRole::class,
            'threshold_cents' => 'integer',
            'steps_required' => 'integer',
            'allow_self_approval' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Whether an amount of $amountCents in $currency is covered by this policy. */
    public function covers(int $amountCents, ?string $currency): bool
    {
        if ($this->threshold_cents === null) {
            return true;
        }

        // Amounts in a different currency cannot be compared with the threshold, so they fail
        // closed: approval is required. Callers should pass the ledger's base-currency amount.
        if ($currency === null || $this->currency !== $currency) {
            return true;
        }

        return $amountCents > $this->threshold_cents;
    }

    /** Plain-language summary for the policies page. */
    public function summary(): string
    {
        $who = $this->min_role ? $this->min_role->label().' ('.$this->approver_capability->label().')' : $this->approver_capability->label();
        $when = $this->threshold_cents === null
            ? 'every '.$this->subject_type->label()
            : $this->subject_type->plural().' over '.Money::format($this->threshold_cents, $this->currency);
        $steps = $this->steps_required > 1 ? "{$this->steps_required} different people approve" : 'one person approves';

        return ucfirst($when).': '.$steps.', approver needs: '.$who.'.';
    }

    public function auditLabel(): string
    {
        return mb_substr($this->name, 0, 120);
    }
}
