<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
use App\Support\Ledger\LedgerAccess;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * What a person may do with a account: decided by LedgerAccess from the ledger the
 * account belongs to (their own personal ledger, or a company they belong to) and
 * their role in it. Another ledger's account never reaches here: the shared ledger
 * scope already 404s it.
 */
class AccountPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return LedgerAccess::current($user)?->canRead() ?? false;
    }

    public function view(User $user, Account $account): bool
    {
        return LedgerAccess::forRow($user, $account)?->canRead() ?? false;
    }

    public function create(User $user): bool
    {
        return LedgerAccess::current($user)?->canEditStructure() ?? false;
    }

    public function update(User $user, Account $account): bool
    {
        return LedgerAccess::forRow($user, $account)?->canEditStructure() ?? false;
    }

    public function delete(User $user, Account $account): bool
    {
        return LedgerAccess::forRow($user, $account)?->canDestroyStructure() ?? false;
    }

    /** Changing the opening balance moves every balance, so it is a separate, stricter ability. */
    public function adjustOpeningBalance(User $user, Account $account): bool
    {
        return LedgerAccess::forRow($user, $account)?->canAdjustOpeningBalance() ?? false;
    }
}
