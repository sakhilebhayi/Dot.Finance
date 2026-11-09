<?php

namespace App\Services\Controls;

use App\Contracts\Approvable;
use App\Enums\ApprovalSubject;
use App\Enums\Capability;
use App\Events\ApprovalDecided;
use App\Exceptions\Controls\ControlsViolation;
use App\Models\Controls\ApprovalDecision;
use App\Models\Controls\ApprovalPolicy;
use App\Models\Controls\ApprovalRequest;
use App\Models\User;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The approvals engine: ask for approval of a subject, approve, reject, cancel.
 *
 * Rules, each with a test (tests/Feature/Controls):
 *
 *  - Off by default: with no policy rows nothing needs approval (requiresApproval() is false).
 *  - Separation of duties: nobody decides their own request, except the one person in a ledger
 *    that has a single member, and only when the policy allows it. With several members the
 *    rule applies again at once.
 *  - The approver must hold the policy's capability and role, and be within their spend limit.
 *  - The subject's amount is re-read at decision time and must equal the snapshot taken at the
 *    request; if it changed the approval is refused (cancel and ask again).
 *  - Several steps need different people; one person cannot provide two of them.
 *  - Idempotent: approving what is already approved changes nothing and fires nothing again; a
 *    stale copy of a request is never trusted (the row is locked and re-read), and the database
 *    allows only one decision per step.
 *  - Every state change is one transaction, audit-logged, and ends with the subject's
 *    onApprovalDecided() inside it and an ApprovalDecided event after the commit.
 *
 * Amounts handed to requiresApproval() should be in the ledger's base currency (the amount that
 * would be posted). An amount in a different currency than the policy's cannot be compared with
 * its threshold, so such an amount needs approval (fail closed).
 */
final class ApprovalService
{
    public function __construct(private readonly LedgerRoles $roles, private readonly SpendLimits $limits) {}

    // ---- the gate ----------------------------------------------------------------------------

    /** The policy that applies to this amount in the active (or given) ledger, or null when none does. The strictest wins. */
    public static function policyFor(string $subjectType, int $amountCents, ?string $currency, ?LedgerContext $context = null): ?ApprovalPolicy
    {
        $kind = ApprovalSubject::tryFrom($subjectType) ?? throw new ControlsViolation('invalid', "Unknown approval subject '{$subjectType}'.");
        $context ??= LedgerContext::current() ?? throw new ControlsViolation('invalid', 'No ledger is active, so it cannot be said whether approval is needed.');

        return ApprovalPolicy::query()->forContext($context)
            ->where('subject_type', $kind->value)->where('is_active', true)->get()
            ->filter(fn (ApprovalPolicy $policy) => $policy->covers($amountCents, $currency))
            ->sortBy([
                fn (ApprovalPolicy $a, ApprovalPolicy $b) => $b->steps_required <=> $a->steps_required,
                fn (ApprovalPolicy $a, ApprovalPolicy $b) => ($b->min_role?->rank() ?? 0) <=> ($a->min_role?->rank() ?? 0),
                fn (ApprovalPolicy $a, ApprovalPolicy $b) => $a->id <=> $b->id,
            ])->first();
    }

    /** The helper other modules call: does this amount need approval here? */
    public static function requiresApproval(string $subjectType, int $amountCents, ?string $currency, ?LedgerContext $context = null): bool
    {
        return self::policyFor($subjectType, $amountCents, $currency, $context) !== null;
    }

    /** The most recent request for a subject (any status), or null. */
    public function latestFor(Approvable $subject): ?ApprovalRequest
    {
        return ApprovalRequest::query()->forContext($subject->ledgerContext())
            ->where('approvable_type', $subject::class)->where('approvable_id', $subject->getKey())
            ->latest('id')->first();
    }

    /** Is the subject approved right now, for the amount it has right now? Modules call this before the guarded step. */
    public function isApproved(Approvable $subject): bool
    {
        $latest = $this->latestFor($subject);

        return $latest !== null
            && $latest->status === ApprovalRequest::APPROVED
            && $latest->amount_cents === $subject->approvalAmountCents()
            && $latest->currency === $subject->approvalCurrency();
    }

    // ---- request -----------------------------------------------------------------------------

    /**
     * Ask for approval of $subject. Returns the open request if there already is one for the same
     * amount (so a retry is harmless), replaces one whose amount has since changed, and refuses
     * when no policy applies.
     *
     * @throws ControlsViolation
     */
    public function request(User $requester, Approvable $subject, ?string $note = null): ApprovalRequest
    {
        if (! $subject instanceof Model || ! $subject->exists) {
            throw new ControlsViolation('invalid', 'Only a saved record can be sent for approval.');
        }

        $context = $subject->ledgerContext();
        LedgerAccess::for($requester, $context) ?? throw new ControlsViolation('no_access', 'You do not belong to this ledger.');

        $kind = ApprovalSubject::tryFrom($subject->approvalSubjectKind()) ?? throw new ControlsViolation('invalid', 'This record is not a kind that can be approved.');
        $amount = $subject->approvalAmountCents();
        $currency = $subject->approvalCurrency();
        $policy = self::policyFor($kind->value, $amount, $currency, $context)
            ?? throw new ControlsViolation('no_policy', 'No approval policy applies to this record, so it does not need approval.');

        try {
            return DB::transaction(function () use ($requester, $subject, $note, $context, $kind, $amount, $currency, $policy) {
                $open = ApprovalRequest::query()->forContext($context)
                    ->where('approvable_type', $subject::class)->where('approvable_id', $subject->getKey())
                    ->whereIn('status', [ApprovalRequest::PENDING, ApprovalRequest::APPROVED])
                    ->latest('id')->lockForUpdate()->first();

                if ($open !== null && $open->amount_cents === $amount && $open->currency === $currency) {
                    return $open;
                }

                if ($open?->isPending()) {
                    $open->forceFill(['status' => ApprovalRequest::CANCELLED, 'decided_at' => now(), 'reason' => 'Replaced: the amount changed after approval was asked for.'])->save();
                    $this->audit($context, 'superseded', $open, ['status' => ['before' => 'pending', 'after' => 'cancelled']], $requester->id);
                }

                $request = ApprovalRequest::create($context->ownerAttributes() + [
                    'approval_policy_id' => $policy->id,
                    'subject_kind' => $kind->value,
                    'approvable_type' => $subject::class,
                    'approvable_id' => $subject->getKey(),
                    'label' => mb_substr($subject->approvalLabel(), 0, 255),
                    'amount_cents' => $amount,
                    'currency' => $currency,
                    'snapshot' => ['kind' => $kind->value, 'amount_cents' => $amount, 'currency' => $currency, 'label' => $subject->approvalLabel()],
                    'requested_by' => $requester->id,
                    'requested_at' => now(),
                    'request_note' => $note !== null ? mb_substr($note, 0, 1000) : null,
                    'status' => ApprovalRequest::PENDING,
                    'approver_capability' => $policy->approver_capability->value,
                    'min_role' => $policy->min_role?->value,
                    'steps_required' => $policy->steps_required,
                    'steps_done' => 0,
                    'allow_self_approval' => $policy->allow_self_approval,
                ]);

                $this->audit($context, 'requested', $request, [
                    'status' => ['after' => 'pending'], 'amount_cents' => ['after' => $amount], 'steps_required' => ['after' => $policy->steps_required],
                ], $requester->id);

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            // Two requests for the same subject raced; the database allows one pending request. Return the winner.
            return ApprovalRequest::query()->forContext($context)->where('approvable_type', $subject::class)
                ->where('approvable_id', $subject->getKey())->where('status', ApprovalRequest::PENDING)->latest('id')->firstOrFail();
        }
    }

    // ---- decide ------------------------------------------------------------------------------

    /** @throws ControlsViolation */
    public function approve(User $approver, ApprovalRequest $request, ?string $reason = null): ApprovalRequest
    {
        return $this->decide($approver, $request, 'approved', $reason);
    }

    /** @throws ControlsViolation */
    public function reject(User $approver, ApprovalRequest $request, string $reason): ApprovalRequest
    {
        if (trim($reason) === '') {
            throw new ControlsViolation('invalid', 'Say why you are rejecting it, so the person who asked can act on it.');
        }

        return $this->decide($approver, $request, 'rejected', $reason);
    }

    /**
     * Withdraw a pending request: the person who asked, or anyone who manages approvals. Cancelling
     * what is already cancelled is a no-op; cancelling what was decided is refused.
     *
     * @throws ControlsViolation
     */
    public function cancel(User $actor, ApprovalRequest $request, ?string $reason = null): ApprovalRequest
    {
        $context = $request->ledgerContext();

        DB::transaction(function () use ($actor, $request, $reason, $context) {
            $locked = $this->lock($request, $context);
            $access = LedgerAccess::for($actor, $context) ?? throw new ControlsViolation('no_access', 'You do not belong to this ledger.');

            if ($locked->requested_by !== $actor->id && ! $access->can(Capability::ManageApprovals)) {
                throw new ControlsViolation('missing_capability', 'Only the person who asked, or someone who manages approvals, can cancel this.');
            }

            if ($locked->status === ApprovalRequest::CANCELLED) {
                return;
            }

            if (! $locked->isPending()) {
                throw new ControlsViolation('not_pending', 'This was already decided, so it cannot be cancelled.');
            }

            $this->finish($locked, $context, ApprovalRequest::CANCELLED, $actor->id, $reason);
        });

        $this->refresh($request, ApprovalRequest::query()->forContext($context)->find($request->getKey()));

        return $request;
    }

    /**
     * Cancel every pending request $requester asked for, with no one acting (a person left the
     * company). Called inside LedgerRoles::memberRemoved's transaction.
     */
    public function cancelPendingBy(LedgerContext $context, User $requester, string $reason): int
    {
        $count = 0;

        ApprovalRequest::query()->forContext($context)->where('requested_by', $requester->id)
            ->where('status', ApprovalRequest::PENDING)->lockForUpdate()->get()
            ->each(function (ApprovalRequest $request) use ($context, $reason, &$count) {
                $this->finish($request, $context, ApprovalRequest::CANCELLED, null, $reason);
                $count++;
            });

        return $count;
    }

    /**
     * Why $user could not decide $request right now (capability, role, separation of duties), or
     * null if they could. Does not look at the amount, steps already taken or spend limits.
     *
     * @return array{0: string, 1: string}|null [code, message]
     */
    public function eligibility(User $user, ApprovalRequest $request, ?LedgerAccess $access = null): ?array
    {
        $context = $request->ledgerContext();
        $access ??= LedgerAccess::for($user, $context);

        if ($access === null) {
            return ['no_access', 'You do not belong to this ledger.'];
        }

        if (! $access->can($request->approver_capability)) {
            return ['missing_capability', 'Your role cannot approve this: it needs "'.$request->approver_capability->label().'".'];
        }

        if ($request->min_role !== null && $access->financeRole()->rank() < $request->min_role->rank()) {
            return ['role_too_low', 'This needs a '.$request->min_role->label().' or above to approve.'];
        }

        if ($request->requested_by === $user->id && ! $this->mayApproveOwn($request, $context)) {
            return ['separation_of_duties', 'You asked for this, so someone else has to approve it.'];
        }

        return null;
    }

    /** @return Collection<int, array{request: ApprovalRequest, blocked: ?string}> pending requests $user could decide, with a note when something (a spend limit) would stop them */
    public function waitingFor(User $user, LedgerContext $context): Collection
    {
        $access = LedgerAccess::for($user, $context);

        if ($access === null) {
            return collect();
        }

        return ApprovalRequest::query()->forContext($context)->where('status', ApprovalRequest::PENDING)->with('requester:id,name')->oldest('id')->get()
            ->filter(fn (ApprovalRequest $r) => $this->eligibility($user, $r, $access) === null
                && ($this->mayApproveOwn($r, $context) || ! $r->decisions()->where('decided_by', $user->id)->exists()))
            ->map(fn (ApprovalRequest $r) => [
                'request' => $r,
                'blocked' => $this->limits->violation($context, $user, $access->financeRole(), $r->amount_cents, $r->currency),
            ])->values();
    }

    // ---- internals ---------------------------------------------------------------------------

    private function decide(User $approver, ApprovalRequest $request, string $decision, ?string $reason): ApprovalRequest
    {
        $context = $request->ledgerContext();
        $final = null;

        DB::transaction(function () use ($approver, $request, $decision, $reason, $context, &$final) {
            // The row is locked and re-read: a stale copy in the caller's hands is never trusted.
            $locked = $this->lock($request, $context);
            $access = LedgerAccess::for($approver, $context) ?? throw new ControlsViolation('no_access', 'You do not belong to this ledger.');

            if ($denial = $this->eligibility($approver, $locked, $access)) {
                throw new ControlsViolation(...$denial);
            }

            if (! $locked->isPending()) {
                // Already settled. The same outcome again is a no-op (two approvers racing both get "approved");
                // the opposite outcome, or a cancelled request, is an error.
                if (($locked->status === ApprovalRequest::APPROVED && $decision === 'approved') || ($locked->status === ApprovalRequest::REJECTED && $decision === 'rejected')) {
                    $final = $locked;

                    return;
                }

                throw new ControlsViolation($locked->status === ApprovalRequest::CANCELLED ? 'not_pending' : 'already_decided', 'This request is already '.$locked->status.'.');
            }

            $sole = $this->mayApproveOwn($locked, $context);

            if ($decision === 'approved' && ! $sole && $locked->decisions()->where('decided_by', $approver->id)->where('decision', 'approved')->exists()) {
                $final = $locked; // you already approved a step of this: asking again changes nothing

                return;
            }

            $subject = $locked->subject();

            if ($decision === 'approved') {
                if ($subject === null) {
                    throw new ControlsViolation('subject_gone', 'The record this approval was for no longer exists.');
                }

                if ($subject->approvalAmountCents() !== $locked->amount_cents || $subject->approvalCurrency() !== $locked->currency) {
                    throw new ControlsViolation('stale_amount', 'The amount changed after approval was asked for. Cancel this request and ask again with the new amount.');
                }

                if ($problem = $this->limits->violation($context, $approver, $access->financeRole(), $locked->amount_cents, $locked->currency)) {
                    throw new ControlsViolation('over_limit', $problem);
                }
            }

            $step = $locked->steps_done + 1;

            try {
                DB::transaction(fn () => ApprovalDecision::create($context->ownerAttributes() + [
                    'approval_request_id' => $locked->id, 'step' => $step, 'decided_by' => $approver->id, 'decision' => $decision,
                    'amount_cents' => $locked->amount_cents, 'currency' => $locked->currency,
                    'reason' => $reason !== null ? mb_substr($reason, 0, 1000) : null,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new ControlsViolation('already_decided', 'Someone else decided this step a moment ago. Open the request again.');
            }

            if ($decision === 'rejected') {
                $this->finish($locked, $context, ApprovalRequest::REJECTED, $approver->id, $reason);
                $final = $locked;

                return;
            }

            // A sole member cannot supply several different people, so their one approval completes every step.
            $done = $sole ? $locked->steps_required : $step;
            $locked->forceFill(['steps_done' => $done])->save();

            if ($done >= $locked->steps_required) {
                $this->finish($locked, $context, ApprovalRequest::APPROVED, $approver->id, $reason);
                $final = $locked;

                return;
            }

            $this->audit($context, 'step_approved', $locked, ['steps_done' => ['before' => $step - 1, 'after' => $step]], $approver->id);
            $final = $locked;
        });

        $this->refresh($request, $final);

        return $request;
    }

    /**
     * Move a locked, pending request to a final state: status, who and when, the subject's own hook
     * (inside this transaction), the audit row, and the event once the transaction commits.
     */
    private function finish(ApprovalRequest $request, LedgerContext $context, string $status, ?int $actorId, ?string $reason): void
    {
        $before = $request->status;

        $request->forceFill([
            'status' => $status,
            'decided_by' => $actorId,
            'decided_at' => now(),
            'reason' => $reason !== null ? mb_substr($reason, 0, 1000) : $request->reason,
        ])->save();

        $subject = $request->subject();
        $subject?->onApprovalDecided($request);

        $changes = ['status' => ['before' => $before, 'after' => $status]];
        if ($reason !== null && $reason !== '') {
            $changes['reason'] = ['changed' => true];
        }
        $this->audit($context, $status, $request, $changes, $actorId);

        DB::afterCommit(fn () => ApprovalDecided::dispatch($request->fresh() ?? $request, $status));
    }

    private function lock(ApprovalRequest $request, LedgerContext $context): ApprovalRequest
    {
        return ApprovalRequest::query()->forContext($context)->lockForUpdate()->find($request->getKey())
            ?? throw new ControlsViolation('no_access', 'That request belongs to another ledger.');
    }

    /** The caller's copy takes on the state the database now holds. */
    private function refresh(ApprovalRequest $into, ?ApprovalRequest $from): void
    {
        if ($from !== null) {
            $into->setRawAttributes($from->getAttributes(), true);
            $into->unsetRelation('decisions');
        }
    }

    /** One person may approve their own request only when they are the ledger's only member and the policy allows it. */
    private function mayApproveOwn(ApprovalRequest $request, LedgerContext $context): bool
    {
        return $request->allow_self_approval && $this->roles->memberCount($context) === 1;
    }

    /** @param  array<string, array<string, mixed>>  $changes */
    private function audit(LedgerContext $context, string $action, ApprovalRequest $request, array $changes, ?int $actorId): void
    {
        FinanceAudit::record($context->ownerAttributes(), $action, 'approval_request', (int) $request->getKey(), $request->auditLabel(), $changes, 'approval:'.$request->subject_kind->value, $request->currency, $actorId);
    }
}
