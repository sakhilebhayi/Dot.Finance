<?php

namespace App\Policies\Shared;

use App\Models\Shared\Party;
use App\Models\User;
use App\Support\Ledger\LedgerAccess;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Customers and suppliers: anyone in the ledger reads them; whoever may record entries may add,
 * edit, archive and restore them. (LedgerAccess::canRecordEntries is the stand-in until the
 * Controls module maps finer capabilities; this is the one place to change.)
 */
class PartyPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return LedgerAccess::current($user)?->canRead() ?? false;
    }

    public function view(User $user, Party $party): bool
    {
        return LedgerAccess::forRow($user, $party)?->canRead() ?? false;
    }

    public function create(User $user): bool
    {
        return LedgerAccess::current($user)?->canRecordEntries() ?? false;
    }

    public function update(User $user, Party $party): bool
    {
        return LedgerAccess::forRow($user, $party)?->canRecordEntries() ?? false;
    }

    /** Archive. */
    public function delete(User $user, Party $party): bool
    {
        return LedgerAccess::forRow($user, $party)?->canRecordEntries() ?? false;
    }

    public function restore(User $user, Party $party): bool
    {
        return LedgerAccess::forRow($user, $party)?->canRecordEntries() ?? false;
    }

    /** Attach a document to this party. */
    public function attach(User $user, Party $party): bool
    {
        return LedgerAccess::forRow($user, $party)?->canRecordEntries() ?? false;
    }
}
