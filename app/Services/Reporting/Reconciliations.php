<?php

namespace App\Services\Reporting;

use App\Enums\ReportBasis;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\Accounting\GlQuery;
use App\Services\Accounting\TrialBalance;
use App\Support\Money;

/**
 * The reconciliation badges a report carries. Every check is computed live from the books by a second route (not by
 * re-reading the report's own numbers) and each says what it compared and, when it does not agree, by how much.
 *
 * Check shape: ['key', 'label', 'ok' => bool, 'difference_cents' => int, 'detail' => string].
 */
class Reconciliations
{
    public function __construct(private readonly GlQuery $query, private readonly TrialBalance $trialBalance) {}

    /** The statement's net result for the period agrees with income minus expense taken straight from the journals. */
    public function profitAndLoss(ReportRequest $r, int $incomeCents, int $expenseCents, string $from, string $to): array
    {
        if ($r->basis === ReportBasis::Cash) {
            $accrual = $this->query->incomeExpense($r->context, $from, $to);
            $gap = ($accrual['income_cents'] - $accrual['expense_cents']) - ($incomeCents - $expenseCents);

            return $this->check('ties_to_trial_balance', 'Ties to the trial balance', true, 0, 'Cash basis is not comparable to the trial balance line by line. Accrual net profit for the same period is '.Money::format($accrual['net_cents'], $r->currency()).'; the cash-basis figure is lower or higher by '.Money::format(abs($gap), $r->currency()).' (invoices and bills not yet paid, or paid from earlier periods).');
        }

        $journal = $this->query->incomeExpense($r->context, $from, $to);
        $diff = ($incomeCents - $expenseCents) - $journal['net_cents'];

        return $this->check('ties_to_trial_balance', 'Ties to the trial balance', $diff === 0, $diff, $diff === 0
            ? 'Net profit equals income minus expense from the posted journals.'
            : 'Net profit differs from the journals by '.Money::format(abs($diff), $r->currency()).'.');
    }

    /** Assets, liabilities and equity (earnings included) agree with the trial balance as at the same date, and A = L + E. */
    public function balanceSheet(ReportRequest $r, string $asOf, int $assets, int $liabilities, int $equity): array
    {
        $tb = $this->trialBalance->asOf($r->context, $asOf)['equation'];
        $tbEquity = $tb['equity'] + $tb['income'] - $tb['expenses'];
        $diff = abs($assets - $tb['assets']) + abs($liabilities - $tb['liabilities']) + abs($equity - $tbEquity);
        $equation = $assets - ($liabilities + $equity);

        return [
            $this->check('ties_to_trial_balance', 'Ties to the trial balance', $diff === 0, $diff, $diff === 0
                ? 'Assets, liabilities and equity equal the trial balance as at '.$asOf.'.'
                : 'Differs from the trial balance by '.Money::format($diff, $r->currency()).'.'),
            $this->check('assets_equal_liabilities_plus_equity', 'Assets equal liabilities plus equity', $equation === 0, $equation, $equation === 0
                ? 'Assets '.Money::format($assets, $r->currency()).' = liabilities '.Money::format($liabilities, $r->currency()).' + equity '.Money::format($equity, $r->currency()).'.'
                : 'Assets exceed liabilities plus equity by '.Money::format($equation, $r->currency()).'.'),
        ];
    }

    /** The ledger's cash and bank accounts agree with the cash accounts' own balances as at a date. */
    public function bank(ReportRequest $r, string $asOf): array
    {
        $start = $r->settings->accounting_start_date?->toDateString();
        if ($start !== null && $asOf < $start) {
            return $this->check('ties_to_bank_balances', 'Ties to bank balances', true, 0, 'Accounting started on '.$start.'; there is nothing to compare before then.');
        }

        $gl = $this->query->ownedLines($r->context)->whereNotNull('l.cash_account_id')->where('l.entry_date', '<=', $asOf)->groupBy('l.cash_account_id')
            ->selectRaw('l.cash_account_id, SUM(CASE WHEN l.txn_currency IS NOT NULL THEN CASE WHEN l.debit_cents > 0 THEN l.txn_amount_cents ELSE -l.txn_amount_cents END ELSE l.debit_cents - l.credit_cents END) AS net')
            ->pluck('net', 'cash_account_id');

        $moved = Transaction::query()->forContext($r->context)->counted()->where('date', '<=', $asOf)
            ->groupBy('account_id')->selectRaw('account_id, SUM('.Transaction::signedCentsSql('transactions').') AS net')->pluck('net', 'account_id');

        $accounts = Account::query()->forContext($r->context)->withTrashed()->get();
        $worst = 0;
        $off = [];
        foreach ($accounts as $account) {
            $opening = ($account->opening_balance_date === null || $account->opening_balance_date->toDateString() <= $asOf) ? (int) $account->opening_balance_cents : 0;
            $ledger = $opening + (int) ($moved[$account->id] ?? 0);
            $diff = $ledger - (int) ($gl[$account->id] ?? 0);
            if ($diff !== 0) {
                $off[] = $account->name.' ('.Money::format(abs($diff), $account->currency).')';
                $worst += abs($diff);
            }
        }

        return $this->check('ties_to_bank_balances', 'Ties to bank balances', $off === [], $worst, $off === []
            ? 'Every cash and bank account\'s balance as at '.$asOf.' equals what the ledger says (each in its own currency).'
            : 'These accounts differ from the ledger: '.implode(', ', $off).'.');
    }

    /** Debits equal credits in the trial balance. */
    public function trialBalance(ReportRequest $r, array $tb): array
    {
        $diff = $tb['total_debits'] - $tb['total_credits'];

        return $this->check('debits_equal_credits', 'Debits equal credits', $diff === 0, $diff, $diff === 0 ? 'Total debits equal total credits.' : 'Debits and credits differ by '.Money::format(abs($diff), $r->currency()).'.');
    }

    /** @return array{key: string, label: string, ok: bool, difference_cents: int, detail: string} */
    public function check(string $key, string $label, bool $ok, int $difference, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'difference_cents' => $difference, 'detail' => $detail];
    }
}
