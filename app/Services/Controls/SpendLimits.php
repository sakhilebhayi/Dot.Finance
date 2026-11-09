<?php

namespace App\Services\Controls;

use App\Enums\Capability;
use App\Enums\FinanceRole;
use App\Exceptions\Controls\ControlsViolation;
use App\Models\Controls\ApprovalDecision;
use App\Models\Controls\ApprovalRequest;
use App\Models\Controls\SpendLimit;
use App\Models\User;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How much an approver may approve.
 *
 * A limit is set for one person or for everyone who holds a role, in one currency, per approval
 * or in total per day / week / month. A person's own limits replace their role's: if the person
 * has any limit rows, only those apply; otherwise their role's rows do; with none there is no
 * limit (the default). Every applicable row for the request's currency must hold. If a person
 * has limits but none in the request's currency the amount cannot be compared, so the answer is
 * no (fail closed). Totals count the person's approved decisions on requests that are still
 * pending or approved, in the same currency, since the start of the day, ISO week or month.
 */
final class SpendLimits
{
    /** @return Collection<int, SpendLimit> the rows that govern $approver (own rows, else their role's) */
    public function governing(LedgerContext $context, User $approver, FinanceRole $role): Collection
    {
        $own = SpendLimit::query()->forContext($context)->where('member_user_id', $approver->id)->get();

        return $own->isNotEmpty() ? $own : SpendLimit::query()->forContext($context)->where('role', $role->value)->get();
    }

    /**
     * Null when $approver may approve $amountCents in $currency, otherwise why not.
     */
    public function violation(LedgerContext $context, User $approver, FinanceRole $role, int $amountCents, ?string $currency): ?string
    {
        $rows = $this->governing($context, $approver, $role);

        if ($rows->isEmpty() || $currency === null) {
            return null;
        }

        $applicable = $rows->where('currency', $currency);

        if ($applicable->isEmpty()) {
            return "You have spend limits in other currencies but none for {$currency}, so this amount cannot be compared with them.";
        }

        foreach ($applicable as $limit) {
            if ($limit->period === 'per_approval') {
                if ($amountCents > $limit->limit_cents) {
                    return 'This is above your limit of '.Money::format($limit->limit_cents, $currency).' per approval.';
                }

                continue;
            }

            $used = $this->used($context, $approver, $currency, $this->periodStart($limit->period));

            if ($used + $amountCents > $limit->limit_cents) {
                $left = max(0, $limit->limit_cents - $used);

                return 'This would take you over your '.strtolower(SpendLimit::PERIODS[$limit->period]).' limit of '.Money::format($limit->limit_cents, $currency).' ('.Money::format($left, $currency).' left).';
            }
        }

        return null;
    }

    /** Minor units this person has approved since $since (pending and approved requests only). */
    public function used(LedgerContext $context, User $approver, string $currency, Carbon $since): int
    {
        return (int) ApprovalDecision::query()->forContext($context)
            ->where('decided_by', $approver->id)->where('decision', 'approved')
            ->where('currency', $currency)->where('created_at', '>=', $since)
            ->whereIn('approval_request_id', ApprovalRequest::query()->forContext($context)
                ->whereIn('status', [ApprovalRequest::PENDING, ApprovalRequest::APPROVED])->select('id'))
            ->sum('amount_cents');
    }

    public function periodStart(string $period): Carbon
    {
        return match ($period) {
            'daily' => now()->startOfDay(),
            'weekly' => now()->startOfWeek(),
            'monthly' => now()->startOfMonth(),
            default => now()->startOfDay(),
        };
    }

    /**
     * Set (or change) a limit for a person ($member) or a role ($role): exactly one of them.
     *
     * @throws ControlsViolation
     */
    public function set(User $actor, LedgerContext $context, ?User $member, ?FinanceRole $role, string $currency, string $period, int $limitCents): SpendLimit
    {
        $by = $this->authorise($actor, $context);

        if (($member === null) === ($role === null)) {
            throw new ControlsViolation('invalid', 'A spend limit is for one person or for one role, not both and not neither.');
        }

        if (! array_key_exists($period, SpendLimit::PERIODS)) {
            throw new ControlsViolation('invalid', 'Unknown limit period.');
        }

        if ($limitCents < 1 || $limitCents > Money::MAX_CENTS || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new ControlsViolation('invalid', 'A limit needs a currency code and an amount above zero.');
        }

        if ($member !== null) {
            $target = LedgerAccess::for($member, $context) ?? throw new ControlsViolation('not_a_member', 'That person does not belong to this ledger.');

            if ($target->financeRole()->rank() > $by->financeRole()->rank()) {
                throw new ControlsViolation('role_too_low', 'You cannot set a limit for someone who outranks you.');
            }
        } elseif ($role->rank() > $by->financeRole()->rank()) {
            throw new ControlsViolation('role_too_low', 'You cannot set a limit for a role above your own.');
        }

        $match = ['member_user_id' => $member?->id, 'role' => $role?->value, 'currency' => $currency, 'period' => $period];
        $limit = SpendLimit::query()->forContext($context)->where($match)->first() ?? new SpendLimit($context->ownerAttributes() + $match);
        $limit->fill(['limit_cents' => $limitCents, 'created_by' => $actor->id])->save();

        return $limit;
    }

    /** @throws ControlsViolation */
    public function remove(User $actor, LedgerContext $context, SpendLimit $limit): void
    {
        $this->authorise($actor, $context);

        if (! $limit->ledgerContext()->equals($context)) {
            throw new ControlsViolation('no_access', 'That limit belongs to another ledger.');
        }

        $limit->delete();
    }

    private function authorise(User $actor, LedgerContext $context): LedgerAccess
    {
        $by = LedgerAccess::for($actor, $context) ?? throw new ControlsViolation('no_access', 'You do not belong to this ledger.');

        if (! $by->can(Capability::ManageApprovals)) {
            throw new ControlsViolation('missing_capability', 'Only an owner or finance manager can set spend limits.');
        }

        return $by;
    }
}
