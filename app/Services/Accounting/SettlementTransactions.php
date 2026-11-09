<?php

namespace App\Services\Accounting;

use App\Models\Shared\Payment;
use App\Models\Transaction;
use App\Support\Ledger\LedgerContext;

/**
 * The cash-ledger rows that are the bank leg of a document payment (a supplier payment, a customer receipt).
 *
 * Paying a bill writes the cash ledger's row AND posts one journal entry that already carries the bank line (Dr payable,
 * Cr bank, plus any exchange difference). If the cash-ledger row were journalled as an ordinary expense as well, the money would
 * be counted twice. So the cash-ledger posting (GlSync, the sweeper, the backfill) leaves a row alone when a payment points at
 * it (payments.transaction_id), and the transaction policy refuses to edit or delete it: reverse the payment instead.
 */
final class SettlementTransactions
{
    public static function is(Transaction $transaction): bool
    {
        return Payment::query()->forContext($transaction->ledgerContext())->where('transaction_id', $transaction->getKey())->exists();
    }

    /** The ids of the settlement rows among $ids in this ledger (one query). @param list<int> $ids @return list<int> */
    public static function among(LedgerContext $context, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Payment::query()->forContext($context)->whereIn('transaction_id', $ids)->pluck('transaction_id')->map(fn ($id) => (int) $id)->all();
    }
}
