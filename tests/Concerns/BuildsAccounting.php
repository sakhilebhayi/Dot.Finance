<?php

namespace Tests\Concerns;

use App\Models\Accounting\LedgerSetting;
use App\Models\Team;
use App\Models\User;
use App\Services\Accounting\ChartTemplates;
use App\Services\Accounting\GlBackfill;
use App\Services\Accounting\GlSync;
use App\Support\Ledger\LedgerContext;

/** Turns accounting on for a ledger the way the setup wizard does: prepare, backfill, verify, enable. */
trait BuildsAccounting
{
    protected function personalContext(User $user): LedgerContext
    {
        return LedgerContext::personal($user);
    }

    /** Prepare and enable accounting for $context; throws if the backfill does not verify. */
    protected function enableAccounting(LedgerContext $context, string $base = 'ZAR', ?string $template = null, int $fiscalStart = 1): LedgerSetting
    {
        $template ??= $context->isCompany() ? ChartTemplates::COMPANY : ChartTemplates::PERSONAL;
        $backfill = app(GlBackfill::class);

        return LedgerContext::run($context, function () use ($backfill, $context, $base, $template, $fiscalStart) {
            $backfill->prepare($context, $base, $fiscalStart, $template);
            $run = $backfill->apply($context);
            $this->assertSame('verified', $run->status, 'backfill did not verify: '.json_encode($run->report['verify']['checks'] ?? []));
            $settings = $backfill->enable($context);
            app(GlSync::class)->flush();

            return $settings;
        });
    }

    protected function companyContext(Team $team): LedgerContext
    {
        return LedgerContext::company($team);
    }
}
