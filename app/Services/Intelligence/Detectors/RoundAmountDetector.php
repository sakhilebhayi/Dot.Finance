<?php

namespace App\Services\Intelligence\Detectors;

use App\Models\Category;
use App\Models\Transaction;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * An expense of exactly R5,000, R10,000 and so on in a category whose other entries are almost never round. A round
 * amount is not wrong; it is worth a second look because invented or estimated amounts tend to be round.
 */
final class RoundAmountDetector implements Detector
{
    public function kind(): string
    {
        return 'round_amount';
    }

    public function period(CarbonImmutable $asOf): string
    {
        return $asOf->format('Y-m');
    }

    public function detect(LedgerContext $context, CarbonImmutable $asOf): array
    {
        $step = (int) config('intelligence.detectors.round_amount_cents');
        $min = (int) config('intelligence.detectors.round_min_cents');

        $recent = Transaction::query()->forContext($context)->counted()->where('type', 'expense')
            ->where('date', '>=', $asOf->subDays(30)->toDateString())->where('date', '<=', $asOf->toDateString())
            ->where('amount_cents', '>=', $min)->get()
            ->filter(fn (Transaction $t) => $t->amount_cents % $step === 0);

        if ($recent->isEmpty()) {
            return [];
        }

        $names = Category::query()->forContext($context)->withTrashed()->pluck('name', 'id');
        $findings = [];

        foreach ($recent as $t) {
            $others = Transaction::query()->forContext($context)->counted()->where('type', 'expense')
                ->where('currency', $t->currency)->whereKeyNot($t->id)
                ->when($t->category_id === null, fn ($q) => $q->whereNull('category_id'), fn ($q) => $q->where('category_id', $t->category_id))
                ->pluck('amount_cents');

            if ($others->count() < 5) {
                continue;
            }

            $round = $others->filter(fn ($c) => $c % $step === 0)->count();
            if ($round * 5 > $others->count()) { // more than 20% of the others are round too: this category is just round
                continue;
            }

            $label = $t->category_id === null ? 'no category' : (string) ($names[$t->category_id] ?? 'a deleted category');

            $findings[] = new Finding(
                $this->kind(),
                'round:'.$t->id,
                $t->date->format('Y-m'),
                'info',
                'Round amount: '.Money::format((int) $t->amount_cents, $t->currency).' for '.$t->description,
                'This expense is an exact round amount, and almost none of the other '.$others->count()." entries with {$label} are. That is worth a look: round figures are often estimates or typed-in placeholders. If it is correct, dismiss this.",
                [['label' => $t->date->toDateString().', '.$t->description, 'value' => Money::format((int) $t->amount_cents, $t->currency), 'href' => fx_route('transactions.edit', ['transaction' => $t->id])]],
                fx_route('transactions.edit', ['transaction' => $t->id]),
            );
        }

        return $findings;
    }
}
