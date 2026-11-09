<?php

namespace App\Services\Purchases;

use App\Contracts\Intelligence\PayablesSource;
use App\Services\Intelligence\Sources\RecurringPayables;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * What the business will have to pay soon: supplier bills and expense claims by due date (what is still owed on each,
 * overdue ones first, dated at their real due date), followed by the recurring payments the ledger already knows about.
 * Null only when neither exists, so the assistant can say it has nothing to answer from.
 */
final class PurchasesPayables implements PayablesSource
{
    public function __construct(private readonly ApLedger $ledger, private readonly RecurringPayables $recurring) {}

    public function upcoming(LedgerContext $context, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $out = [];
        foreach ($this->ledger->openItems($context) as $item) {
            if (! in_array($item['type'], ['bill', 'expense_claim'], true) || $item['open'] <= 0 || $item['due_date'] === null || $item['due_date'] > $to->toDateString()) {
                continue;
            }

            $overdue = $item['due_date'] < $from->toDateString();
            $out[] = [
                'label' => $item['party'].' '.$item['number'].($overdue ? ' (overdue)' : ''),
                'due_on' => $item['due_date'],
                'amount_cents' => (int) $item['open'],
                'currency' => (string) $item['currency'],
                'kind' => $item['type'],
                'href' => fx_route($item['route'], [$item['id']]),
            ];
        }

        $recurring = $this->recurring->upcoming($context, $from, $to);
        foreach ($recurring ?? [] as $row) {
            $out[] = $row;
        }

        if ($out === []) {
            return $recurring === null ? ($this->ledger->openItems($context)->isEmpty() ? null : []) : [];
        }

        usort($out, fn ($a, $b) => [$a['due_on'], $b['amount_cents']] <=> [$b['due_on'], $a['amount_cents']]);

        return $out;
    }
}
