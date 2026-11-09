<?php

namespace App\Services\Shared;

use App\Contracts\Allocatable;
use App\Models\Shared\Payment;
use App\Models\Shared\PaymentAllocation;
use App\Models\User;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\AllocationRejected;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only way to apply a payment to something that is owed, and the home of the invariants:
 *
 *   1. the live allocations of a payment never add up to more than the payment;
 *   2. the live allocations to an invoice or bill never add up to more than it is worth
 *      (its total, which already includes credit notes);
 *   3. money in settles what customers owe, money out settles what we owe (direction matches);
 *   4. the payment and the thing it settles are in the same ledger, in the same currency,
 *      and for the same customer or supplier when both name one;
 *   5. a reversed payment settles nothing, and its allocations are released with it.
 *
 * It runs in a transaction, locks the payment first and the allocatable second (always in that order,
 * so two requests cannot deadlock), re-reads the sums under the lock, and writes nothing when any check
 * fails. It posts no journal: Sales and Purchases do that after a successful allocation.
 */
final class PaymentAllocator
{
    public function allocate(Payment $payment, Allocatable $target, int $amountCents, int $taxCents = 0, int $fxDiffCents = 0, ?User $by = null): PaymentAllocation
    {
        if ($amountCents <= 0) {
            throw new AllocationRejected('An allocation must be greater than zero.');
        }
        if ($taxCents < 0 || $taxCents > $amountCents) {
            throw new AllocationRejected('The tax part of an allocation is between zero and the allocated amount.');
        }

        return LedgerContext::run($payment->ledgerContext(), fn () => DB::transaction(function () use ($payment, $target, $amountCents, $taxCents, $fxDiffCents, $by) {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->first()
                ?? throw new AllocationRejected('That payment is not in this ledger.');

            $this->lockTarget($target);

            if ($locked->status !== 'recorded') {
                throw new AllocationRejected('A reversed payment cannot be allocated.');
            }
            if ($locked->ledgerOwner() !== $this->ownerOf($target)) {
                throw new AllocationRejected('A payment can only settle something in its own ledger.');
            }
            if ($locked->direction !== $target->allocatableDirection()) {
                throw new AllocationRejected($locked->direction === 'in'
                    ? 'Money received can only settle what customers owe (an invoice), not a bill.'
                    : 'Money paid out can only settle what we owe (a bill), not an invoice.');
            }
            if (strtoupper($locked->currency) !== strtoupper($target->allocatableCurrency())) {
                throw new AllocationRejected("The payment is in {$locked->currency} and it settles something in {$target->allocatableCurrency()}. Allocation across currencies is not supported.");
            }
            if ($locked->party_id !== null && $target->allocatablePartyId() !== null && (int) $locked->party_id !== (int) $target->allocatablePartyId()) {
                throw new AllocationRejected('The payment and the document are for different customers or suppliers.');
            }

            $free = $locked->unallocatedCents();
            if ($amountCents > $free) {
                throw new AllocationRejected('This allocates '.$amountCents.' cents but only '.$free.' cents of the payment are unallocated.');
            }

            $open = $this->openBalance($target, $locked);
            if ($amountCents > $open) {
                throw new AllocationRejected('This allocates '.$amountCents.' cents but only '.$open.' cents are still owed on the document.');
            }

            return PaymentAllocation::query()->create($locked->ledgerOwner() + [
                'payment_id' => $locked->id,
                'allocatable_type' => $target->getMorphClass(),
                'allocatable_id' => $target->getKey(),
                'amount_cents' => $amountCents,
                'tax_cents' => $taxCents,
                'fx_diff_cents' => $fxDiffCents,
                'allocated_by' => $by?->id,
            ]);
        }));
    }

    /** Take an allocation back (the payment was applied to the wrong document). The row stays, marked released. */
    public function release(PaymentAllocation $allocation, string $reason, ?User $by = null): PaymentAllocation
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new AllocationRejected('Say why the allocation is being released.');
        }

        return LedgerContext::run($allocation->ledgerContext(), fn () => DB::transaction(function () use ($allocation, $reason, $by) {
            Payment::query()->whereKey($allocation->payment_id)->lockForUpdate()->firstOrFail();

            $fresh = PaymentAllocation::query()->whereKey($allocation->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->released_at !== null) {
                throw new AllocationRejected('That allocation has already been released.');
            }

            $fresh->forceFill(['released_at' => now(), 'released_by' => $by?->id, 'release_reason' => mb_substr($reason, 0, 255)])->save();

            return $fresh;
        }));
    }

    /** Reverse a payment: it stops counting, and everything it settled is released with it. */
    public function reverse(Payment $payment, string $reason, ?User $by = null): Payment
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new AllocationRejected('Say why the payment is being reversed.');
        }

        return LedgerContext::run($payment->ledgerContext(), fn () => DB::transaction(function () use ($payment, $reason, $by) {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === 'reversed') {
                throw new AllocationRejected('That payment has already been reversed.');
            }

            $locked->allocations()->whereNull('released_at')->get()->each(
                fn (PaymentAllocation $a) => $a->forceFill(['released_at' => now(), 'released_by' => $by?->id, 'release_reason' => 'payment reversed'])->save()
            );

            $locked->forceFill([
                'status' => 'reversed',
                'reversed_at' => now(),
                'reversal_reason' => mb_substr($reason, 0, 255),
                'reversed_by' => $by?->id,
            ])->save();

            return $locked;
        }));
    }

    /** What is still owed on a document: its total minus the live allocations of recorded payments. */
    public function openBalance(Allocatable $target, ?Payment $ignoring = null): int
    {
        return $target->allocatableTotalCents() - $this->allocatedTo($target);
    }

    /** The sum of live allocations (recorded payments only) against a document. */
    public function allocatedTo(Allocatable $target): int
    {
        return (int) LedgerContext::run($this->contextOf($target), fn () => PaymentAllocation::query()
            ->where('allocatable_type', $target->getMorphClass())
            ->where('allocatable_id', $target->getKey())
            ->whereNull('released_at')
            ->whereHas('payment', fn ($q) => $q->where('status', 'recorded'))
            ->sum('amount_cents'));
    }

    /** Lock the document row when it is a model, so two allocations to it queue up. */
    private function lockTarget(Allocatable $target): void
    {
        if ($target instanceof Model) {
            $target->newQuery()->whereKey($target->getKey())->lockForUpdate()->first();
        }
    }

    /** @return array{user_id: ?int, team_id: ?int} */
    private function ownerOf(Allocatable $target): array
    {
        $owner = $target->ledgerOwner();

        return ['user_id' => $owner['user_id'] !== null ? (int) $owner['user_id'] : null, 'team_id' => $owner['team_id'] !== null ? (int) $owner['team_id'] : null];
    }

    private function contextOf(Allocatable $target): LedgerContext
    {
        $owner = $this->ownerOf($target);

        return LedgerContext::fromOwner($owner['user_id'], $owner['team_id']);
    }
}
