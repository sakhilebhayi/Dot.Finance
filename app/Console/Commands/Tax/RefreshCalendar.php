<?php

namespace App\Console\Commands\Tax;

use App\Models\Tax\TaxProfile;
use App\Services\Tax\DeadlineGenerator;
use App\Services\Tax\TaxReminder;
use App\Support\Ledger\LedgerContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Daily: regenerate each ledger's tax calendar from its profile and the rules (idempotent), then create the reminder
 * records that are due. Sends nothing; the Intelligence module delivers the reminder records.
 */
#[Signature('finance:tax:calendar {--ledger= : only this ledger, as personal:ID or company:ID}')]
#[Description('Generate the tax calendar and the reminder records for every ledger with a tax profile')]
class RefreshCalendar extends Command
{
    public function handle(DeadlineGenerator $generator, TaxReminder $reminders): int
    {
        $only = $this->option('ledger');
        $created = $updated = $notices = 0;

        foreach (TaxProfile::query()->withoutGlobalScopes()->get() as $profile) {
            $ctx = LedgerContext::fromOwner($profile->user_id, $profile->team_id);
            if ($only !== null && $only !== $ctx->key()) {
                continue;
            }

            LedgerContext::run($ctx, function () use ($generator, $reminders, $ctx, $profile, &$created, &$updated, &$notices) {
                $r = $generator->generate($ctx, $profile);
                $created += $r['created'];
                $updated += $r['updated'];
                $notices += $reminders->refresh($ctx)['created'];
            });
        }

        $this->info("Deadlines: {$created} created, {$updated} updated. Reminder records created: {$notices}.");

        return self::SUCCESS;
    }
}
