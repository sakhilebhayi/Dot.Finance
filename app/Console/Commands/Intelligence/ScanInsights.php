<?php

namespace App\Console\Commands\Intelligence;

use App\Services\Intelligence\InsightEngine;
use App\Support\Ledger\LedgerContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:intel:scan {--ledger= : Only this ledger, e.g. company:12 or personal:5}')]
#[Description('Run the anomaly detectors over every ledger (or one) and refresh its findings')]
class ScanInsights extends Command
{
    public function handle(InsightEngine $engine): int
    {
        foreach ($this->contexts() as $context) {
            $result = $engine->scan($context);
            $this->line(sprintf('%s: %d new, %d refreshed, %d resolved', $context->key(), count($result['created']), $result['updated'], $result['resolved']));
        }

        return self::SUCCESS;
    }

    /** @return iterable<LedgerContext> */
    private function contexts(): iterable
    {
        $only = (string) $this->option('ledger');

        return LedgerContext::owningRowsIn('accounts', liveOnly: true)
            ->filter(fn (LedgerContext $c) => ! $c->isCompany() || $c->team()?->isCompany())
            ->when($only !== '', fn ($all) => $all->filter(fn (LedgerContext $c) => $c->key() === $only));
    }
}
