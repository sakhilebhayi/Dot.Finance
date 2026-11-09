<?php

namespace App\Services\Planning;

use App\Contracts\Intelligence\AffordabilitySource;
use App\Models\Accounting\LedgerSetting;
use App\Services\Intelligence\Sources\ForecastAffordability;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * "Can I afford it?" for the assistant, answered by the Planning cash projection (open invoices at the dates the customer
 * really pays, open bills at their due dates, recurring rules, tax and payroll from their modules) instead of recurring items
 * alone. The window is the days asked for; the lowest balance is the lowest the projection reaches inside it. A ledger without
 * accounting settings has no base currency to project in, so the recurring-items projection answers as before.
 */
final class PlanningAffordability implements AffordabilitySource
{
    public function __construct(private readonly CashProjection $projection, private readonly ForecastAffordability $fallback) {}

    public function projection(LedgerContext $context, int $days): ?array
    {
        $settings = LedgerSetting::query()->forContext($context)->first();
        if ($settings === null) {
            return $this->fallback->projection($context, $days);
        }

        $today = CarbonImmutable::today();
        $p = $this->projection->project($context, $settings, $today);
        $limit = $today->addDays($days)->toDateString();

        $lowest = ['date' => null, 'balance_cents' => $p['opening_cents']];
        foreach ($p['series'] as $point) {
            if ($point['date'] <= $limit && $point['balance_cents'] < $lowest['balance_cents']) {
                $lowest = $point;
            }
        }

        $commitments = 0;
        foreach ($p['events'] as $event) {
            if ($event['date'] <= $limit && $event['cents'] < 0) {
                $commitments -= $event['cents'];
            }
        }

        return [
            'currency' => (string) $p['currency'],
            'balance_cents' => (int) $p['opening_cents'],
            'lowest_cents' => (int) $lowest['balance_cents'],
            'lowest_date' => $lowest['date'] === $today->toDateString() ? null : $lowest['date'],
            'days' => $days,
            'commitments_cents' => $commitments,
            'basis_note' => 'The Planning cash projection: open invoices, open bills, recurring items'.($p['notes'] !== [] ? ' ('.strtolower(rtrim($p['notes'][0], '.')).')' : '').'. An estimate, not a guarantee.',
            'href' => fx_route('planning.cash.index'),
        ];
    }
}
