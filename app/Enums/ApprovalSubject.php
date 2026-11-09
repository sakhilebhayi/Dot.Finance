<?php

namespace App\Enums;

/** The kinds of thing an approval policy can cover, and the capability that normally approves each. */
enum ApprovalSubject: string
{
    case Expense = 'expense';
    case Bill = 'bill';
    case Payment = 'payment';
    case Journal = 'journal';
    case Invoice = 'invoice';
    case PeriodClose = 'period_close';
    case Budget = 'budget';

    public function label(): string
    {
        return match ($this) {
            self::Expense => 'Expense claim',
            self::Bill => 'Supplier bill',
            self::Payment => 'Payment',
            self::Journal => 'Manual journal',
            self::Invoice => 'Invoice',
            self::PeriodClose => 'Period close',
            self::Budget => 'Budget',
        };
    }

    public function plural(): string
    {
        return match ($this) {
            self::Expense => 'expense claims',
            self::Bill => 'bills',
            self::Payment => 'payments',
            self::Journal => 'manual journals',
            self::Invoice => 'invoices',
            self::PeriodClose => 'period closes',
            self::Budget => 'budgets',
        };
    }

    /** The capability a person needs to approve this kind of subject (a policy may name another). */
    public function defaultCapability(): Capability
    {
        return match ($this) {
            self::Expense => Capability::ApproveExpense,
            self::Bill => Capability::ApproveBill,
            self::Payment => Capability::ApprovePayment,
            self::Journal => Capability::PostJournal,
            self::Invoice => Capability::ApproveInvoice,
            self::PeriodClose => Capability::ClosePeriod,
            self::Budget => Capability::ApproveBudget,
        };
    }

    /** Capabilities a policy may require of its approvers. @return list<Capability> */
    public static function approverCapabilities(): array
    {
        return [
            Capability::ApproveExpense, Capability::ApproveBill, Capability::ApprovePayment, Capability::ApproveInvoice,
            Capability::ApproveBudget, Capability::PostJournal, Capability::ClosePeriod,
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
