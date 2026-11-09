<?php

namespace App\Services\Controls;

use App\Enums\ApprovalSubject;
use App\Enums\FinanceRole;
use App\Support\Money;

/**
 * One-click starting points for approval policies, worded the way a small business says it.
 * Nothing is on until a person applies one; applying one again updates it rather than doubling it.
 */
final class ApprovalPresets
{
    /**
     * @return array<string, array{subject: ApprovalSubject, takes_amount: bool, default_cents: int, steps: int, min_role: ?FinanceRole, summary: string}>
     */
    public static function all(): array
    {
        return [
            'owner_bills' => [
                'subject' => ApprovalSubject::Bill, 'takes_amount' => true, 'default_cents' => 500_000, 'steps' => 1,
                'min_role' => FinanceRole::Owner, 'summary' => 'The owner approves supplier bills over an amount.',
            ],
            'approver_expenses' => [
                'subject' => ApprovalSubject::Expense, 'takes_amount' => true, 'default_cents' => 100_000, 'steps' => 1,
                'min_role' => null, 'summary' => 'An approver reviews expense claims over an amount.',
            ],
            'two_person_payments' => [
                'subject' => ApprovalSubject::Payment, 'takes_amount' => true, 'default_cents' => 2_500_000, 'steps' => 2,
                'min_role' => null, 'summary' => 'Two different people approve payments over an amount.',
            ],
            'manager_invoices' => [
                'subject' => ApprovalSubject::Invoice, 'takes_amount' => true, 'default_cents' => 5_000_000, 'steps' => 1,
                'min_role' => FinanceRole::FinanceManager, 'summary' => 'A finance manager or owner approves invoices over an amount before they go out.',
            ],
            'manager_journals' => [
                'subject' => ApprovalSubject::Journal, 'takes_amount' => false, 'default_cents' => 0, 'steps' => 1,
                'min_role' => FinanceRole::FinanceManager, 'summary' => 'Every manual journal is approved by a finance manager or owner before it posts.',
            ],
            'manager_period_close' => [
                'subject' => ApprovalSubject::PeriodClose, 'takes_amount' => false, 'default_cents' => 0, 'steps' => 1,
                'min_role' => FinanceRole::FinanceManager, 'summary' => 'Closing a period is approved by a finance manager or owner.',
            ],
            'approver_budgets' => [
                'subject' => ApprovalSubject::Budget, 'takes_amount' => false, 'default_cents' => 0, 'steps' => 1,
                'min_role' => null, 'summary' => 'Every budget is approved by an approver before it takes effect.',
            ],
        ];
    }

    /** The policy name for a preset at an amount, e.g. "Owner approves bills over ZAR 5,000.00". */
    public static function name(string $key, ?int $thresholdCents, ?string $currency): string
    {
        $over = $thresholdCents !== null ? ' over '.Money::format($thresholdCents, $currency) : '';

        return match ($key) {
            'owner_bills' => "Owner approves bills{$over}",
            'approver_expenses' => "An approver reviews expense claims{$over}",
            'two_person_payments' => "Two people approve payments{$over}",
            'manager_invoices' => "A finance manager approves invoices{$over}",
            'manager_journals' => 'A finance manager approves every manual journal',
            'manager_period_close' => 'A finance manager approves every period close',
            'approver_budgets' => 'An approver approves every budget',
            default => 'Approval policy',
        };
    }
}
