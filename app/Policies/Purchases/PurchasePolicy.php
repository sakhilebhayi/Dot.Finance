<?php

namespace App\Policies\Purchases;

use App\Enums\Capability;
use App\Models\Purchases\Bill;
use App\Models\Purchases\ExpenseClaim;
use App\Models\Purchases\ExpenseClaimLine;
use App\Models\Purchases\PaymentRun;
use App\Models\Purchases\RecurringBill;
use App\Models\User;
use App\Support\Ledger\LedgerAccess;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may do what with the Purchases records. Reading needs only membership of the ledger the record belongs to. Everything that
 * writes asks the Controls capability for it (LedgerAccess::can), against the RECORD's ledger, so an id from another ledger is
 * refused here even if routing ever let it through (the ledger scope already 404s it first).
 *
 *   bills, credit notes, recurring bills     enter-bill        edit only while a draft
 *   void a bill                              approve-bill
 *   expense claims                           record-entries    void: approve-expense
 *   payment runs                             record-payment
 */
class PurchasePolicy
{
    use HandlesAuthorization;

    public function view(User $user, Model $record): bool
    {
        if ($record instanceof ExpenseClaimLine) {
            $record = $record->claim;
        }

        return LedgerAccess::forRow($user, $record)?->canRead() ?? false;
    }

    public function viewAny(User $user): bool
    {
        return LedgerAccess::current($user)?->canRead() ?? false;
    }

    public function create(User $user): bool
    {
        return LedgerAccess::current($user)?->can(Capability::EnterBill) ?? false;
    }

    public function update(User $user, Model $record): bool
    {
        return $this->can($user, $record, $this->writeCapability($record)) && $this->isDraft($record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }

    /** Submit for approval / post. */
    public function submit(User $user, Model $record): bool
    {
        return $this->can($user, $record, $this->writeCapability($record));
    }

    /** Attach a document to it. */
    public function attach(User $user, Model $record): bool
    {
        return $this->can($user, $record, $this->writeCapability($record));
    }

    /** Void a posted record. */
    public function void(User $user, Model $record): bool
    {
        return $this->can($user, $record, $record instanceof ExpenseClaim ? Capability::ApproveExpense : Capability::ApproveBill);
    }

    private function writeCapability(Model $record): Capability
    {
        return match (true) {
            $record instanceof ExpenseClaim => Capability::RecordEntries,
            $record instanceof PaymentRun => Capability::RecordPayment,
            default => Capability::EnterBill,
        };
    }

    private function isDraft(Model $record): bool
    {
        return match (true) {
            $record instanceof Bill, $record instanceof ExpenseClaim => $record->status === 'draft',
            $record instanceof PaymentRun => $record->status === 'draft',
            $record instanceof RecurringBill => true,
            default => false,
        };
    }

    private function can(User $user, Model $record, Capability $capability): bool
    {
        return LedgerAccess::forRow($user, $record)?->can($capability) ?? false;
    }
}
