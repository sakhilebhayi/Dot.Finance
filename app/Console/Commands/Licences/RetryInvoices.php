<?php

namespace App\Console\Commands\Licences;

use App\Models\Licences\LicenceCreditNote;
use App\Models\Licences\LicencePayment;
use App\Services\Licences\LicenceInvoicer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Makes the invoices and credit notes that failed or were waiting (for example before the seller was configured). Lists what is still undone. */
#[Signature('licences:invoices')]
#[Description('Retry licence invoices and credit notes that are missing or failed, and list what is still undone')]
class RetryInvoices extends Command
{
    public function handle(LicenceInvoicer $invoicer): int
    {
        $left = 0;

        LicencePayment::query()->whereNull('sales_document_id')->whereNotNull('licence_batch_id')->where('status', '<>', 'unmatched')->orderBy('id')->each(function (LicencePayment $p) use ($invoicer, &$left) {
            if ($invoicer->invoice($p) !== 'issued') {
                $left++;
                $this->warn($p->paystack_reference.': '.$p->fresh()->invoice_error);
            }
        });

        LicenceCreditNote::query()->whereNull('sales_document_id')->orderBy('id')->each(function (LicenceCreditNote $n) use ($invoicer, &$left) {
            if ($invoicer->creditNote(LicencePayment::query()->findOrFail($n->licence_payment_id), $n->amount_minor, $n->refund_id) !== 'issued') {
                $left++;
                $this->warn('credit note '.$n->refund_id.': '.$n->fresh()->error);
            }
        });

        $this->line($left === 0 ? 'Nothing undone.' : $left.' still undone.');

        return $left === 0 ? self::SUCCESS : self::FAILURE;
    }
}
