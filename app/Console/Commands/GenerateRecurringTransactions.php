<?php

namespace App\Console\Commands;

use App\Services\TransactionRecurrenceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:generate-recurring-transactions')]
#[Description('Generate any recurring transaction occurrences that are now due, across all users')]
class GenerateRecurringTransactions extends Command
{
    public function handle(TransactionRecurrenceService $service): int
    {
        $created = $service->generateDueOccurrences();

        $this->info($created === 1 ? '1 transaction generated.' : "{$created} transactions generated.");

        return self::SUCCESS;
    }
}
