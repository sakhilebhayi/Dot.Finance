<?php

namespace App\Console\Commands\Sales;

use App\Services\Sales\RecurringInvoices;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RunRecurring extends Command
{
    protected $signature = 'sales:run-recurring {--date= : Run as if today were this date (Y-m-d)}';

    protected $description = 'Create the invoices that repeating templates are due to make today. One invoice per template and period, however often it runs.';

    public function handle(RecurringInvoices $recurring): int
    {
        $today = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::today();

        foreach ($recurring->runAll($today) as $key => $counts) {
            $this->line($key.': '.collect($counts)->map(fn ($n, $k) => "{$k}={$n}")->implode(' '));
        }

        return self::SUCCESS;
    }
}
