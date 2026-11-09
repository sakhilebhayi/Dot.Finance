<?php

namespace App\Services\Sales;

use App\Models\Accounting\GlAccount;
use App\Models\Category;
use App\Models\Shared\Payment;
use App\Models\Transaction;
use App\Services\Accounting\Chart;
use App\Support\Ledger\LedgerContext;

/**
 * The cash-ledger side of a settlement. Money received from (or given back to) a customer must move the account
 * balance, which is derived from the cash-ledger `transactions` table, so each settlement writes exactly one
 * transaction row. That row is marked with settlement_ref ("payment:12") and is NOT posted by the generic
 * cash-ledger rules (PostingRules::skipReason): the settlement posts its own journal entry, with the same
 * cash account on the bank line, so the cash tie-out (cash ledger = journals, per account, to the cent) holds.
 *
 * The rows are filed under two categories, "Customer receipts" and "Customer refunds". Their GL account is
 * receivables, not income: the income was recognised when the invoice was issued.
 */
final class CashSettlement
{
    public const RECEIPTS = 'Customer receipts';

    public const REFUNDS = 'Customer refunds';

    public function __construct(private readonly Chart $chart) {}

    /** The cash-ledger row for a payment: income for money in, expense for money out. */
    public function record(Payment $payment, string $description): Transaction
    {
        $context = $payment->ledgerContext();
        $in = $payment->direction === 'in';
        $category = $this->category($context, $in);

        $transaction = new Transaction($payment->ledgerOwner() + [
            'account_id' => $payment->cash_account_id,
            'category_id' => $category->id,
            'description' => mb_substr($description, 0, 255),
            'amount_cents' => (int) $payment->amount_cents,
            'currency' => $payment->currency,
            'type' => $in ? 'income' : 'expense',
            'date' => $payment->paid_on->toDateString(),
            'notes' => $payment->reference,
        ]);
        $transaction->settlement_ref = 'payment:'.$payment->id;
        $transaction->save();

        return $transaction;
    }

    /** Remove the row when the settlement is reversed (it is soft-deleted, so the audit trail keeps it). */
    public function remove(Payment $payment): void
    {
        if ($payment->transaction_id === null) {
            return;
        }

        $transaction = Transaction::query()->forContext($payment->ledgerContext())->find($payment->transaction_id);
        $transaction?->delete();
    }

    private function category(LedgerContext $context, bool $in): Category
    {
        $name = $in ? self::RECEIPTS : self::REFUNDS;
        $category = Category::query()->forContext($context)->where('name', $name)->where('type', $in ? 'income' : 'expense')->first()
            ?? Category::query()->create($context->ownerAttributes() + ['name' => $name, 'type' => $in ? 'income' : 'expense', 'color' => '#4b5563']);

        if ($category->gl_account_id === null) {
            $ar = GlAccount::query()->forContext($context)->findOrFail($this->chart->systemIds($context)['ar']);
            $category->forceFill(['gl_account_id' => $ar->id])->saveQuietly();
        }

        return $category;
    }
}
