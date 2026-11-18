<?php

namespace App\Services\Licences;

use App\Models\Licences\LicenceBatch;
use App\Models\Licences\LicencePayment;
use App\Services\Paystack\PaystackGateway;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Starts a refund at Paystack for a batch's payment. The refund is only recorded (and InfoDot told, and the credit note made) when
 * Paystack's refund.processed webhook arrives, so there is one path for every refund. D10: a first purchase may be refunded within
 * 7 days; renewals and later are not refunded by this tool unless forced by a person (--force on the command).
 */
final class LicenceRefunds
{
    public function __construct(private readonly PaystackGateway $gateway) {}

    /** @throws RuntimeException when the refund is not allowed or cannot be started */
    public function request(LicenceBatch $batch, ?string $reference = null, ?int $amountMinor = null, bool $force = false): string
    {
        $payment = LicencePayment::query()->where('licence_batch_id', $batch->id)->whereIn('status', ['paid', 'partially_refunded'])
            ->when($reference, fn ($q) => $q->where('paystack_reference', $reference))->orderBy('id')->first()
            ?? throw new RuntimeException('There is no refundable payment for this batch.');

        $window = (int) config('licences.refund_window_days', 7);

        if (! $force && ($payment->kind !== 'initial' || $payment->paid_at->lt(now()->subDays($window)))) {
            throw new RuntimeException('Only a first purchase can be refunded, and only within '.$window.' days. Use --force after a person has decided.');
        }

        $amount = $amountMinor ?? ($payment->amount_minor - $payment->refunded_minor);

        if ($amount < 1 || $amount > $payment->amount_minor - $payment->refunded_minor) {
            throw new RuntimeException('That amount is more than what is left of the payment.');
        }

        $out = $this->gateway->refund($payment->paystack_reference, $amount, 'Licence refund');
        Log::info('Licence refund requested at Paystack.', ['batch_uuid' => $batch->batch_uuid, 'reference' => $payment->paystack_reference, 'amount_minor' => $amount]);

        return $out['status'];
    }
}
