<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GlAccount;
use App\Support\Ledger\LedgerContext;

/**
 * The trial balance as of a date: every account with its debit or credit balance, the totals, and the
 * accounting equation (assets = liabilities + equity + income - expenses). All integers.
 */
class TrialBalance
{
    public function __construct(private readonly GlQuery $query) {}

    /**
     * @return array{as_of: ?string, rows: list<array{account: GlAccount, debits: int, credits: int, debit_balance: int, credit_balance: int}>, total_debits: int, total_credits: int, balanced: bool, equation: array{assets: int, liabilities: int, equity: int, income: int, expenses: int, holds: bool}}
     */
    public function asOf(LedgerContext $context, ?string $date = null): array
    {
        $totals = $this->query->totals($context, null, $date);
        $accounts = GlAccount::query()->forContext($context)->orderBy('code')->get();

        $rows = [];
        $debits = 0;
        $credits = 0;
        $byClass = ['asset' => 0, 'liability' => 0, 'equity' => 0, 'income' => 0, 'expense' => 0];

        foreach ($accounts as $account) {
            $t = $totals->get($account->id);
            if ($t === null && ! $account->is_active) {
                continue;
            }

            $d = $t->debits ?? 0;
            $c = $t->credits ?? 0;
            $net = $d - $c;

            if ($t === null && ! $account->is_postable) {
                continue;
            }

            $rows[] = [
                'account' => $account, 'debits' => $d, 'credits' => $c,
                'debit_balance' => max(0, $net), 'credit_balance' => max(0, -$net),
            ];
            $debits += max(0, $net);
            $credits += max(0, -$net);
            $byClass[$account->class] += $net;
        }

        // Assets and expenses are debit-natured, the rest credit-natured; nets are debit minus credit.
        $equation = [
            'assets' => $byClass['asset'],
            'liabilities' => -$byClass['liability'],
            'equity' => -$byClass['equity'],
            'income' => -$byClass['income'],
            'expenses' => $byClass['expense'],
        ];
        $equation['holds'] = $equation['assets'] === $equation['liabilities'] + $equation['equity'] + $equation['income'] - $equation['expenses'];

        return [
            'as_of' => $date, 'rows' => $rows, 'total_debits' => $debits, 'total_credits' => $credits,
            'balanced' => $debits === $credits, 'equation' => $equation,
        ];
    }
}
