<?php

namespace App\Services\Accounting;

use App\Services\Accounting\Exceptions\UnpostableSource;

/**
 * Everything PostingRules needs to know about a ledger, loaded once: the base currency, which GL
 * account each system key is, which GL account each cash account and category maps to. Built by
 * Chart::map(); in tests it can be constructed by hand, which is why the rules need no database.
 */
final class PostingMap
{
    /**
     * @param  array<string, int>  $systemIds  system key => gl_accounts.id
     * @param  array<int, array{gl: int, currency: string, type: string}>  $cash  cash account id => its GL account and currency
     * @param  array<int, int>  $categories  category id => gl_accounts.id
     */
    public function __construct(
        public readonly string $baseCurrency,
        public readonly array $systemIds,
        public readonly array $cash,
        public readonly array $categories,
    ) {}

    public function system(string $key): int
    {
        return $this->systemIds[$key] ?? throw new UnpostableSource("The chart has no '{$key}' account.");
    }

    /** @return array{gl: int, currency: string, type: string} */
    public function cashAccount(int $accountId): array
    {
        return $this->cash[$accountId] ?? throw new UnpostableSource("Cash account #{$accountId} is not mapped to a GL account.");
    }

    /** The GL account for a category, or the uncategorised account for its type when it has none. */
    public function categoryOrDefault(?int $categoryId, string $type): int
    {
        if ($categoryId !== null && isset($this->categories[$categoryId])) {
            return $this->categories[$categoryId];
        }

        return $this->system($type === 'income' ? 'uncategorised_income' : 'uncategorised_expense');
    }
}
