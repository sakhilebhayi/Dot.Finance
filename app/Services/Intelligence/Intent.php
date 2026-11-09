<?php

namespace App\Services\Intelligence;

/** The questions the assistant can answer. Each one maps to a fixed service call over the active ledger. */
enum Intent: string
{
    case Spend = 'spend';
    case ExpensesIncreased = 'expenses_increased';
    case WhoOwesMe = 'who_owes_me';
    case OverdueInvoices = 'overdue_invoices';
    case UpcomingBills = 'upcoming_bills';
    case CashPosition = 'cash_position';
    case ProjectedCash = 'projected_cash';
    case Profitable = 'profitable';
    case VatLiability = 'vat_liability';
    case TaxToPrepare = 'tax_to_prepare';
    case NeedsAttention = 'needs_attention';
    case BudgetPerformance = 'budget_performance';
    case CanIAfford = 'can_i_afford';
    case DraftTransaction = 'draft_transaction';
    case DraftCategorise = 'draft_categorise';
    case DraftReminder = 'draft_reminder';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Spend => 'Spending',
            self::ExpensesIncreased => 'Expenses that went up',
            self::WhoOwesMe => 'Who owes you money',
            self::OverdueInvoices => 'Overdue invoices',
            self::UpcomingBills => 'Upcoming bills',
            self::CashPosition => 'Cash position',
            self::ProjectedCash => 'Projected cash',
            self::Profitable => 'Profit',
            self::VatLiability => 'VAT so far',
            self::TaxToPrepare => 'Tax to prepare for',
            self::NeedsAttention => 'What needs attention',
            self::BudgetPerformance => 'Budgets',
            self::CanIAfford => 'Can you afford it',
            self::DraftTransaction => 'A draft transaction',
            self::DraftCategorise => 'Draft categorisation',
            self::DraftReminder => 'A draft reminder',
            self::Unknown => 'Not understood',
        };
    }

    /** Intents that propose a draft instead of answering with figures. */
    public function isDraft(): bool
    {
        return in_array($this, [self::DraftTransaction, self::DraftCategorise, self::DraftReminder], true);
    }

    /** The questions offered as starting points on the assistant page. @return list<string> */
    public static function suggestions(): array
    {
        return [
            'How much did I spend this month?',
            'Which expenses increased the most?',
            'Who owes me money?',
            'Which invoices are overdue?',
            'What bills are coming up?',
            'What is my cash position?',
            'What will my cash be in 60 days?',
            'Am I profitable this month?',
            'What is my VAT liability so far?',
            'What tax do I need to prepare for?',
            'Which transactions need attention?',
            'How are my budgets doing?',
            'Can I afford R5,000?',
        ];
    }
}
