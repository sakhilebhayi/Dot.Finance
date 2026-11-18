<?php

namespace App\Services\Licences;

use App\Models\Licences\LicenceBatch;
use App\Services\Paystack\PaystackException;
use App\Services\Paystack\PaystackGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cancel one batch at period end (InfoDot -> Finance). The Paystack subscription is disabled first (so it never charges again; what was
 * paid stays valid to period_end, which InfoDot enforces), and only after that succeeds is the batch marked cancelled and the signed
 * `cancelled` confirmation queued. A gateway failure changes nothing, so the same call can simply be repeated.
 * Always company-scoped: the batch is found by batch_uuid AND the signed team_uuid; a batch of another company is "unknown".
 * Idempotent: the confirmation event id is the same one the Paystack subscription.disable webhook uses, so the two never double up.
 */
final class LicenceCancel
{
    public function __construct(private readonly PaystackGateway $gateway, private readonly LicenceOutbox $outbox) {}

    /**
     * @return array{outcome: string, batch: ?LicenceBatch} outcome: cancelled|replayed|unknown|not_ready
     *
     * @throws PaystackException when Paystack could not disable the subscription (nothing was changed; retry)
     */
    public function cancel(string $batchUuid, string $teamUuid): array
    {
        $find = fn () => LicenceBatch::query()->where('batch_uuid', $batchUuid)->where('team_uuid', $teamUuid)->first();

        if ($find() === null) {
            return ['outcome' => 'unknown', 'batch' => null];
        }

        return Cache::lock('licence-cancel:'.$batchUuid, 30)->block(10, function () use ($find) {
            $batch = $find();

            if ($batch->status === LicenceBatch::REFUNDED) {
                return ['outcome' => 'replayed', 'batch' => $batch];   // refund already stopped renewals
            }

            if ($batch->status === LicenceBatch::CANCELLED) {
                $this->confirm($batch);   // idempotent: a missing confirmation is made, an existing one is untouched

                return ['outcome' => 'replayed', 'batch' => $batch];
            }

            if ($batch->cancel_requested_at === null) {
                $batch->forceFill(['cancel_requested_at' => now()])->save();
            }

            $paid = $batch->paystack_subscription_code !== null;

            if ($paid) {
                if ($batch->subscription_email_token === null) {
                    return ['outcome' => 'not_ready', 'batch' => $batch];
                }
                $this->gateway->disableSubscription((string) $batch->paystack_subscription_code, (string) $batch->subscription_email_token);
            } elseif ($batch->first_paid_at !== null) {
                // Paid but the subscription has not been seen yet: it cannot be disabled, so do not claim it was.
                $batch->forceFill(['needs_review' => true])->save();

                return ['outcome' => 'not_ready', 'batch' => $batch];
            }

            DB::transaction(function () use ($batch) {
                $locked = LicenceBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== LicenceBatch::CANCELLED) {
                    $locked->forceFill(['status' => LicenceBatch::CANCELLED, 'cancelled_at' => now()])->save();
                }
                $this->confirm($locked);
            });

            return ['outcome' => 'cancelled', 'batch' => $batch->refresh()];
        });
    }

    private function confirm(LicenceBatch $batch): void
    {
        $this->outbox->emit($batch, 'cancelled', 'lic:'.$batch->batch_uuid.':cancelled', ['amount_minor' => null, 'reference' => (string) ($batch->paystack_subscription_code ?? $batch->paystack_reference)]);
    }
}
