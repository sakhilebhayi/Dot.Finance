<?php

namespace App\Console\Commands\Gl;

use App\Models\Accounting\LedgerSetting;
use App\Models\Team;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Collection;

/** Shared by the finance:gl:* commands: which ledgers to visit. */
trait Concerns
{
    /**
     * The ledgers a command should visit: the one named by --ledger (personal:ID or company:ID), or every
     * ledger that has accounting settings.
     *
     * @return Collection<int, LedgerContext>|null null when --ledger is malformed (an error was printed)
     */
    protected function ledgers(?string $key, bool $onlyEnabled = false): ?Collection
    {
        if ($key !== null && $key !== '') {
            if (preg_match('/^(personal|company):(\d+)$/', $key, $m) !== 1) {
                $this->error('--ledger must look like personal:12 or company:3.');

                return null;
            }

            if ($m[1] === 'company' && ! Team::find((int) $m[2])?->isCompany()) {
                $this->error('That team is not a company ledger.');

                return null;
            }

            return collect([$m[1] === 'company' ? LedgerContext::company((int) $m[2]) : LedgerContext::personal((int) $m[2])]);
        }

        return LedgerContext::owningRowsIn('ledger_settings')->filter(function (LedgerContext $c) use ($onlyEnabled) {
            if (! $onlyEnabled) {
                return true;
            }

            return LedgerContext::run($c, fn () => LedgerSetting::query()->forContext($c)->first()?->isEnabled() ?? false);
        })->values();
    }
}
