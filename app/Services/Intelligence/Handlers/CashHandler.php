<?php

namespace App\Services\Intelligence\Handlers;

use App\Models\Account;
use App\Models\User;
use App\Services\ForecastService;
use App\Services\Intelligence\Answer;
use App\Services\Intelligence\Basis;
use App\Services\Intelligence\Intent;
use App\Services\Intelligence\RecognisedIntent;
use App\Services\LedgerSummary;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Carbon;

/** "What is my cash position?" (recorded balances) and "What will my cash be in 60 days?" (a projection). */
final class CashHandler extends Handler
{
    public function __construct(private readonly LedgerSummary $summary, private readonly ForecastService $forecast) {}

    public function intents(): array
    {
        return [Intent::CashPosition, Intent::ProjectedCash];
    }

    public function handle(RecognisedIntent $question, LedgerContext $context, ?User $user = null): Answer
    {
        return $question->intent === Intent::CashPosition ? $this->position($context) : $this->projected($question, $context);
    }

    private function position(LedgerContext $context): Answer
    {
        $accounts = $this->summary->accounts($context);
        $link = $this->sources(['Accounts', fx_route('accounts.index')]);

        if ($accounts->isEmpty()) {
            return Answer::noData(Intent::CashPosition, "I don't have that information: no accounts have been added yet.", $link);
        }

        $totals = [];
        foreach ($accounts as $account) {
            $totals[$account->currency] = ($totals[$account->currency] ?? 0) + $account->balanceCents();
        }
        ksort($totals);

        $facts = [];
        $parts = [];
        foreach ($totals as $currency => $total) {
            $facts[] = ['label' => "Total balance ({$currency})", 'value' => $this->money($total, $currency)];
            $parts[] = $this->money($total, $currency);
        }

        $rows = [];
        foreach ($accounts->sortByDesc(fn (Account $a) => abs($a->balanceCents()))->take(8) as $account) {
            $rows[] = ['cells' => [$account->name, $this->money($account->balanceCents(), $account->currency)], 'href' => fx_route('accounts.index')];
        }

        return new Answer(
            Intent::CashPosition,
            Answer::ANSWERED,
            'Across '.$this->plural($accounts->count(), 'account', 'accounts').', your recorded balance is '.implode(' and ', $parts).'.',
            $facts,
            ['Account', 'Balance'],
            $rows,
            $link,
            notes: ['Balances are worked out from the opening balance and every entry recorded. They are not read from a bank, so an unrecorded transaction is not in them.'],
        );
    }

    private function projected(RecognisedIntent $q, LedgerContext $context): Answer
    {
        $days = $q->horizonDays;
        $projection = $this->forecast->project($context, Carbon::now(), $days);
        $link = $this->sources(['Forecast', fx_route('forecast.index')]);

        if ($projection['currencies'] === []) {
            return Answer::noData(Intent::ProjectedCash, "I don't have that information: no accounts have been added yet, so there is nothing to project from.", $link);
        }

        if ($projection['included_rules'] === 0) {
            return Answer::noData(Intent::ProjectedCash, "I don't have that information: no recurring income or expenses are set up, so a projection would only repeat today's balance.", $link);
        }

        $facts = [];
        $parts = [];
        $rows = [];
        foreach ($projection['currencies'] as $currency => $entry) {
            $facts[] = ['label' => "Balance now ({$currency})", 'value' => $this->money($entry['start_cents'], $currency)];
            $facts[] = ['label' => "Expected in {$days} days ({$currency})", 'value' => $this->money($entry['end_cents'], $currency)];
            $facts[] = ['label' => "Lowest point ({$currency})", 'value' => $this->money($entry['lowest_cents'], $currency).' on '.$entry['lowest_date']];
            $parts[] = $this->money($entry['end_cents'], $currency);
            $rows[] = ['cells' => [$currency, $this->money($entry['start_cents'], $currency), $this->money($entry['end_cents'], $currency), $this->money($entry['lowest_cents'], $currency)], 'href' => fx_route('forecast.index')];
        }

        return new Answer(
            Intent::ProjectedCash,
            Answer::ANSWERED,
            'On the recurring items already set up, your cash would be about '.implode(' and ', $parts)." in {$days} days.",
            $facts,
            ['Currency', 'Now', "In {$days} days", 'Lowest'],
            $rows,
            $link,
            Basis::Estimate,
            [
                'This is a projection from recorded balances and '.$this->plural($projection['included_rules'], 'active recurring item', 'active recurring items').'. It assumes no other income or expenses, so it is not a promise.',
            ],
        );
    }
}
