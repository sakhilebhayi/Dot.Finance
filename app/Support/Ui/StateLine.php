<?php

namespace App\Support\Ui;

use App\Models\Budget;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Services\BudgetSpendCalculator;
use App\Support\Ledger\LedgerContext;
use Closure;

/**
 * The persistent state line at the top of every page: what, in words, needs the owner right now. It is computed
 * from the active ledger only (every query carries the ledger scope) and only from facts that exist: a count of
 * rows, never an estimate.
 *
 * Each item is ['key', 'tone' => 'variance'|'neutral', 'count', 'text' (already worded, singular or plural),
 * 'href'|null]. 'variance' (pencil red) is reserved for something overdue or over its limit.
 *
 * Other modules add their own items from a service provider's boot(), for example:
 *   StateLine::extend(fn (LedgerContext $ledger) => ['key' => 'vat', 'tone' => 'neutral', 'count' => 2, 'text' => '2 VAT checks to review', 'href' => route('tax.vat.index')]);
 * A provider returns null (or [] ) when it has nothing to say. It runs on every page, so it must be cheap.
 */
final class StateLine
{
    /** @var array<int|string, Closure(LedgerContext): (array<string, mixed>|list<array<string, mixed>>|null)> */
    private static array $providers = [];

    /**
     * Add a provider. With a $key the provider replaces any earlier one registered under the same key, so a service
     * provider that boots more than once in a process (every test boots a fresh application) does not stack duplicates.
     */
    public static function extend(Closure $provider, ?string $key = null): void
    {
        $key === null ? self::$providers[] = $provider : self::$providers[$key] = $provider;
    }

    public static function flush(): void
    {
        self::$providers = [];
    }

    /** @return list<array<string, mixed>> */
    public static function for(?LedgerContext $ledger): array
    {
        if ($ledger === null) {
            return [];
        }

        $items = array_filter([
            self::overBudget(),
            self::recurringDue(),
            self::uncategorised(),
        ]);

        foreach (self::$providers as $provider) {
            $result = $provider($ledger);
            if (is_array($result) && $result !== []) {
                foreach (isset($result['key']) ? [$result] : $result as $item) {
                    $items[] = $item;
                }
            }
        }

        // Overdue and over-limit first, then the rest in the order they were found.
        usort($items, fn ($a, $b) => ($b['tone'] === 'variance') <=> ($a['tone'] === 'variance'));

        return array_values($items);
    }

    /** @return array<string, mixed>|null */
    private static function overBudget(): ?array
    {
        $budgets = Budget::query()->where('month', now()->month)->where('year', now()->year)->get();
        if ($budgets->isEmpty()) {
            return null;
        }

        app(BudgetSpendCalculator::class)->attach($budgets);
        $over = $budgets->filter(fn (Budget $b) => $b->amount_cents > 0 && $b->spentCents() >= $b->amount_cents)->count();

        if ($over === 0) {
            return null;
        }

        return [
            'key' => 'budgets-over',
            'tone' => 'variance',
            'count' => $over,
            'text' => $over === 1 ? '1 budget is at or over its limit' : "{$over} budgets are at or over their limit",
            'href' => fx_route('budgets.index'),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function recurringDue(): ?array
    {
        $due = RecurringRule::query()
            ->whereNull('paused_at')->whereNull('ended_at')
            ->whereNotNull('next_occurrence_date')
            ->whereDate('next_occurrence_date', '<', now()->toDateString())
            ->count();

        if ($due === 0) {
            return null;
        }

        return [
            'key' => 'recurring-due',
            'tone' => 'variance',
            'count' => $due,
            'text' => $due === 1 ? '1 recurring item is past its date and not added yet' : "{$due} recurring items are past their date and not added yet",
            'href' => fx_route('recurring.index'),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function uncategorised(): ?array
    {
        $count = Transaction::query()
            ->whereIn('type', ['income', 'expense'])
            ->whereNull('category_id')
            ->whereDate('date', '>=', now()->startOfMonth()->toDateString())
            ->count();

        if ($count === 0) {
            return null;
        }

        return [
            'key' => 'uncategorised',
            'tone' => 'neutral',
            'count' => $count,
            'text' => $count === 1 ? '1 transaction this month has no category' : "{$count} transactions this month have no category",
            'href' => fx_route('transactions.index', ['category_id' => 'none', 'from' => now()->startOfMonth()->toDateString()]),
        ];
    }
}
