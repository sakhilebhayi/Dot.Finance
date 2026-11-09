<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;
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
        // R44 SALES: the cash leg of a payment received or a refund is changed by reversing that payment, not by editing the row
        return $transaction->settlement_ref === null && (LedgerAccess::forRow($user, $transaction)?->canRecordEntries() ?? false);
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return $transaction->settlement_ref === null && (LedgerAccess::forRow($user, $transaction)?->canRecordEntries() ?? false);
    }
}
