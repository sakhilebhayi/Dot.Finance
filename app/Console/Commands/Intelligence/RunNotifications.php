<?php

namespace App\Console\Commands\Intelligence;

use App\Services\Intelligence\NotificationRules;
use App\Support\Ledger\LedgerContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:notifications:run {--ledger= : Only this ledger, e.g. company:12 or personal:5}')]
#[Description('Send the scheduled notifications (approvals waiting, tax deadlines, overdue summary, new warnings); repeating it sends nothing twice')]
class RunNotifications extends Command
{
    public function handle(NotificationRules $rules): int
    {
        $only = (string) $this->option('ledger');

        $contexts = LedgerContext::owningRowsIn('accounts', liveOnly: true)
            ->filter(fn (LedgerContext $c) => ! $c->isCompany() || $c->team()?->isCompany())
            ->when($only !== '', fn ($all) => $all->filter(fn (LedgerContext $c) => $c->key() === $only));

        foreach ($contexts as $context) {
            $this->line($context->key().': '.json_encode($rules->run($context)));
        }

        return self::SUCCESS;
    }
}
