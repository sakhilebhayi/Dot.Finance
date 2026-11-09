<?php

namespace App\Enums;

/**
 * Everything a person can be allowed to do in a ledger. LedgerAccess::can() is the single
 * place that answers "may this person do X here"; every policy, gate and service asks it.
 *
 * The values are kebab-case and double as Gate names (see ControlsServiceProvider), so a
 * route can say `->can('approve-bill')` or a controller `$this->authorize('approve-bill')`.
 *
 * The last three are the legacy structure abilities the first three roles already had
 * (LedgerAccess::canEditStructure / canDestroyStructure / canAdjustOpeningBalance). They are
 * kept as their own capabilities so those methods can keep their exact meaning.
 */
enum Capability: string
{
    case ViewFinance = 'view-finance';
    case RecordEntries = 'record-entries';
    case ManageChart = 'manage-chart';
    case PostJournal = 'post-journal';
    case ClosePeriod = 'close-period';
    case ManageParties = 'manage-parties';
    case IssueInvoice = 'issue-invoice';
    case ApproveInvoice = 'approve-invoice';
    case RecordPayment = 'record-payment';
    case ApprovePayment = 'approve-payment';
    case EnterBill = 'enter-bill';
    case ApproveBill = 'approve-bill';
    case ApproveExpense = 'approve-expense';
    case ManageTax = 'manage-tax';
    case PrepareTaxReturn = 'prepare-tax-return';
    case RecordTaxSubmission = 'record-tax-submission';
    case ViewPayrollDetail = 'view-payroll-detail';
    case ManageBudgets = 'manage-budgets';
    case ApproveBudget = 'approve-budget';
    case ExportReports = 'export-reports';
    case ManageMembers = 'manage-members';
    case ViewAudit = 'view-audit';
    case ManageApprovals = 'manage-approvals';
    case RunReconciliation = 'run-reconciliation';

    // Legacy structure abilities (kept so the original LedgerAccess methods mean what they always meant).
    case EditStructure = 'edit-structure';
    case DestroyStructure = 'destroy-structure';
    case AdjustOpeningBalance = 'adjust-opening-balance';

    public function label(): string
    {
        return match ($this) {
            self::ViewFinance => 'View the books and reports',
            self::RecordEntries => 'Record, edit and delete transactions, transfers and imports',
            self::ManageChart => 'Manage the chart of accounts',
            self::PostJournal => 'Post manual journals',
            self::ClosePeriod => 'Close and reopen accounting periods',
            self::ManageParties => 'Manage customers and suppliers',
            self::IssueInvoice => 'Create and issue invoices',
            self::ApproveInvoice => 'Approve invoices',
            self::RecordPayment => 'Record payments',
            self::ApprovePayment => 'Approve payments',
            self::EnterBill => 'Enter supplier bills',
            self::ApproveBill => 'Approve supplier bills',
            self::ApproveExpense => 'Approve expense claims',
            self::ManageTax => 'Manage tax settings and codes',
            self::PrepareTaxReturn => 'Prepare tax returns',
            self::RecordTaxSubmission => 'Record a tax submission reference',
            self::ViewPayrollDetail => 'See payroll detail',
            self::ManageBudgets => 'Create and edit budgets',
            self::ApproveBudget => 'Approve budgets',
            self::ExportReports => 'Export reports',
            self::ManageMembers => 'Manage members and roles',
            self::ViewAudit => 'Read the audit log',
            self::ManageApprovals => 'Set approval policies and spend limits',
            self::RunReconciliation => 'Run bank reconciliation',
            self::EditStructure => 'Create and edit accounts and categories',
            self::DestroyStructure => 'Delete accounts, categories and budgets',
            self::AdjustOpeningBalance => 'Adjust opening balances',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
