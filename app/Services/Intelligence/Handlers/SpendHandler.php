<?php

namespace App\Services\Intelligence\Handlers;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Intelligence\Answer;
use App\Services\Intelligence\Intent;
use App\Services\Intelligence\RecognisedIntent;
use App\Support\Ledger\LedgerContext;

/** "How much did I spend?" and "Which expenses increased the most?" Cash-ledger expenses, per currency, transfers excluded. */
final class SpendHandler extends Handler
{
    public function intents(): array
    {
        return [Intent::Spend, Intent::ExpensesIncreased];
    }

    public function handle(RecognisedIntent $question, LedgerContext $context, ?User $user = null): Answer
    {
        return $question->intent === Intent::Spend ? $this->spend($question, $context) : $this->increased($question, $context);
    }

    private function spend(RecognisedIntent $q, LedgerContext $context): Answer
    {
        $period = $q->period;
        $rows = $this->byCategory($context, $period->fromDate(), $period->toDate());
        $link = $this->sources(['Expenses, '.$period->label, fx_route('transactions.index', ['type' => 'expense', 'from' => $period->fromDate(), 'to' => $period->toDate()])]);

        if ($rows === []) {
            return Answer::noData(Intent::Spend, "I don't have any expenses recorded for {$period->label}.", $link, [$period->explicit ? '' : 'No period was named, so I looked at this month so far.']);
        }

        $byCurrency = [];
        foreach ($rows as $row) {
            $byCurrency[$row->currency] = ($byCurrency[$row->currency] ?? 0) + (int) $row->total;
        }
        ksort($byCurrency);

        $previous = $period->previous();
        $before = [];
        foreach ($this->byCategory($context, $previous->fromDate(), $previous->toDate()) as $row) {
            $before[$row->currency] = ($before[$row->currency] ?? 0) + (int) $row->total;
        }

        $facts = [];
        $parts = [];
        foreach ($byCurrency as $currency => $total) {
            $facts[] = ['label' => "Spent in {$currency}", 'value' => $this->money($total, $currency)];
            $parts[] = $this->money($total, $currency);
            if (isset($before[$currency]) && $before[$currency] > 0) {
                $diff = $total - $before[$currency];
                $facts[] = ['label' => "Compared with {$previous->label} ({$currency})", 'value' => ($diff >= 0 ? 'up ' : 'down ').$this->money(abs($diff), $currency).' ('.$this->percent(abs($diff), $before[$currency]).'%)'];
            }
        }

        $names = Category::query()->forContext($context)->withTrashed()->pluck('name', 'id');
        $top = collect($rows)->sortByDesc('total')->take(5);
        $tableRows = [];
        foreach ($top as $row) {
            $tableRows[] = [
                'cells' => [$row->category_id === null ? 'No category' : (string) ($names[$row->category_id] ?? 'Deleted category'), $this->money((int) $row->total, $row->currency)],
                'href' => fx_route('transactions.index', array_filter(['type' => 'expense', 'category_id' => $row->category_id ?? 'none', 'from' => $period->fromDate(), 'to' => $period->toDate()])),
            ];
        }

        return new Answer(
            Intent::Spend,
            Answer::ANSWERED,
            'You spent '.implode(' and ', $parts)." {$period->inPhrase()}.",
            $facts,
            ['Category', 'Spent'],
            $tableRows,
            $link,
            notes: array_values(array_filter([$period->explicit ? null : 'No period was named, so this is this month so far.', 'Money moved between your own accounts (transfers) is not counted as spending.'])),
        );
    }

    private function increased(RecognisedIntent $q, LedgerContext $context): Answer
    {
        $period = $q->period;
        $previous = $period->previous();

        $now = $this->byCategory($context, $period->fromDate(), $period->toDate());
        $link = $this->sources(['Expenses, '.$period->label, fx_route('transactions.index', ['type' => 'expense', 'from' => $period->fromDate(), 'to' => $period->toDate()])]);

        if ($now === []) {
            return Answer::noData(Intent::ExpensesIncreased, "I don't have any expenses recorded for {$period->label}, so there is nothing to compare.", $link);
        }

        $before = [];
        foreach ($this->byCategory($context, $previous->fromDate(), $previous->toDate()) as $row) {
            $before[$row->currency.'|'.($row->category_id ?? 'none')] = (int) $row->total;
        }

        if ($before === []) {
            return Answer::noData(Intent::ExpensesIncreased, "I don't have expenses recorded for {$previous->label} to compare {$period->label} with.", $link);
        }

        $names = Category::query()->forContext($context)->withTrashed()->pluck('name', 'id');
        $changes = [];
        foreach ($now as $row) {
            $key = $row->currency.'|'.($row->category_id ?? 'none');
            $prev = $before[$key] ?? 0;
            $diff = (int) $row->total - $prev;
            if ($diff > 0) {
                $changes[] = ['row' => $row, 'prev' => $prev, 'diff' => $diff];
            }
        }

        usort($changes, fn ($a, $b) => $b['diff'] <=> $a['diff']);
        $changes = array_slice($changes, 0, 5);

        if ($changes === []) {
            return new Answer(Intent::ExpensesIncreased, Answer::ANSWERED, "No expense category went up {$period->inPhrase()} compared with {$previous->label}.", sources: $link, notes: ["Compared with {$previous->label}."]);
        }

        $rows = [];
        foreach ($changes as $c) {
            $row = $c['row'];
            $label = $row->category_id === null ? 'No category' : (string) ($names[$row->category_id] ?? 'Deleted category');
            $rows[] = [
                'cells' => [
                    $label,
                    $this->money($c['prev'], $row->currency),
                    $this->money((int) $row->total, $row->currency),
                    '+'.$this->money($c['diff'], $row->currency).($c['prev'] > 0 ? ' ('.$this->percent($c['diff'], $c['prev']).'%)' : ' (new)'),
                ],
                'href' => fx_route('transactions.index', array_filter(['type' => 'expense', 'category_id' => $row->category_id ?? 'none', 'from' => $period->fromDate(), 'to' => $period->toDate()])),
            ];
        }

        $top = $changes[0];
        $topRow = $top['row'];
        $topName = $topRow->category_id === null ? 'expenses with no category' : (string) ($names[$topRow->category_id] ?? 'a deleted category');

        return new Answer(
            Intent::ExpensesIncreased,
            Answer::ANSWERED,
            "The biggest increase {$period->inPhrase()} is {$topName}, up ".$this->money($top['diff'], $topRow->currency).($top['prev'] > 0 ? ' ('.$this->percent($top['diff'], $top['prev']).'%)' : '')." compared with {$previous->label}.",
            [],
            ['Category', $previous->label, $period->label, 'Change'],
            $rows,
            $link,
            notes: ["Compared with {$previous->label}."],
        );
    }

    /** @return list<object{category_id: ?int, currency: string, total: int}> */
    private function byCategory(LedgerContext $context, string $from, string $to): array
    {
        return Transaction::query()->forContext($context)->counted()
            ->where('type', 'expense')
            ->where('date', '>=', $from)
            ->where('date', '<', date('Y-m-d', strtotime($to.' +1 day')))
            ->selectRaw('category_id, currency, SUM(amount_cents) AS total')
            ->groupBy('category_id', 'currency')
            ->get()
            ->all();
    }
}
