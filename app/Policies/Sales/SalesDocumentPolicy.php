<?php

namespace App\Policies\Sales;

use App\Enums\Capability;
use App\Models\Sales\SalesDocument;
use App\Models\User;
use App\Support\Ledger\LedgerAccess;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Who may do what with a sales document. Everything is a capability of the ledger the document belongs to
 * (LedgerAccess::can), never a role name. A document from another ledger is a 404 before this runs.
 *
 *   view            anyone who may read the books
 *   create/update   issue-invoice (draft work), and a document that is no longer a draft cannot be updated
 *   issue, void, send, share, credit, convert    issue-invoice
 *   record payment  record-payment
 *   write off       approve-invoice
 */
class SalesDocumentPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return LedgerAccess::current($user)?->can(Capability::ViewFinance) ?? false;
    }

    public function view(User $user, SalesDocument $document): bool
    {
        return LedgerAccess::forRow($user, $document)?->can(Capability::ViewFinance) ?? false;
    }

    public function create(User $user): bool
    {
        return LedgerAccess::current($user)?->can(Capability::IssueInvoice) ?? false;
    }

    public function update(User $user, SalesDocument $document): bool
    {
        return $document->status === 'draft' && $this->holds($user, $document, Capability::IssueInvoice);
    }

    public function discard(User $user, SalesDocument $document): bool
    {
        return in_array($document->status, ['draft', 'awaiting_approval'], true) && $this->holds($user, $document, Capability::IssueInvoice);
    }

    public function issue(User $user, SalesDocument $document): bool
    {
        return $this->holds($user, $document, Capability::IssueInvoice);
    }

    public function void(User $user, SalesDocument $document): bool
    {
        return $this->holds($user, $document, Capability::IssueInvoice);
    }

    public function send(User $user, SalesDocument $document): bool
    {
        return $this->holds($user, $document, Capability::IssueInvoice);
    }

    public function correct(User $user, SalesDocument $document): bool
    {
        return $this->holds($user, $document, Capability::IssueInvoice);
    }

    public function recordPayment(User $user, SalesDocument $document): bool
    {
        return $this->holds($user, $document, Capability::RecordPayment);
    }

    public function writeOff(User $user, SalesDocument $document): bool
    {
        // someone who can approve writes off directly; someone who can only issue may ask when an approval rule covers it
        return $this->holds($user, $document, Capability::ApproveInvoice) || $this->holds($user, $document, Capability::IssueInvoice);
    }

    private function holds(User $user, SalesDocument $document, Capability $capability): bool
    {
        return LedgerAccess::forRow($user, $document)?->can($capability) ?? false;
    }
}
