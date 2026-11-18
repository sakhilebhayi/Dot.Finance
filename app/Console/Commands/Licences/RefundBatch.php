<?php

namespace App\Console\Commands\Licences;

use App\Models\Licences\LicenceBatch;
use App\Services\Licences\LicenceRefunds;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('licences:refund {batch_uuid} {--reference= : A specific payment reference} {--amount= : Minor units for a part refund} {--force : Ignore the 7-day first-purchase rule}')]
#[Description('Ask Paystack to refund a licence batch payment (the books and InfoDot follow when Paystack confirms)')]
class RefundBatch extends Command
{
    public function handle(LicenceRefunds $refunds): int
    {
        $batch = LicenceBatch::query()->where('batch_uuid', strtolower((string) $this->argument('batch_uuid')))->first();

        if ($batch === null) {
            $this->error('No such batch.');

            return self::FAILURE;
        }

        try {
            $status = $refunds->request($batch, $this->option('reference') ?: null, $this->option('amount') !== null ? (int) $this->option('amount') : null, (bool) $this->option('force'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Paystack accepted the refund request (status '.$status.'). Nothing changes here until Paystack confirms.');

        return self::SUCCESS;
    }
}
