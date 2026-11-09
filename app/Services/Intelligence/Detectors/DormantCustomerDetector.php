<?php

namespace App\Services\Intelligence\Detectors;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\LedgerSetting;
use App\Models\Shared\Party;
use App\Services\Accounting\GlQuery;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * A customer who used to buy regularly (at least three sales on receivables) and has not bought for 120 days.
 * Reads the receivables account of the general ledger, so it needs accounting on and sales posted to it.
 */
final class DormantCustomerDetector implements Detector
{
    public function __construct(private readonly GlQuery $gl) {}

    public function kind(): string
    {
        return 'dormant_customer';
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

        $days = (int) config('intelligence.detectors.dormant_days');
        $minSales = (int) config('intelligence.detectors.dormant_min_invoices');

        $rows = $this->gl->ownedLines($context)
            ->where('l.gl_account_id', $ar->id)->where('l.debit_cents', '>', 0)->whereNotNull('l.party_id')
            ->groupBy('l.party_id')
            ->selectRaw('l.party_id, COUNT(*) AS sales, MAX(l.entry_date) AS last_sale')
            ->get();

        $findings = [];
        foreach ($rows as $row) {
            $last = CarbonImmutable::parse(substr((string) $row->last_sale, 0, 10));
            if ((int) $row->sales < $minSales || $last->diffInDays($asOf, true) < $days) {
                continue;
            }

            $party = Party::query()->forContext($context)->find($row->party_id);
            if ($party === null || $party->status !== 'active') {
                continue;
            }

            $findings[] = new Finding(
                $this->kind(),
                'party:'.$party->id,
                $this->period($asOf),
                'info',
                "{$party->name} has not bought for ".(int) $last->diffInDays($asOf, true).' days',
                "{$party->name} had ".(int) $row->sales.' sales on record, the last on '.$last->toDateString().'. A customer going quiet is often worth a call.',
                [['label' => 'Sales on record', 'value' => (string) (int) $row->sales], ['label' => 'Last sale', 'value' => $last->toDateString()]],
                fx_route_exists('parties.show') ? route('parties.show', $party->id) : null,
            );
        }

        return $findings;
    }
}
