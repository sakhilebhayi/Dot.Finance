<?php

namespace App\Services\Purchases;

use App\Models\Purchases\Bill;
use App\Models\Purchases\ExpenseClaim;
use App\Models\Shared\Payment;
use App\Models\Shared\PaymentAllocation;
use App\Support\Ledger\LedgerContext;

/**
 * Base-currency facts about what is left owing on a document or with a supplier. All of it is arithmetic on stored rows:
 * what the document was posted at, less the posted credit notes against it, less what each live allocation took off the payable.
 */
final class Settlement
{
    /** What an open bill or claim is still carried at in the books (base currency). */
    public function carryingBase(Bill|ExpenseClaim $doc): int
    {
        return LedgerContext::run($doc->ledgerContext(), function () use ($doc) {
            $posted = $doc instanceof Bill ? ($doc->base_total_cents ?? 0) : $doc->total_cents;
            $credits = $doc instanceof Bill && ! $doc->isCreditNote()
                ? (int) Bill::query()->where('credit_for_bill_id', $doc->id)->where('kind', 'credit_note')->whereIn('status', Bill::POSTED)->sum('base_total_cents')
                : 0;

            return max(0, $posted - $credits - $this->reliefOf($doc));
        });
    }

    /** The sum of what the live allocations to a document took off the payable. */
    public function reliefOf(Bill|ExpenseClaim $doc): int
    {
        return (int) PaymentAllocation::query()
            ->where('allocatable_type', $doc->getMorphClass())->where('allocatable_id', $doc->getKey())->whereNull('released_at')
            ->whereHas('payment', fn ($q) => $q->where('status', 'recorded'))
            ->selectRaw('COALESCE(SUM(COALESCE(base_relief_cents, amount_cents)), 0) AS total')->value('total');
    }

    /**
     * Money paid to a supplier that is not applied to anything (paid on account), in the payment's currency and in base currency.
     *
     * @return array{cents: int, base_cents: int}
     */
    public function unappliedOf(Payment $payment): array
    {
        $cents = $payment->unallocatedCents();
        if ($cents <= 0 || $payment->isReversed()) {
            return ['cents' => 0, 'base_cents' => 0];
        }

        $live = $payment->allocations()->whereNull('released_at')->get();
        $paidForDocuments = (int) $live->sum(fn (PaymentAllocation $a) => ($a->base_relief_cents ?? $a->amount_cents) - $a->fx_diff_cents);
        $totalBase = $payment->base_amount_cents ?? $payment->amount_cents;

        return ['cents' => $cents, 'base_cents' => max(0, $totalBase - $paidForDocuments)];
    }
}
