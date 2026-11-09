<?php

namespace App\Enums;

/**
 * The six finance roles of a ledger membership, and what each may do.
 *
 * Two things are kept apart on purpose:
 *
 *  - the BASELINE comes from InfoDot (via the synced team role): InfoDot owner/admin are
 *    finance managers, an editor is a bookkeeper, a viewer is a viewer, and the company's
 *    local owner (the person who created the company here) is the owner;
 *  - a LOCAL GRANT (ledger_member_roles) refines it for this ledger, but never beyond what
 *    InfoDot allows for that person (see grantableOn()): InfoDot is the ceiling.
 *
 * rank() orders the roles for "you cannot give or change a role above your own".
 * Approver is a lateral role (it approves but writes nothing), so it ranks just above viewer.
 */
enum FinanceRole: string
{
    case Owner = 'owner';
    case FinanceManager = 'finance_manager';
    case Accountant = 'accountant';
    case Bookkeeper = 'bookkeeper';
    case Approver = 'approver';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::FinanceManager => 'Finance manager',
            self::Accountant => 'Accountant',
            self::Bookkeeper => 'Bookkeeper',
            self::Approver => 'Approver',
            self::Viewer => 'Viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Everything, including members, roles, approval policies and spend limits.',
            self::FinanceManager => 'Everything an owner can do in the books, including members, roles and approvals.',
            self::Accountant => 'Posts journals, manages the chart, closes periods, prepares tax and exports. Does not approve spending.',
            self::Bookkeeper => 'Records day-to-day entries, bills, invoices and payments. Cannot post journals, close periods or approve.',
            self::Approver => 'Reads the books and approves bills, payments, expenses, invoices and budgets. Records nothing.',
            self::Viewer => 'Reads the books and reports. Changes nothing.',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Owner => 6,
            self::FinanceManager => 5,
            self::Accountant => 4,
            self::Bookkeeper => 3,
            self::Approver => 2,
            self::Viewer => 1,
        };
    }

    /** The original three-level role this maps back to (what LedgerAccess::role() has always returned). */
    public function legacyRole(): string
    {
        return match ($this) {
            self::Owner => 'owner',
            self::FinanceManager => 'admin',
            self::Accountant, self::Bookkeeper => 'editor',
            self::Approver, self::Viewer => 'viewer',
        };
    }

    /** The finance role InfoDot's (synced) role gives with no local grant. Anything unknown is a viewer. */
    public static function baselineFor(string $legacyRole): self
    {
        return match ($legacyRole) {
            'owner' => self::Owner,
            'admin' => self::FinanceManager,
            'editor' => self::Bookkeeper,
            default => self::Viewer,
        };
    }

    /**
     * Roles a local grant may give a person whose InfoDot-synced baseline is $this. This is the
     * ceiling that stops local grants (and the sync) from escalating beyond what InfoDot says:
     * a viewer can be made an approver (no write power) but never a bookkeeper; an editor can be
     * refined up to accountant but never to finance manager; only an InfoDot owner/admin can be a
     * finance manager. Narrowing is always allowed. Nobody is ever granted "owner" locally: the
     * owner is the company's local owner and no one else.
     *
     * @return list<self>
     */
    public function grantable(): array
    {
        return match ($this) {
            self::Owner => [],
            self::FinanceManager => [self::FinanceManager, self::Accountant, self::Bookkeeper, self::Approver, self::Viewer],
            self::Bookkeeper, self::Accountant => [self::Accountant, self::Bookkeeper, self::Approver, self::Viewer],
            self::Approver, self::Viewer => [self::Approver, self::Viewer],
        };
    }

    public function canBeGrantedOn(self $baseline): bool
    {
        return in_array($this, $baseline->grantable(), true);
    }

    /** @return list<Capability> */
    public function capabilities(): array
    {
        $read = [Capability::ViewFinance];

        return match ($this) {
            self::Owner, self::FinanceManager => Capability::cases(),
            self::Accountant => [
                ...$read,
                Capability::RecordEntries, Capability::EditStructure, Capability::ManageChart, Capability::PostJournal,
                Capability::ClosePeriod, Capability::ManageParties, Capability::IssueInvoice, Capability::RecordPayment,
                Capability::EnterBill, Capability::ManageTax, Capability::PrepareTaxReturn, Capability::RecordTaxSubmission,
                Capability::ViewPayrollDetail, Capability::ManageBudgets, Capability::ExportReports, Capability::ViewAudit,
                Capability::RunReconciliation,
            ],
            self::Bookkeeper => [
                ...$read,
                Capability::RecordEntries, Capability::EditStructure, Capability::ManageParties, Capability::IssueInvoice,
                Capability::RecordPayment, Capability::EnterBill, Capability::ManageBudgets, Capability::ExportReports,
                Capability::RunReconciliation,
            ],
            self::Approver => [
                ...$read,
                Capability::ApproveInvoice, Capability::ApprovePayment, Capability::ApproveBill, Capability::ApproveExpense,
                Capability::ApproveBudget,
            ],
            self::Viewer => $read,
        };
    }

    public function grants(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}
