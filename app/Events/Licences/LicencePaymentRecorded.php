<?php

namespace App\Events\Licences;

use App\Models\Licences\LicencePayment;

/** A verified licence payment was recorded (first payment or renewal). Dispatched after the recording transaction commits. */
final class LicencePaymentRecorded
{
    public function __construct(public readonly LicencePayment $payment) {}
}
