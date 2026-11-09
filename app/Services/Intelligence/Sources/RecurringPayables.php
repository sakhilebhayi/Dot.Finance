<?php

namespace App\Services\Intelligence\Sources;

use App\Contracts\Intelligence\PayablesSource;
use App\Models\Account;
use App\Services\ForecastService;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/** Upcoming payments the ledger already knows about: its active recurring expenses, from the forecast engine. */
final class RecurringPayables implements PayablesSource
{
    public function __construct(private readonly ForecastService $forecast) {}

    public function upcoming(LedgerContext $context, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        if (! Account::query()->forContext($context)->exists()) {
            return null;
        }

        $days = max(1, (int) $from->diffInDays($to));
        $projection = $this->forecast->project($context, Carbon::parse($from->toDateString()), $days);

        $out = [];
        foreach ($projection['currencies'] as $currency => $entry) {
            foreach ($entry['events'] as $event) {
                if ($event['signed_cents'] >= 0) {
                    continue;
                }

                $out[] = [
                    'label' => (string) $event['description'],
                    'due_on' => (string) $event['date'],
                    'amount_cents' => -(int) $event['signed_cents'],
                    'currency' => (string) $currency,
                    'kind' => 'recurring',
                    'href' => fx_route('recurring.index'),
                ];
            }
        }

        usort($out, fn ($a, $b) => [$a['due_on'], $b['amount_cents']] <=> [$b['due_on'], $a['amount_cents']]);

        return $out === [] ? ($projection['included_rules'] > 0 ? [] : null) : $out;
    }
}
