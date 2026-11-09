<?php

namespace App\Services\Intelligence\Sources;

use App\Contracts\Intelligence\AffordabilitySource;
use App\Services\ForecastService;
use App\Support\Ledger\LedgerContext;

/** Projects recorded cash and the known recurring items. The currency is the one holding the most money. */
final class ForecastAffordability implements AffordabilitySource
{
    public function __construct(private readonly ForecastService $forecast) {}

    public function projection(LedgerContext $context, int $days): ?array
    {
        $projection = $this->forecast->project($context, null, $days);

        if ($projection['currencies'] === []) {
            return null;
        }

        $entries = $projection['currencies'];
        uasort($entries, fn ($a, $b) => abs($b['start_cents']) <=> abs($a['start_cents']));
        $code = array_key_first($entries);
        $entry = $entries[$code];

        return [
            'currency' => (string) $code,
            'balance_cents' => (int) $entry['start_cents'],
            'lowest_cents' => (int) $entry['lowest_cents'],
            'lowest_date' => $entry['lowest_date'],
            'days' => $days,
            'commitments_cents' => (int) $entry['expense_cents'],
            'basis_note' => 'Recorded balances and the recurring items already set up; nothing else is assumed (no new sales, no one-off bills).',
            'href' => fx_route('forecast.index'),
        ];
    }
}
