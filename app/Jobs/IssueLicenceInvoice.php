<?php

namespace App\Jobs;

use App\Models\Licences\LicencePayment;
use App\Services\Licences\LicenceInvoicer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Makes the invoice (or, with a refund id, the credit note) for one licence payment, off the webhook path so a books problem never delays the confirmation to InfoDot. */
class IssueLicenceInvoice implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $paymentId, public ?int $refundAmount = null, public ?string $refundId = null) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(LicenceInvoicer $invoicer): void
    {
        $payment = LicencePayment::query()->find($this->paymentId);

        if ($payment === null) {
            return;
        }

        $invoicer->invoice($payment);

        if ($this->refundId !== null && $this->refundAmount !== null) {
            $invoicer->creditNote($payment, $this->refundAmount, $this->refundId);
        }
    }
}
