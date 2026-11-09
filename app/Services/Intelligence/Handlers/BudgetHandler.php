<?php

namespace App\Services\Intelligence\Handlers;

use App\Models\Budget;
use App\Models\User;
use App\Services\BudgetSpendCalculator;
use App\Services\Intelligence\Answer;
use App\Services\Intelligence\Intent;
use App\Services\Intelligence\RecognisedIntent;
use App\Support\Ledger\LedgerContext;

/** "How are my budgets doing?" The budgets of the month asked about (this month by default). */
final class BudgetHandler extends Handler
{
    public function __construct(private readonly BudgetSpendCalculator $spend) {}

    public function intents(): array
    {
        return [Intent::BudgetPerformance];
    }

    public function handle(RecognisedIntent $question, LedgerContext $context, ?User $user = null): Answer
    {
        $month = (int) $question->period->from->format('n');
        $year = (int) $question->period->from->format('Y');
        $link = $this->sources(['Budgets', fx_route('budgets.index')]);

        $budgets = Budget::query()->forContext($context)->with('category')->where('month', $month)->where('year', $year)->get();

        if ($budgets->isEmpty()) {
            return Answer::noData(Intent::BudgetPerformance, "I don't have any budgets for ".$question->period->from->format('F Y').'. Set one on the Budgets page and I can tell you how it is going.', $link);
        }

        $this->spend->attach($budgets);

        $over = 0;
        $rows = [];
        foreach ($budgets->sortByDesc(fn (Budget $b) => $b->amount_cents > 0 ? $b->spentCents() / $b->amount_cents : 0) as $budget) {
            $spent = $budget->spentCents();
            $limit = $budget->amount_cents;
            $isOver = $limit > 0 && $spent >= $limit;
            $over += $isOver ? 1 : 0;
            $rows[] = [
                'cells' => [
                    (string) ($budget->category?->name ?? 'Deleted category'),
                    $this->money($spent, $budget->currency).' of '.$this->money($limit, $budget->currency),
                    $this->percent($spent, $limit).'% used'.($isOver ? ' (at or over)' : ''),
                ],
                'href' => fx_route('budgets.index'),
            ];
        }

        $total = $budgets->count();

        return new Answer(
            Intent::BudgetPerformance,
            Answer::ANSWERED,
            $over === 0
                ? 'None of your '.$this->plural($total, 'budget', 'budgets').' for '.$question->period->from->format('F Y').' is at or over its limit.'
                : $over.' of your '.$this->plural($total, 'budget', 'budgets').' for '.$question->period->from->format('F Y').($over === 1 ? ' is' : ' are').' at or over the limit.',
            [['label' => 'Budgets at or over their limit', 'value' => (string) $over, 'tone' => $over > 0 ? 'variance' : 'neutral']],
            ['Category', 'Spent of limit', 'Used'],
            $rows,
            $link,
            notes: ['Spend is the expenses recorded in each budget\'s category and currency in that month.'],
        );
    }
}
