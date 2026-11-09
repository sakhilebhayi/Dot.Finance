<?php

namespace App\Services\Sales;

use App\Models\Accounting\JournalEntry;
use App\Models\Sales\SalesApplication;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesRefund;
use App\Models\Shared\Payment;
use App\Models\Shared\PaymentAllocation;
use App\Models\User;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Sales\Support\SalesViolation;
use App\Services\Shared\PaymentAllocator;
use App\Support\Ledger\LedgerContext;
use App\Support\MoneyMath;
use App\Support\Shared\Exceptions\AllocationRejected;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Settling receivables: apply a payment, or a credit note, to an invoice or debit note; take it back.
 *
 * Money is tracked twice, in the document currency and in the ledger's base currency, because receivables sit
 * in the books in base currency at the rate each document was booked at. Every source (a payment, a credit
 * note) and every target (an invoice) is a "pool" with an amount and a base value. An application takes the
 * same share of both pools; the last application out of a pool takes whatever is left, so nothing is lost to
 * rounding and a pool empties to exactly zero in both currencies. When the two base values differ (a payment
 * received at a different rate than the invoice was booked at) the difference is the realised exchange
 * difference, posted to the books in the same transaction.
 *
 * Lock order is always: the payment (or credit note) first, then the invoice. PaymentAllocator holds the
 * invariants for payments (never more than the payment holds, never more than the invoice is owed, same
 * ledger, same currency, same customer, right direction).
 */
final class ApplicationEngine
{
    public function __construct(private readonly PaymentAllocator $allocator, private readonly JournalPoster $poster) {}

    // ---- pools --------------------------------------------------------------------------------

    /** What an invoice or debit note still owes: [amount in its currency, value in base]. @return array{foreign: int, base: int} */
    public function invoicePool(SalesDocument $document): array
    {
        $context = $document->ledgerContext();
        $foreign = $document->allocatableTotalCents() - $this->allocator->allocatedTo($document);
        $applied = (int) SalesApplication::query()->forContext($context)->where('target_document_id', $document->id)->whereNull('released_at')->sum('target_base_cents');
        $base = (int) $document->base_total_cents - (int) $document->written_off_base_cents - $applied;

        return ['foreign' => $foreign, 'base' => $base];
    }

    /** What a received payment still holds that is not applied to anything or refunded. @return array{foreign: int, base: int} */
    public function paymentPool(Payment $payment): array
    {
        $context = $payment->ledgerContext();
        $used = (int) SalesApplication::query()->forContext($context)->where('payment_id', $payment->id)->whereNull('released_at')->sum('source_base_cents');
        $refunded = (int) SalesRefund::query()->forContext($context)->where('source_payment_id', $payment->id)->where('status', 'recorded')->sum('source_base_cents');
        $total = $payment->base_amount_cents !== null ? (int) $payment->base_amount_cents : (int) $payment->amount_cents;

        return ['foreign' => $payment->status === 'recorded' ? $payment->unallocatedCents() : 0, 'base' => $payment->status === 'recorded' ? $total - $used - $refunded : 0];
    }

    /** What a credit note still holds that is not applied to an invoice or refunded. @return array{foreign: int, base: int} */
    public function creditPool(SalesDocument $credit): array
    {
        $context = $credit->ledgerContext();
        $apps = SalesApplication::query()->forContext($context)->where('source_document_id', $credit->id)->whereNull('released_at');
        $refunds = SalesRefund::query()->forContext($context)->where('source_document_id', $credit->id)->where('status', 'recorded');

        if ($credit->isVoid() || ! $credit->isCredit() || ! $credit->isSealed()) {
            return ['foreign' => 0, 'base' => 0];
        }

        return [
            'foreign' => (int) $credit->total_cents - (int) (clone $apps)->sum('amount_cents') - (int) (clone $refunds)->sum('amount_cents'),
            'base' => (int) $credit->base_total_cents - (int) (clone $apps)->sum('source_base_cents') - (int) (clone $refunds)->sum('source_base_cents'),
        ];
    }

    /** The share of a pool an amount takes. The whole pool takes everything left, in base too. @param  array{foreign: int, base: int}  $pool */
    public function share(array $pool, int $amount): int
    {
        if ($amount <= 0 || $amount > $pool['foreign']) {
            throw new SalesViolation('That is more than is left.', 'over_allocation');
        }

        return $amount === $pool['foreign'] ? $pool['base'] : MoneyMath::mulDivRound($pool['base'], $amount, $pool['foreign']);
    }

    /** What is still owed on a document, in its own currency. */
    public function openBalance(SalesDocument $document): int
    {
        return $document->isReceivable() && $document->isSealed() && ! $document->isVoid() ? $this->invoicePool($document)['foreign'] : 0;
    }

    // ---- apply --------------------------------------------------------------------------------

    /**
     * Apply $amount of a received payment to an invoice or debit note. Call inside the caller's transaction (it
     * opens its own savepoint). Returns the application.
     */
    public function applyPayment(Payment $payment, SalesDocument $document, int $amount, ?User $by = null): SalesApplication
    {
        $context = $payment->ledgerContext();

        return LedgerContext::run($context, fn () => DB::transaction(function () use ($payment, $document, $amount, $by, $context) {
            $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $target = $this->lockedReceivable($context, $document->id);

            $this->assertSettleable($target);
            if ($lockedPayment->direction !== 'in') {
                throw new SalesViolation('Only money received can be applied to an invoice.', 'invalid');
            }

            $source = $this->share($this->paymentPool($lockedPayment), $amount);
            $cleared = $this->share($this->invoicePool($target), $amount);
            $diff = $source - $cleared;

            try {
                $allocation = $this->allocator->allocate($lockedPayment, $target, $amount, 0, $diff, $by);
            } catch (AllocationRejected $e) {
                throw new SalesViolation($e->getMessage(), 'allocation');
            }

            $on = $this->settledOn($lockedPayment->paid_on, $target->issue_date);
            $application = SalesApplication::query()->create($context->ownerAttributes() + [
                'kind' => 'payment', 'payment_id' => $lockedPayment->id, 'allocation_id' => $allocation->id, 'target_document_id' => $target->id,
                'amount_cents' => $amount, 'target_base_cents' => $cleared, 'source_base_cents' => $source, 'fx_diff_base_cents' => $diff,
                'applied_on' => $on->toDateString(), 'applied_by' => $by?->id,
            ]);

            $this->postDifference($application, $target, $diff, $on, 'Payment '.$lockedPayment->id.' applied to '.$target->displayRef());
            $this->refreshStatus($target);
            SalesTrail::event($target, 'payment_applied', $by?->id, ['payment_id' => $lockedPayment->id, 'amount_cents' => $amount]);

            return $application->refresh();
        }));
    }

    /** Apply $amount of an issued credit note to an invoice or debit note of the same customer and currency. */
    public function applyCredit(SalesDocument $credit, SalesDocument $invoice, int $amount, ?User $by = null): SalesApplication
    {
        $context = $credit->ledgerContext();

        return LedgerContext::run($context, fn () => DB::transaction(function () use ($credit, $invoice, $amount, $by, $context) {
            // always the credit note first, then the invoice
            $lockedCredit = SalesDocument::query()->whereKey($credit->id)->lockForUpdate()->firstOrFail();
            $target = $this->lockedReceivable($context, $invoice->id);

            if (! $lockedCredit->isCredit() || ! $lockedCredit->isSealed() || $lockedCredit->isVoid()) {
                throw new SalesViolation('Only an issued credit note can be applied.', 'invalid');
            }
            $this->assertSettleable($target);
            if ((int) $lockedCredit->party_id !== (int) $target->party_id) {
                throw new SalesViolation('A credit note can only be applied to an invoice of the same customer.', 'allocation');
            }
            if ($lockedCredit->currency !== $target->currency) {
                throw new SalesViolation('The credit note is in '.$lockedCredit->currency.' and the invoice in '.$target->currency.'. A credit is applied within one currency.', 'allocation');
            }

            $source = $this->share($this->creditPool($lockedCredit), $amount);
            $cleared = $this->share($this->invoicePool($target), $amount);
            $diff = $source - $cleared;

            $on = $this->settledOn($lockedCredit->issue_date, $target->issue_date);
            $application = SalesApplication::query()->create($context->ownerAttributes() + [
                'kind' => 'credit', 'source_document_id' => $lockedCredit->id, 'target_document_id' => $target->id,
                'amount_cents' => $amount, 'target_base_cents' => $cleared, 'source_base_cents' => $source, 'fx_diff_base_cents' => $diff,
                'applied_on' => $on->toDateString(), 'applied_by' => $by?->id,
            ]);

            $this->postDifference($application, $target, $diff, $on, $lockedCredit->displayRef().' applied to '.$target->displayRef());
            $this->refreshStatus($target);
            SalesTrail::event($target, 'credit_applied', $by?->id, ['credit_note' => $lockedCredit->number, 'amount_cents' => $amount]);
            SalesTrail::event($lockedCredit, 'credit_used', $by?->id, ['invoice' => $target->number, 'amount_cents' => $amount]);

            return $application->refresh();
        }));
    }

    /** Take an application back: the invoice owes that amount again and the payment or credit holds it again. */
    public function release(SalesApplication $application, string $reason, ?User $by = null): SalesApplication
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new SalesViolation('Say why it is being taken back.', 'reason');
        }
        $context = $application->ledgerContext();

        return LedgerContext::run($context, fn () => DB::transaction(function () use ($application, $reason, $by, $context) {
            $fresh = SalesApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            if ($fresh->released_at !== null) {
                return $fresh;
            }

            if ($fresh->kind === 'payment') {
                Payment::query()->whereKey($fresh->payment_id)->lockForUpdate()->firstOrFail();
            }
            $target = $this->lockedReceivable($context, $fresh->target_document_id);

            if ($fresh->kind === 'payment') {
                $allocation = PaymentAllocation::query()->findOrFail($fresh->allocation_id);
                try {
                    $this->allocator->release($allocation, $reason, $by);
                } catch (AllocationRejected $e) {
                    throw new SalesViolation($e->getMessage(), 'allocation');
                }
            }

            $this->reverseDifference($fresh, 'Application released: '.$reason);
            $fresh->forceFill(['released_at' => now(), 'released_by' => $by?->id, 'release_reason' => mb_substr($reason, 0, 255)])->save();

            $this->refreshStatus($target);
            SalesTrail::event($target, 'application_released', $by?->id, ['amount_cents' => (int) $fresh->amount_cents, 'kind' => $fresh->kind]);

            return $fresh;
        }));
    }

    /**
     * Release every live application of a payment in one go (used when the payment is reversed). The allocations
     * themselves are released by PaymentAllocator::reverse, which the caller runs.
     */
    public function releaseAllOfPayment(Payment $payment, string $reason, ?User $by = null): void
    {
        $context = $payment->ledgerContext();
        $apps = SalesApplication::query()->forContext($context)->where('payment_id', $payment->id)->whereNull('released_at')->lockForUpdate()->get();

        foreach ($apps as $application) {
            $target = $this->lockedReceivable($context, $application->target_document_id);
            $this->reverseDifference($application, 'Payment reversed: '.$reason);
            $application->forceFill(['released_at' => now(), 'released_by' => $by?->id, 'release_reason' => mb_substr('payment reversed: '.$reason, 0, 255)])->save();
            $this->refreshStatus($target, afterPaymentReversal: true);
            SalesTrail::event($target, 'application_released', $by?->id, ['amount_cents' => (int) $application->amount_cents, 'kind' => 'payment', 'because' => 'payment reversed']);
        }
    }

    // ---- status -------------------------------------------------------------------------------

    /**
     * Bring a receivable's stored status in line with what has been applied to it. "Paid" only ever comes from
     * payments that add up to what is owed (or an authorised write-off); a fully credited invoice stays issued.
     */
    public function refreshStatus(SalesDocument $document, bool $afterPaymentReversal = false): SalesDocument
    {
        if (! $document->isReceivable() || ! $document->isSealed() || $document->isVoid()) {
            return $document;
        }

        $context = $document->ledgerContext();
        $fresh = SalesDocument::query()->forContext($context)->findOrFail($document->id);
        $open = $this->invoicePool($fresh)['foreign'];
        $paid = $this->allocator->allocatedTo($fresh);
        $status = match (true) {
            $open === 0 && ($paid > 0 || (int) $fresh->written_off_cents > 0) => 'paid',
            $paid > 0 => 'partially_paid',
            default => 'issued',
        };

        if ($fresh->status !== $status) {
            $fresh->forceFill(['status' => $status])->save();
            if ($status === 'paid') {
                SalesTrail::event($fresh, 'paid', null, []);
            }
        }

        $document->setRawAttributes($fresh->getAttributes(), true);

        return $document;
    }

    // ---- internals ----------------------------------------------------------------------------

    private function lockedReceivable(LedgerContext $context, int $id): SalesDocument
    {
        return SalesDocument::query()->forContext($context)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function assertSettleable(SalesDocument $document): void
    {
        if (! $document->isReceivable() || ! $document->isSealed() || $document->isVoid()) {
            throw new SalesViolation('Only an issued invoice or debit note that is not void can be settled.', 'invalid');
        }
    }

    private function settledOn(\DateTimeInterface $a, \DateTimeInterface $b): CarbonImmutable
    {
        $x = CarbonImmutable::parse($a->format('Y-m-d'));
        $y = CarbonImmutable::parse($b->format('Y-m-d'));

        return $x->gt($y) ? $x : $y;
    }

    /**
     * Post the realised exchange difference of an application: a gain when the payment or credit carried more base
     * value than the invoice was booked at (Dr receivables, Cr realised FX), a loss the other way. Nothing when equal.
     */
    private function postDifference(SalesApplication $application, SalesDocument $target, int $diff, CarbonImmutable $on, string $memo): void
    {
        if ($diff === 0) {
            return;
        }

        $party = (int) $target->party_id;
        $gain = $diff > 0;
        $ar = new PostingLine('ar', $gain ? $diff : 0, $gain ? 0 : -$diff, partyId: $party, description: 'Exchange difference: '.$memo);
        $fx = new PostingLine('fx_realised', $gain ? 0 : -$diff, $gain ? $diff : 0, description: 'Realised exchange '.($gain ? 'gain' : 'loss').': '.$memo);

        $entry = $this->poster->post(new PostingRequest($on, 'payment', 'app-'.$application->id, 1, 'Realised exchange difference: '.$memo, [$ar, $fx]));
        $application->forceFill(['fx_entry_id' => $entry->id])->save();
    }

    private function reverseDifference(SalesApplication $application, string $reason): void
    {
        if ($application->fx_entry_id === null) {
            return;
        }

        $entry = JournalEntry::query()->forContext($application->ledgerContext())->find($application->fx_entry_id);
        if ($entry !== null && $entry->status === 'posted') {
            $this->poster->reverse($entry, $reason);
        }
    }
}
