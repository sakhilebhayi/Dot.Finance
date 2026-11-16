<?php

namespace App\Services\Licences;

use App\Models\Licences\LicenceBatch;
use App\Services\Paystack\PaystackException;
use App\Services\Paystack\PaystackGateway;
use App\Services\Paystack\PlanRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Creates (or replays) the hosted checkout for one batch. Idempotent on batch_uuid: the same signed request again gives the same
 * checkout_url; the same batch_uuid with different terms is a conflict and changes nothing.
 */
final class LicenceCheckout
{
    public function __construct(private readonly PaystackGateway $gateway, private readonly PlanRegistry $plans) {}

    /**
     * @param  array{batch_uuid: string, team_uuid: string, quantity: int, interval: string, total_minor: int, currency: string, customer: array{name: string, email: string}}  $in
     * @return array{outcome: string, batch: LicenceBatch} outcome: created|replayed|conflict
     *
     * @throws PaystackException when Paystack cannot start the checkout (the batch stays pending and a retry starts it)
     */
    public function start(array $in): array
    {
        $batch = LicenceBatch::query()->where('batch_uuid', $in['batch_uuid'])->first();

        if ($batch === null) {
            try {
                $batch = DB::transaction(fn () => LicenceBatch::query()->create([
                    'batch_uuid' => $in['batch_uuid'], 'team_uuid' => $in['team_uuid'], 'quantity' => $in['quantity'],
                    'interval' => PlanRegistry::interval($in['interval']), 'interval_raw' => $in['interval'],
                    'total_minor' => $in['total_minor'], 'currency' => $in['currency'],
                    'customer_name' => $in['customer']['name'], 'customer_email' => $in['customer']['email'], 'status' => LicenceBatch::PENDING,
                ]));
            } catch (UniqueConstraintViolationException) {
                $batch = LicenceBatch::query()->where('batch_uuid', $in['batch_uuid'])->firstOrFail();
            }
        }

        if (! $this->sameTerms($batch, $in)) {
            return ['outcome' => 'conflict', 'batch' => $batch];
        }

        if ($batch->checkout_url !== null || ! in_array($batch->status, [LicenceBatch::PENDING, LicenceBatch::AWAITING_PAYMENT], true)) {
            return ['outcome' => 'replayed', 'batch' => $batch];
        }

        // One initialiser at a time per batch: a double submit must not call Paystack twice.
        return Cache::lock('licence-checkout:'.$batch->batch_uuid, 30)->block(10, function () use ($batch) {
            $locked = LicenceBatch::query()->whereKey($batch->id)->firstOrFail();

            if ($locked->checkout_url !== null) {
                return ['outcome' => 'replayed', 'batch' => $locked];
            }

            $plan = $this->plans->forAmount($locked->total_minor, $locked->currency, $locked->interval);
            $attempt = $locked->init_attempts + 1;
            $reference = 'lic_'.str_replace('-', '', $locked->batch_uuid).($attempt > 1 ? '_'.$attempt : '');

            // Counted before the call: a reference that reached Paystack must never be reused.
            $locked->forceFill(['init_attempts' => $attempt, 'paystack_reference' => $reference, 'paystack_plan_code' => $plan->plan_code])->save();

            $init = $this->gateway->initializeTransaction(
                $locked->customer_email, $locked->total_minor, $locked->currency, $reference, $plan->plan_code,
                filled(config('licences.return_url')) ? (string) config('licences.return_url') : null,
                ['batch_uuid' => $locked->batch_uuid, 'team_uuid' => $locked->team_uuid, 'quantity' => $locked->quantity, 'interval' => $locked->interval],
            );

            $locked->forceFill(['checkout_url' => $init['authorization_url'], 'status' => LicenceBatch::AWAITING_PAYMENT])->save();

            return ['outcome' => 'created', 'batch' => $locked];
        });
    }

    /** @param  array<string, mixed>  $in */
    private function sameTerms(LicenceBatch $batch, array $in): bool
    {
        return $batch->team_uuid === $in['team_uuid'] && $batch->quantity === $in['quantity'] && $batch->interval === PlanRegistry::interval($in['interval'])
            && $batch->total_minor === $in['total_minor'] && $batch->currency === $in['currency'];
    }
}
