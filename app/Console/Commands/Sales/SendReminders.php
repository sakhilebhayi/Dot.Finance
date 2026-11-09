<?php

namespace App\Console\Commands\Sales;

use App\Services\Sales\Reminders;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendReminders extends Command
{
    protected $signature = 'sales:send-reminders {--date= : Run as if today were this date (Y-m-d)} {--ledger= : Only this ledger (personal:ID or company:ID)} {--dry-run : Count what is due, send and log nothing}';

    protected $description = 'Send payment reminders before, on and after the due date. Safe to run repeatedly: each reminder is sent once.';

    public function handle(Reminders $reminders): int
    {
        $today = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::today();

        if ($ledger = $this->option('ledger')) {
            [$kind, $id] = array_pad(explode(':', (string) $ledger, 2), 2, null);
            $context = $kind === 'company' ? LedgerContext::company((int) $id) : LedgerContext::personal((int) $id);
            $results = [$context->key() => $reminders->runFor($context, $today, (bool) $this->option('dry-run'))];
        } else {
            $results = $reminders->runAll($today);
        }

        foreach ($results as $key => $counts) {
            $this->line($key.': '.collect($counts)->map(fn ($n, $k) => "{$k}={$n}")->implode(' '));
        }
        $this->info(count($results).' ledger(s) with reminders switched on.');

        return self::SUCCESS;
    }
}
