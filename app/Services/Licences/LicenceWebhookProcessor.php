<?php

namespace App\Services\Licences;

use App\Events\Licences\LicencePaymentRecorded;
use App\Events\Licences\LicenceRefundRecorded;
use App\Models\Licences\LicenceBatch;
use App\Models\Licences\LicencePayment;
use App\Models\Licences\PaystackPlan;
use App\Services\Paystack\PaystackException;
use App\Services\Paystack\PaystackGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies one verified-signature Paystack event to the licence books and queues the confirmation to InfoDot.
 *
 * Rules that hold for every event:
 *  - Value is only ever granted from a payment that this server re-verified with Paystack's verify call (status success, our
 *    currency, the batch's exact amount). The webhook body alone is never trusted for money.
 *  - One payment is one row in licence_payments, unique by Paystack reference, so a renewal reported by both invoice.update and
 *    charge.success (or by a redelivery) is granted once. The check happens before and again under a lock on the batch.
 *  - Money that cannot be tied to exactly one batch is recorded as `unmatched` and flagged, never guessed onto a batch.
 *  - A payment that is not for one of our plans or batches (this Paystack account may take other payments) is ignored.
 *
 * What the payloads look like is taken from Paystack's public documentation as recalled; fields that could not be checked are read
 * defensively (several places to look, a missing one means "do nothing", never "grant").
 */
final class LicenceWebhookProcessor
{
    public const PROCESSED = 'processed';

    public const IGNORED = 'ignored';

    public function __construct(private readonly PaystackGateway $gateway, private readonly LicenceOutbox $outbox) {}

    /**
     * @param  array<string, mixed>  $data
     * @return string processed|ignored
     *
     * @throws PaystackException when verification could not be done: the delivery must be retried (never swallowed)
     */
    public function handle(string $event, array $data): string
    {
        return match ($event) {
            'charge.success' => $this->settle((string) ($data['reference'] ?? ''), $data),
            'invoice.update' => $this->invoiceUpdate($data),
            'subscription.create' => $this->subscriptionCreated($data),
            'invoice.payment_failed' => $this->paymentFailed($data),
            'subscription.disable', 'subscription.not_renew' => $this->cancelled($data),
            'refund.processed' => $this->refundProcessed($data),
            'refund.failed' => $this->refundFailed($data),
            default => self::IGNORED,
        };
    }

    // ---- payments ----------------------------------------------------------------------------

    /** @param  array<string, mixed>  $data */
    private function invoiceUpdate(array $data): string
    {
        // Only a paid invoice carries value, and only through its transaction reference, which settle() dedupes with charge.success.
        $paid = ($data['paid'] ?? false) === true || ($data['status'] ?? '') === 'success';
        $reference = (string) ($data['transaction']['reference'] ?? $data['transaction_reference'] ?? '');

        if (! $paid || $reference === '') {
            return self::IGNORED;
        }

        return $this->settle($reference, $data);
    }

    /** @param  array<string, mixed>  $hint */
    private function settle(string $reference, array $hint): string
    {
        if ($reference === '' || LicencePayment::query()->where('paystack_reference', $reference)->exists()) {
            return self::IGNORED; // nothing to settle, or this payment was already granted
        }

        $tx = $this->gateway->verifyTransaction($reference); // PaystackException propagates: the delivery is retried

        if ($tx['status'] !== 'success') {
            // The webhook says paid and Paystack's own answer does not (yet). Never grant; have the delivery retried.
            throw new PaystackException('Transaction is not successful at Paystack (status '.$tx['status'].').', null, true);
        }

        if ($tx['currency'] !== config('licences.currency', 'ZAR') || $tx['amount_minor'] < 1) {
            return self::IGNORED;
        }

        $paidAt = $this->time($tx['paid_at']) ?? CarbonImmutable::now();
        $ownBatch = $this->batchOfCheckout($reference, $tx['metadata']);
        $ambiguous = false;
        $batch = $ownBatch;

        if ($batch === null) {
            [$batch, $ambiguous] = $this->renewalBatch($tx, $hint, $paidAt);
        }

        if ($batch === null && ! $ambiguous && ! $this->isOurPlan($tx['plan_code'], $hint)) {
            return self::IGNORED; // not a licence payment at all
        }

        return DB::transaction(function () use ($reference, $tx, $paidAt, $batch, $ambiguous, $ownBatch) {
            $locked = $batch === null ? null : LicenceBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if (LicencePayment::query()->where('paystack_reference', $reference)->exists()) {
                return self::IGNORED; // the other event for this payment won the race
            }

            if ($locked === null || $tx['amount_minor'] !== $locked->total_minor || in_array($locked->status, [LicenceBatch::REFUNDED], true)) {
                $payment = LicencePayment::query()->create([
                    'licence_batch_id' => $locked?->id, 'paystack_reference' => $reference, 'kind' => $locked !== null && $locked->first_paid_at === null ? 'initial' : 'renewal',
                    'amount_minor' => $tx['amount_minor'], 'currency' => $tx['currency'], 'paid_at' => $paidAt, 'status' => 'unmatched',
                ]);
                $locked?->forceFill(['needs_review' => true])->save();
                Log::warning('Licence payment recorded but not applied.', ['reference' => $reference, 'reason' => $ambiguous ? 'more than one batch fits' : ($locked === null ? 'no batch' : 'amount or state does not fit')]);

                return self::PROCESSED;
            }

            $initial = $ownBatch !== null && $locked->first_paid_at === null;
            $previousEnd = $locked->period_end;
            $start = $initial || $previousEnd === null || $previousEnd->lt($paidAt->subDays(3)) ? $paidAt : CarbonImmutable::instance($previousEnd);
            $end = LicencePeriods::end($start, $locked->interval);

            $payment = LicencePayment::query()->create([
                'licence_batch_id' => $locked->id, 'paystack_reference' => $reference, 'kind' => $initial ? 'initial' : 'renewal',
                'amount_minor' => $tx['amount_minor'], 'currency' => $tx['currency'], 'paid_at' => $paidAt, 'period_start' => $start, 'period_end' => $end, 'status' => 'paid',
            ]);

            $locked->forceFill(array_filter([
                'status' => LicenceBatch::ACTIVE, 'period_start' => $start, 'period_end' => $end,
                'first_paid_at' => $locked->first_paid_at ?? $paidAt,
                'paystack_customer_code' => $locked->paystack_customer_code ?? $tx['customer_code'],
                'authorization_code' => $locked->authorization_code ?? $tx['authorization_code'],
            ], fn ($v) => $v !== null))->save();

            $this->outbox->emit($locked, $initial ? 'paid' : 'renewed', 'lic:'.$locked->batch_uuid.':'.($initial ? 'paid' : 'renewed').':'.$reference, [
                'amount_minor' => $tx['amount_minor'], 'period_start' => $start, 'period_end' => $end, 'reference' => $reference,
            ]);
            DB::afterCommit(fn () => event(new LicencePaymentRecorded($payment)));

            return self::PROCESSED;
        });
    }

    /** @param  array<string, mixed>  $metadata */
    private function batchOfCheckout(string $reference, array $metadata): ?LicenceBatch
    {
        $batch = LicenceBatch::query()->where('paystack_reference', $reference)->first();

        if ($batch === null && isset($metadata['batch_uuid']) && is_string($metadata['batch_uuid'])) {
            $batch = LicenceBatch::query()->where('batch_uuid', strtolower($metadata['batch_uuid']))->first();
        }

        return $batch;
    }

    /**
     * A renewal carries no reference of ours. Tie it to a batch by subscription code if the payload has one, else by the
     * customer and plan, else (several fit) by the batch whose period ends nearest the payment. Never a guess between near ties.
     *
     * @param  array<string, mixed>  $tx
     * @param  array<string, mixed>  $hint
     * @return array{0: ?LicenceBatch, 1: bool} the batch, and whether more than one fit
     */
    private function renewalBatch(array $tx, array $hint, CarbonImmutable $paidAt): array
    {
        $subscription = $this->subscriptionCode($hint);

        if ($subscription !== null) {
            return [LicenceBatch::query()->where('paystack_subscription_code', $subscription)->first(), false];
        }

        $plan = $tx['plan_code'] ?? ($hint['plan']['plan_code'] ?? null);
        $customer = $tx['customer_code'] ?? ($hint['customer']['customer_code'] ?? null);

        if (! is_string($plan) || ! is_string($customer)) {
            return [null, false];
        }

        return $this->pickByCustomerAndPlan($customer, $plan, $paidAt, [LicenceBatch::ACTIVE, LicenceBatch::PAST_DUE]);
    }

    /**
     * @param  list<string>  $statuses
     * @return array{0: ?LicenceBatch, 1: bool}
     */
    private function pickByCustomerAndPlan(string $customer, string $plan, CarbonImmutable $at, array $statuses): array
    {
        $candidates = LicenceBatch::query()->where('paystack_customer_code', $customer)->where('paystack_plan_code', $plan)
            ->whereIn('status', $statuses)->whereNotNull('first_paid_at')->get();

        if ($candidates->count() <= 1) {
            return [$candidates->first(), false];
        }

        $ranked = $candidates->sortBy(fn (LicenceBatch $b) => abs(($b->period_end ?? $at)->getTimestamp() - $at->getTimestamp()))->values();
        $gap = abs(($ranked[1]->period_end ?? $at)->getTimestamp() - $at->getTimestamp()) - abs(($ranked[0]->period_end ?? $at)->getTimestamp() - $at->getTimestamp());

        if ($gap > 86400) {
            return [$ranked[0], false];
        }

        $candidates->each(fn (LicenceBatch $b) => $b->forceFill(['needs_review' => true])->save());

        return [null, true];
    }

    /** @param  array<string, mixed>  $hint */
    private function isOurPlan(?string $planCode, array $hint): bool
    {
        $code = $planCode ?? ($hint['plan']['plan_code'] ?? null);

        return is_string($code) && PaystackPlan::query()->where('plan_code', $code)->exists();
    }

    // ---- subscription lifecycle --------------------------------------------------------------

    /** @param  array<string, mixed>  $data */
    private function subscriptionCreated(array $data): string
    {
        $code = (string) ($data['subscription_code'] ?? '');
        $plan = (string) ($data['plan']['plan_code'] ?? '');
        $customer = (string) ($data['customer']['customer_code'] ?? '');
        $email = strtolower((string) ($data['customer']['email'] ?? ''));

        if ($code === '' || $plan === '') {
            return self::IGNORED;
        }

        return DB::transaction(function () use ($code, $plan, $customer, $email, $data) {
            if (LicenceBatch::query()->where('paystack_subscription_code', $code)->exists()) {
                return self::IGNORED;
            }

            // subscription.create may arrive before or after the first charge.success (UNVERIFIED): match on what both orders know.
            $candidates = LicenceBatch::query()->where('paystack_plan_code', $plan)->whereNull('paystack_subscription_code')
                ->whereIn('status', [LicenceBatch::AWAITING_PAYMENT, LicenceBatch::ACTIVE])
                ->where(fn ($q) => $q->when($customer !== '', fn ($w) => $w->where('paystack_customer_code', $customer))->when($email !== '', fn ($w) => $w->orWhereRaw('lower(customer_email) = ?', [$email])))
                ->orderBy('id')->lockForUpdate()->get();

            if ($candidates->isEmpty()) {
                return self::IGNORED; // not a licence subscription
            }

            // Several same-priced batches for one buyer: the oldest unlinked one that has been paid is the one whose first charge made this subscription.
            $paid = $candidates->whereNotNull('first_paid_at');
            $pick = $candidates->count() === 1 ? $candidates->first() : ($paid->count() === 1 ? $paid->first() : null);

            if ($pick === null) {
                $candidates->each(fn (LicenceBatch $b) => $b->forceFill(['needs_review' => true])->save());
                Log::warning('A Paystack subscription fits several licence batches; not linked.', ['subscription' => $code, 'candidates' => $candidates->count()]);

                return self::PROCESSED;
            }

            $pick->forceFill(array_filter([
                'paystack_subscription_code' => $code, 'subscription_email_token' => isset($data['email_token']) ? (string) $data['email_token'] : null,
                'paystack_customer_code' => $pick->paystack_customer_code ?? ($customer !== '' ? $customer : null),
            ], fn ($v) => $v !== null))->save();

            return self::PROCESSED;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function paymentFailed(array $data): string
    {
        $batch = $this->batchOfLifecycleEvent($data);

        if ($batch === null || in_array($batch->status, [LicenceBatch::CANCELLED, LicenceBatch::REFUNDED], true)) {
            return self::IGNORED;
        }

        $key = (string) ($data['invoice_code'] ?? $data['id'] ?? $data['reference'] ?? sha1((string) json_encode($data)));

        return DB::transaction(function () use ($batch, $key) {
            $locked = LicenceBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['status' => LicenceBatch::PAST_DUE])->save();
            $this->outbox->emit($locked, 'payment_failed', 'lic:'.$locked->batch_uuid.':payment_failed:'.$key, ['amount_minor' => $locked->total_minor, 'reference' => $key]);

            return self::PROCESSED;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function cancelled(array $data): string
    {
        $code = (string) ($data['subscription_code'] ?? $data['subscription']['subscription_code'] ?? '');
        $batch = $code === '' ? null : LicenceBatch::query()->where('paystack_subscription_code', $code)->first();

        if ($batch === null || in_array($batch->status, [LicenceBatch::CANCELLED, LicenceBatch::REFUNDED], true)) {
            return self::IGNORED;
        }

        return DB::transaction(function () use ($batch) {
            $locked = LicenceBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['status' => LicenceBatch::CANCELLED, 'cancelled_at' => now()])->save();
            $this->outbox->emit($locked, 'cancelled', 'lic:'.$locked->batch_uuid.':cancelled', ['amount_minor' => null, 'reference' => (string) ($locked->paystack_subscription_code ?? $locked->paystack_reference)]);

            return self::PROCESSED;
        });
    }

    /** The batch an invoice or subscription event is about: by subscription code, else the one customer-and-plan fit. @param array<string, mixed> $data */
    private function batchOfLifecycleEvent(array $data): ?LicenceBatch
    {
        $code = $this->subscriptionCode($data);

        if ($code !== null) {
            return LicenceBatch::query()->where('paystack_subscription_code', $code)->first();
        }

        $customer = $data['customer']['customer_code'] ?? null;
        $plan = $data['plan']['plan_code'] ?? $data['subscription']['plan']['plan_code'] ?? null;

        if (! is_string($customer) || ! is_string($plan)) {
            return null;
        }

        return $this->pickByCustomerAndPlan($customer, $plan, CarbonImmutable::now(), [LicenceBatch::ACTIVE, LicenceBatch::PAST_DUE])[0];
    }

    /** @param  array<string, mixed>  $data */
    private function subscriptionCode(array $data): ?string
    {
        $code = $data['subscription']['subscription_code'] ?? $data['subscription_code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    // ---- refunds -----------------------------------------------------------------------------

    /** @param  array<string, mixed>  $data */
    private function refundProcessed(array $data): string
    {
        $reference = (string) ($data['transaction_reference'] ?? $data['transaction']['reference'] ?? '');
        $payment = $reference === '' ? null : LicencePayment::query()->where('paystack_reference', $reference)->first();

        if ($payment === null) {
            return self::IGNORED;
        }

        $refundId = (string) ($data['id'] ?? $data['refund_reference'] ?? 'refund-'.$reference);

        $disable = DB::transaction(function () use ($payment, $data, $refundId) {
            $lockedPayment = LicencePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedPayment->refund_id === $refundId) {
                return null;
            }

            // Paystack reports a refund amount in minor units; absent means the whole payment.
            $amount = isset($data['amount']) && is_numeric($data['amount']) && (int) $data['amount'] > 0 ? (int) $data['amount'] : $lockedPayment->amount_minor - $lockedPayment->refunded_minor;
            $amount = min($amount, $lockedPayment->amount_minor - $lockedPayment->refunded_minor);

            if ($amount < 1) {
                return null;
            }

            $total = $lockedPayment->refunded_minor + $amount;
            $lockedPayment->forceFill(['refunded_minor' => $total, 'refund_id' => $refundId, 'status' => $total >= $lockedPayment->amount_minor ? 'refunded' : 'partially_refunded'])->save();

            $batch = $lockedPayment->licence_batch_id === null ? null : LicenceBatch::query()->whereKey($lockedPayment->licence_batch_id)->lockForUpdate()->first();
            $full = $total >= $lockedPayment->amount_minor;

            if ($batch !== null) {
                if ($full) {
                    $batch->forceFill(['status' => LicenceBatch::REFUNDED])->save();
                }
                $this->outbox->emit($batch, 'refunded', 'lic:'.$batch->batch_uuid.':refunded:'.$refundId, ['amount_minor' => $amount, 'reference' => $lockedPayment->paystack_reference]);
            }

            DB::afterCommit(fn () => event(new LicenceRefundRecorded($lockedPayment->refresh(), $amount, $refundId)));

            return $full && $batch?->paystack_subscription_code !== null && $batch->subscription_email_token !== null ? $batch : null;
        });

        if ($disable !== null) {
            $this->stopRenewals($disable);
        }

        return self::PROCESSED;
    }

    /** A refunded batch must not be charged again. Best effort: a failure is logged and the batch is flagged. */
    private function stopRenewals(LicenceBatch $batch): void
    {
        try {
            $this->gateway->disableSubscription((string) $batch->paystack_subscription_code, (string) $batch->subscription_email_token);
        } catch (Throwable $e) {
            $batch->forceFill(['needs_review' => true])->save();
            Log::warning('Could not disable the Paystack subscription of a refunded licence batch.', ['batch_uuid' => $batch->batch_uuid, 'error' => class_basename($e)]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function refundFailed(array $data): string
    {
        Log::warning('Paystack reported a failed licence refund.', ['reference' => (string) ($data['transaction_reference'] ?? '')]);

        return self::IGNORED;
    }

    private function time(?string $value): ?CarbonImmutable
    {
        try {
            return $value === null || $value === '' ? null : CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
