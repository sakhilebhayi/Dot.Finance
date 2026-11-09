<?php

namespace App\Services\Accounting;

use App\Models\Shared\Payment;
use App\Models\Transaction;
use App\Support\Ledger\LedgerContext;
use Illuminate\Database\Query\Builder;

/**
 * The cash-ledger rows that are the bank leg of a document payment (a supplier payment, a customer receipt): the ONE place that
 * writes them, recognises them and removes them (R-44 integration, D-1206; Sales and Purchases had one mechanism each).
 *
 * Paying a bill, or receiving money for an invoice, writes the cash ledger's row (so the account balance moves) AND posts one
 * journal entry that already carries the bank line (Dr payable / Cr bank, or Dr bank / Cr receivable, plus any exchange
 * difference). If the cash-ledger row were journalled as an ordinary income or expense as well, the money would be counted
 * twice. So a row is a settlement row when
 *
 *   - it carries the marker `settlement_ref = "payment:<id>"` (written here, on the row, before it is first saved), or
 *   - a payment points at it (payments.transaction_id), which is the same fact seen from the payment and also covers a row
 *     written before the marker existed;
 *
 * and then the cash-ledger posting (PostingRules, GlSync, the sweeper, the backfill) leaves it alone, the transaction policy
 * refuses to edit or delete it (reverse the payment instead), and the bank module's locks apply as to any row.
 */
final class SettlementTransactions
{
    public const MARK = 'payment:';

    /** Write the cash-ledger row of a payment: income for money in, expense for money out, marked as a settlement row. */
    public static function write(Payment $payment, int $categoryId, string $description): Transaction
    {
        $transaction = new Transaction($payment->ledgerOwner() + [
            'account_id' => $payment->cash_account_id,
            'category_id' => $categoryId,
            'description' => mb_substr($description, 0, 255),
            'amount_cents' => (int) $payment->amount_cents,
            'currency' => $payment->currency,
            'type' => $payment->direction === 'in' ? 'income' : 'expense',
            'date' => $payment->paid_on->toDateString(),
            'notes' => $payment->reference,
        ]);
        $transaction->settlement_ref = self::MARK.$payment->id;
        $transaction->save();

        return $transaction;
    }

    /** Take the row back when its payment is reversed. It is soft-deleted, so the audit trail keeps it. */
    public static function remove(Payment $payment): void
    {
        if ($payment->transaction_id === null) {
            return;
        }

        Transaction::query()->forContext($payment->ledgerContext())->find($payment->transaction_id)?->delete();
    }

    /** The marker alone: enough to decide, without a query, that a row is not for the cash-ledger posting rules. */
    public static function marked(Transaction $transaction): bool
    {
        return $transaction->settlement_ref !== null;
    }

    public static function is(Transaction $transaction): bool
    {
        return self::marked($transaction)
            || Payment::query()->forContext($transaction->ledgerContext())->where('transaction_id', $transaction->getKey())->exists();
    }

    /** The ids of the settlement rows among $ids in this ledger (two small queries). @param list<int> $ids @return list<int> */
    public static function among(LedgerContext $context, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $marked = Transaction::query()->forContext($context)->whereIn('id', $ids)->whereNotNull('settlement_ref')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $linked = Payment::query()->forContext($context)->whereIn('transaction_id', $ids)->pluck('transaction_id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_unique([...$marked, ...$linked]));
    }

    /**
     * Leave the settlement rows out of a query on `transactions` (the sweeper's "should be posted but is not"): neither marked nor
     * pointed at by a payment.
     *
     * @param  Builder  $query
     */
    public static function exclude($query, string $alias = 't')
    {
        return $query->whereNull("{$alias}.settlement_ref")
            ->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('payments as p')->whereColumn('p.transaction_id', "{$alias}.id"));
    }
}
