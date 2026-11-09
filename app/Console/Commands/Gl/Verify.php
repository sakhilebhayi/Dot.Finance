<?php

namespace App\Console\Commands\Gl;

use App\Services\Accounting\GlVerifier;
use App\Support\Ledger\LedgerContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:gl:verify {--ledger= : Only this ledger (personal:ID or company:ID)} {--json : Print the report as JSON}')]
#[Description('Recompute every promise the journals make: balanced entries, trial balance, cash tie-out, gap-free numbers, periods, isolation')]
class Verify extends Command
{
    use Concerns;

    public function handle(GlVerifier $verifier): int
    {
        $ledgers = $this->ledgers($this->option('ledger'));

        if ($ledgers === null) {
            return self::FAILURE;
        }

        $reports = $ledgers->map(fn (LedgerContext $c) => LedgerContext::run($c, fn () => $verifier->verify($c)))->all();

        if ($this->option('json')) {
            $this->line(json_encode($reports, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($reports as $report) {
                $this->line($report['ledger'].': '.($report['ok'] ? 'OK' : 'FAILED'));
                foreach ($report['checks'] as $check) {
                    $line = sprintf('  [%s] %s: %s', $check['ok'] ? 'ok' : 'FAIL', $check['code'], $check['message']);
                    $check['ok'] ? $this->line($line) : $this->error($line);
                    foreach ($check['details'] as $detail) {
                        $this->line('      '.$detail);
                    }
                }
            }
            if ($reports === []) {
                $this->line('No ledger has accounting settings; nothing to verify.');
            }
        }

        return collect($reports)->every(fn ($r) => $r['ok']) ? self::SUCCESS : self::FAILURE;
    }
}
