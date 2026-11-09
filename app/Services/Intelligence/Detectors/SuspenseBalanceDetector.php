<?php

namespace App\Services\Intelligence\Detectors;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\LedgerSetting;
use App\Services\Accounting\GlQuery;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Money parked in Suspense. Suspense is a holding place for an amount nobody has classified yet, and it should be nil. A
 * balance on it sits in the asset section of the balance sheet looking like something owned, which it is not, so it is flagged.
 */
final class SuspenseBalanceDetector implements Detector
{
    public function __construct(private readonly GlQuery $gl) {}

    public function kind(): string
    {
        return 'suspense_balance';
    }

    public function period(CarbonImmutable $asOf): string
    {
        return 'ongoing';
    }

    public function detect(LedgerContext $context, CarbonImmutable $asOf): array
    {
        $settings = LedgerSetting::query()->forContext($context)->first();
        $suspense = $settings?->isEnabled() ? GlAccount::query()->forContext($context)->where('system_key', 'suspense')->first() : null;
        if ($suspense === null) {
            return [];
        }

        $net = (int) $this->gl->ownedLines($context)->where('l.gl_account_id', $suspense->id)->selectRaw('COALESCE(SUM(l.debit_cents), 0) - COALESCE(SUM(l.credit_cents), 0) AS net')->value('net');
        if ($net === 0) {
            return [];
        }

        $currency = (string) $settings->base_currency;
        $href = fx_route('accounting.general-ledger', ['account' => $suspense->id]);

        return [new Finding(
            $this->kind(),
            'gl:'.$suspense->id,
            $this->period($asOf),
            'warning',
            'Suspense holds '.Money::format(abs($net), $currency).' that has not been classified',
            'Suspense is a parking place for money nobody has sorted yet, and it should be nil. While it holds a balance, the balance sheet carries an amount that belongs to some other account. Find the entries on it and move each to the account it belongs to.',
            [['label' => $suspense->label(), 'value' => Money::format($net, $currency), 'href' => $href]],
            $href,
        )];
    }
}
