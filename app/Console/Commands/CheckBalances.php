<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\LedgerReconciler;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:check-balances {--user= : Only check this user id\'s personal ledger} {--team= : Only check this company team id\'s ledger}')]
#[Description('Recompute every account balance from the ledger (every personal ledger and every company ledger, each on its own) and report any drift or broken ledger invariant')]
class CheckBalances extends Command
{
    public function handle(LedgerReconciler $reconciler): int
    {
        if ($this->option('user') !== null && $this->option('team') !== null) {
            $this->error('Pick one: --user for a personal ledger or --team for a company ledger.');

            return self::FAILURE;
        }

        if ($this->option('team') !== null && ! Team::find((int) $this->option('team'))?->isCompany()) {
            $this->error('That team is not a company ledger (it must be a non-personal team linked to an InfoDot company).');

            return self::FAILURE;
        }

        $report = match (true) {
            $this->option('user') !== null => $reconciler->run(LedgerContext::personal((int) $this->option('user'))),
            $this->option('team') !== null => $reconciler->run(LedgerContext::company((int) $this->option('team'))),
            default => $reconciler->runAll(),
        };

        $this->table(
            ['Ledger', 'Account', 'Cur', 'Opening', 'Opening date', 'Txns counted', 'Derived (SQL)', 'Recomputed', 'Drift'],
            array_map(fn (array $a) => [
                $a['ledger'], $a['name'], $a['currency'],
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
