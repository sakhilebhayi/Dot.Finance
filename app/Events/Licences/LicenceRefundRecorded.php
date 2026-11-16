<?php

namespace App\Events\Licences;

use App\Models\Licences\LicencePayment;

/** Paystack finished a refund (refund.processed). Dispatched after the recording transaction commits. */
final class LicenceRefundRecorded
{
    public function __construct(public readonly LicencePayment $payment, public readonly int $amountMinor, public readonly string $refundId) {}
}
