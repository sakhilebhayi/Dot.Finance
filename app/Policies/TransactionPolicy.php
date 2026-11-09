<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\SettlementTransactions;
use App\Support\Ledger\LedgerAccess;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * What a person may do with a transaction: decided by LedgerAccess from the ledger it
 * belongs to and their role in it. This is also the guard that stops one person from
 * reaching another's transactions by guessing the route-model-bound {transaction} id
 * (the shared ledger scope 404s it first; this is the second line).
 */
class TransactionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return LedgerAccess::current($user)?->canRead() ?? false;
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return LedgerAccess::forRow($user, $transaction)?->canRead() ?? false;
    }

    public function create(User $user): bool
    {
        return LedgerAccess::current($user)?->canRecordEntries() ?? false;
    }

    public function update(User $user, Transaction $transaction): bool
    {
        // R-44: the cash row of a supplier payment (or a customer receipt) is changed by reversing the payment, never by editing it.
        return (LedgerAccess::forRow($user, $transaction)?->canRecordEntries() ?? false) && ! SettlementTransactions::is($transaction);
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return (LedgerAccess::forRow($user, $transaction)?->canRecordEntries() ?? false) && ! SettlementTransactions::is($transaction);
    }
}
