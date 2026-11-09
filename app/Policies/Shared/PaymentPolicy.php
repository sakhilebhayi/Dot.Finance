<?php

namespace App\Policies\Shared;

use App\Models\Shared\Payment;
use App\Models\User;
use App\Support\Ledger\LedgerAccess;
use Illuminate\Auth\Access\HandlesAuthorization;

/** Payments: read by anyone in the ledger; recorded, allocated and reversed by whoever may record entries. */
class PaymentPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return LedgerAccess::current($user)?->canRead() ?? false;
    }

    public function view(User $user, Payment $payment): bool
    {
        return LedgerAccess::forRow($user, $payment)?->canRead() ?? false;
    }

    public function create(User $user): bool
    {
        return LedgerAccess::current($user)?->canRecordEntries() ?? false;
    }

    public function allocate(User $user, Payment $payment): bool
    {
        return LedgerAccess::forRow($user, $payment)?->canRecordEntries() ?? false;
    }

    public function reverse(User $user, Payment $payment): bool
    {
        return LedgerAccess::forRow($user, $payment)?->canRecordEntries() ?? false;
    }
}
