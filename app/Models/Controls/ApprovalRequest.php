<?php

namespace App\Models\Controls;

use App\Contracts\Approvable;
use App\Enums\ApprovalSubject;
use App\Enums\Capability;
use App\Enums\FinanceRole;
use App\Models\Concerns\HasLedgerContext;
use App\Models\User;
use App\Support\Ledger\LedgerContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One ask for approval of one subject (a bill, a payment ...). The rules it was asked under
 * (capability, minimum role, steps, self-approval) are copied from the policy so a later edit of
 * the policy never changes a request in flight, and the subject's amount is snapshotted so a
 * change after the ask is caught at decision time. Only ApprovalService changes the status.
 */
class ApprovalRequest extends Model
{
    use HasLedgerContext;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'subject_kind' => ApprovalSubject::class,
            'approver_capability' => Capability::class,
            'min_role' => FinanceRole::class,
            'snapshot' => 'array',
            'amount_cents' => 'integer',
            'steps_required' => 'integer',
            'steps_done' => 'integer',
            'allow_self_approval' => 'boolean',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function ledgerReferences(): array
    {
        return ['approval_policy_id' => ApprovalPolicy::class];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ApprovalPolicy::class, 'approval_policy_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class)->orderBy('step');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** The subject, looked up inside this request's own ledger. Null when it no longer exists or is not an Approvable. */
    public function subject(): ?Approvable
    {
        $class = $this->approvable_type;

        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Approvable::class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        $found = LedgerContext::run($this->ledgerContext(), fn () => $class::query()->find($this->approvable_id));

        return $found instanceof Approvable ? $found : null;
    }

    public function auditLabel(): string
    {
        return mb_substr(($this->subject_kind?->label() ?? 'Approval').' '.($this->label ?? '#'.$this->approvable_id), 0, 200);
    }
}
