<?php

namespace App\Console\Commands;

use App\Services\LedgerReconciler;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:check-balances {--user= : Only check this user id}')]
#[Description('Recompute every account balance from the ledger and report any drift or broken ledger invariant')]
class CheckBalances extends Command
{
    public function handle(LedgerReconciler $reconciler): int
    {
        $userId = $this->option('user') !== null ? (int) $this->option('user') : null;
        $report = $reconciler->run($userId);

        $this->table(
            ['User', 'Account', 'Cur', 'Opening', 'Opening date', 'Txns counted', 'Derived (SQL)', 'Recomputed', 'Drift'],
            array_map(fn (array $a) => [
                $a['user_id'], $a['name'], $a['currency'],
                Money::format($a['opening_cents']), $a['opening_date'] ?? 'none',
                $a['transactions_counted'],
                Money::format($a['derived_cents']), Money::format($a['recomputed_cents']), Money::format($a['drift_cents']),
            ], $report['accounts'])
        );

        foreach ($report['findings'] as $finding) {
            $line = strtoupper($finding['severity']).' ['.$finding['code'].'] '.$finding['message'];
            $finding['severity'] === 'error' ? $this->error($line) : $this->line($line);
        }

        $problems = LedgerReconciler::problems($report);

        if ($problems === []) {
            $this->info('No drift or broken invariants found in '.count($report['accounts']).' account(s).');

            return self::SUCCESS;
        }

        $this->error(count($problems).' problem(s) found.');

        return self::FAILURE;
    }
}
