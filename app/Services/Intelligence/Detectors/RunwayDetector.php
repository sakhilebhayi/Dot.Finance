<?php

namespace App\Services\Intelligence\Detectors;

use App\Services\ReserveRunwayCalculator;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;

/** Liquid cash would last under three months at the current spending pace (under one month is critical). Uses the existing runway calculator. */
final class RunwayDetector implements Detector
{
    public function __construct(private readonly ReserveRunwayCalculator $runway) {}

    public function kind(): string
    {
        return 'cash_runway';
    }

    public function period(CarbonImmutable $asOf): string
    {
        return $asOf->format('Y-m');
    }

    public function detect(LedgerContext $context, CarbonImmutable $asOf): array
    {
        $findings = [];

        foreach ($this->runway->calculateAll($context) as $currency => $r) {
            if (! $r['available'] || $r['is_growing'] || $r['months'] === null || $r['months'] >= (int) config('intelligence.detectors.runway_warning_months')) {
                continue;
            }

            $months = (int) $r['months'];

            if (! empty($r['no_reserves'])) {
                $findings[] = new Finding(
                    $this->kind(),
                    'runway:'.$currency,
                    $this->period($asOf),
                    'critical',
                    "{$currency} accounts hold no cash while money is going out",
                    'The '.$currency.' checking, savings and cash accounts hold '.Money::format((int) $r['liquid_reserves_cents'], $currency).' and '.$currency.' spending averages '.Money::format((int) $r['avg_monthly_burn_cents'], $currency).' a month. '.$r['basis'],
                    [
                        ['label' => 'Liquid reserves', 'value' => Money::format((int) $r['liquid_reserves_cents'], $currency)],
                        ['label' => 'Average monthly net spend', 'value' => Money::format((int) $r['avg_monthly_burn_cents'], $currency)],
                    ],
                    fx_route('forecast.index'),
                );

                continue;
            }

            $findings[] = new Finding(
                $this->kind(),
                'runway:'.$currency,
                $this->period($asOf),
                $months < (int) config('intelligence.detectors.runway_critical_months') ? 'critical' : 'warning',
                "{$currency} cash would last about ".($months === 1 ? '1 month' : "{$months} months"),
                'At the current '.$currency.' spending pace of '.Money::format((int) $r['avg_monthly_burn_cents'], $currency).' a month, your liquid reserves of '.Money::format((int) $r['liquid_reserves_cents'], $currency).' would run out in about '.($months === 1 ? '1 month' : "{$months} months").'. '.$r['basis'],
                [
                    ['label' => 'Liquid reserves', 'value' => Money::format((int) $r['liquid_reserves_cents'], $currency)],
                    ['label' => 'Average monthly net spend', 'value' => Money::format((int) $r['avg_monthly_burn_cents'], $currency)],
                ],
                fx_route('forecast.index'),
            );
        }

        return $findings;
    }
}
