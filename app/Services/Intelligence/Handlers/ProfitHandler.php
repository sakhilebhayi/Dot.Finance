<?php

namespace App\Services\Intelligence\Handlers;

use App\Models\Transaction;
use App\Models\User;
use App\Services\Intelligence\Answer;
use App\Services\Intelligence\Intent;
use App\Services\Intelligence\RecognisedIntent;
use App\Support\Ledger\LedgerContext;

/** "Am I profitable?" Money in minus money out for the period, on the cash ledger, per currency. */
final class ProfitHandler extends Handler
{
    public function intents(): array
    {
        return [Intent::Profitable];
    }

    public function handle(RecognisedIntent $question, LedgerContext $context, ?User $user = null): Answer
    {
        $period = $question->period;
        $rows = Transaction::query()->forContext($context)->counted()
            ->whereIn('type', ['income', 'expense'])
            ->where('date', '>=', $period->fromDate())
            ->where('date', '<', date('Y-m-d', strtotime($period->toDate().' +1 day')))
            ->selectRaw('currency, type, SUM(amount_cents) AS total')
            ->groupBy('currency', 'type')
            ->get();

        $link = $this->sources(['Transactions in '.$period->label, fx_route('transactions.index', ['from' => $period->fromDate(), 'to' => $period->toDate()])], ['Cash flow', fx_route('reports.cash-flow')]);

        if ($rows->isEmpty()) {
            return Answer::noData(Intent::Profitable, "I don't have any income or expenses recorded for {$period->label}, so I cannot say.", $link);
        }

        $by = [];
        foreach ($rows as $row) {
            $by[$row->currency ?? 'ZAR'][$row->type] = (int) $row->total;
        }
        ksort($by);

        $facts = [];
        $sentences = [];
        foreach ($by as $currency => $t) {
            $income = $t['income'] ?? 0;
            $expense = $t['expense'] ?? 0;
            $net = $income - $expense;
            $facts[] = ['label' => "Income ({$currency})", 'value' => $this->money($income, $currency)];
            $facts[] = ['label' => "Expenses ({$currency})", 'value' => $this->money($expense, $currency)];
            $facts[] = ['label' => $net >= 0 ? "Surplus ({$currency})" : "Deficit ({$currency})", 'value' => $this->money(abs($net), $currency), 'tone' => $net < 0 ? 'variance' : 'neutral'];

            $sentences[] = $net >= 0
                ? 'You are in surplus by '.$this->money($net, $currency).' ('.$this->money($income, $currency).' in, '.$this->money($expense, $currency).' out)'
                : 'You are in deficit by '.$this->money(abs($net), $currency).' ('.$this->money($income, $currency).' in, '.$this->money($expense, $currency).' out)';
        }

        return new Answer(
            Intent::Profitable,
            Answer::ANSWERED,
            implode('; ', $sentences)." for {$period->label}.",
            $facts,
            sources: $link,
            notes: [
                'This is money in minus money out as recorded (a cash view), not an accrual profit and loss: unpaid invoices and bills are not in it.',
                $period->explicit ? '' : 'No period was named, so this is this month so far.',
            ],
        );
    }
}
