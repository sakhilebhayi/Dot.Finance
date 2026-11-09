<?php

namespace App\Services\Intelligence\Handlers;

use App\Contracts\Intelligence\PayablesSource;
use App\Models\User;
use App\Services\Intelligence\Answer;
use App\Services\Intelligence\Basis;
use App\Services\Intelligence\Intent;
use App\Services\Intelligence\RecognisedIntent;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/** "What bills are coming up?" The next days of what the ledger already knows it must pay. */
final class BillsHandler extends Handler
{
    public function __construct(private readonly PayablesSource $source) {}

    public function intents(): array
    {
        return [Intent::UpcomingBills];
    }

    public function handle(RecognisedIntent $question, LedgerContext $context, ?User $user = null): Answer
    {
        $from = CarbonImmutable::now()->startOfDay();
        $days = max(7, $question->horizonDays);
        $to = $from->addDays($days);
        $upcoming = $this->source->upcoming($context, $from, $to);
        $link = $this->sources(['Recurring items', fx_route('recurring.index')]);

        if ($upcoming === null) {
            return Answer::noData(Intent::UpcomingBills, "I don't have that information: there are no bills or recurring payments recorded yet.", $link);
        }

        if ($upcoming === []) {
            return new Answer(Intent::UpcomingBills, Answer::ANSWERED, "Nothing recorded is due in the next {$days} days.", sources: $link, notes: ['Only bills and recurring payments already recorded are listed; a payment nobody has entered cannot appear.']);
        }

        $totals = [];
        foreach ($upcoming as $item) {
            $totals[$item['currency']] = ($totals[$item['currency']] ?? 0) + $item['amount_cents'];
        }
        ksort($totals);

        $rows = [];
        foreach (array_slice($upcoming, 0, 10) as $item) {
            $rows[] = ['cells' => [$item['due_on'], $item['label'], $this->money($item['amount_cents'], $item['currency'])], 'href' => $item['href']];
        }

        $facts = [];
        $parts = [];
        foreach ($totals as $currency => $total) {
            $facts[] = ['label' => "Due in the next {$days} days ({$currency})", 'value' => $this->money($total, $currency)];
            $parts[] = $this->money($total, $currency);
        }

        $first = $upcoming[0];

        return new Answer(
            Intent::UpcomingBills,
            Answer::ANSWERED,
            $this->plural(count($upcoming), 'payment is', 'payments are')." due in the next {$days} days, ".implode(' and ', $parts).' in all; the first is '.$first['label'].' on '.$first['due_on'].'.',
            $facts,
            ['Date', 'What', 'Amount'],
            $rows,
            $link,
            Basis::Calculated,
            ['Only bills and recurring payments already recorded are listed; a payment nobody has entered cannot appear.'],
        );
    }
}
