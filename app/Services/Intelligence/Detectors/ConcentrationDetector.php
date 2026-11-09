<?php

namespace App\Services\Intelligence\Detectors;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\LedgerSetting;
use App\Models\Shared\Party;
use App\Services\Accounting\GlQuery;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;

/** One customer accounts for at least half of the last twelve months' sales on receivables. Needs three months of sales. */
final class ConcentrationDetector implements Detector
{
    public function __construct(private readonly GlQuery $gl) {}

    public function kind(): string
    {
        return 'revenue_concentration';
    }

    public function period(CarbonImmutable $asOf): string
    {
        return $asOf->format('Y').'-Q'.$asOf->quarter;
    }

    public function detect(LedgerContext $context, CarbonImmutable $asOf): array
    {
        $settings = LedgerSetting::query()->forContext($context)->first();
        $ar = $settings?->isEnabled() ? GlAccount::query()->forContext($context)->where('system_key', 'ar')->first() : null;
        if ($ar === null) {
            return [];
        }

        $lines = $this->gl->ownedLines($context)
            ->where('l.gl_account_id', $ar->id)->where('l.debit_cents', '>', 0)
            ->where('l.entry_date', '>', $asOf->subYear()->toDateString())->where('l.entry_date', '<=', $asOf->toDateString())
            ->get(['l.party_id', 'l.debit_cents', 'l.entry_date']);

        $months = $lines->map(fn ($l) => substr((string) $l->entry_date, 0, 7))->unique()->count();
        $total = (int) $lines->sum('debit_cents');
        if ($months < (int) config('intelligence.detectors.concentration_min_months') || $total <= 0) {
            return [];
        }

        $byParty = $lines->whereNotNull('party_id')->groupBy('party_id')->map(fn ($g) => (int) $g->sum('debit_cents'))->sortDesc();
        $topId = $byParty->keys()->first();
        if ($topId === null) {
            return [];
        }

        $share = Money::divideRound($byParty[$topId] * 10000, $total);
        if ($share < (int) config('intelligence.detectors.concentration_share_bp')) {
            return [];
        }

        $party = Party::query()->forContext($context)->find($topId);
        if ($party === null) {
            return [];
        }

        $currency = (string) $settings->base_currency;
        $pct = Money::divideRound($share, 100);

        return [new Finding(
            $this->kind(),
            'party:'.$party->id,
            $this->period($asOf),
            $share >= 8000 ? 'warning' : 'notice',
            "{$party->name} is {$pct}% of your sales",
            "Over the last twelve months {$party->name} accounts for ".Money::format($byParty[$topId], $currency).' of '.Money::format($total, $currency)." in sales ({$pct}%). Losing one customer that size would hurt, which is worth knowing early.",
            [['label' => $party->name, 'value' => Money::format($byParty[$topId], $currency)], ['label' => 'All sales, twelve months', 'value' => Money::format($total, $currency)]],
            fx_route_exists('parties.show') ? route('parties.show', $party->id) : null,
        )];
    }
}
