<?php

namespace App\Services;

use App\Models\Account;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Collection;

/**
 * Every account of a ledger with its derived balance, computed once per request.
 *
 * The balance is a correlated subquery over the whole transaction history
 * (Account::scopeWithBalance); the dashboard used to run it three times (position,
 * accounts, runway per currency). This holds the result for the life of the request
 * (the service is bound as scoped, so a queue worker or Octane request starts empty) and
 * is flushed by the models whenever a transaction or an account is written, so a read
 * after a write in the same request is never stale.
 */
final class AccountBalances
{
    /** @var array<string, Collection<int, Account>> */
    private array $memo = [];

    /** @return Collection<int, Account> accounts (not deleted) ordered by name, each carrying derived_balance_cents */
    public function forContext(LedgerContext $context): Collection
    {
        return $this->memo[$context->key()] ??= Account::query()->forContext($context)->withBalance()->orderBy('name')->get();
    }

    public function flush(): void
    {
        $this->memo = [];
    }
}
