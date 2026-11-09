<?php

namespace App\Services\Bank;

use App\Exceptions\Bank\ReconciledTransactionLocked;
use App\Models\Transaction;

/**
 * The lock on cash-ledger rows the bank has vouched for. A row that is matched to a statement line (cleared) or whose
 * reconciliation is completed (reconciled) keeps the figures the bank agreed with: its account, amount, date, type and
 * transfer link cannot change and it cannot be deleted. What does not move a balance (the description, notes, category)
 * can still be corrected. To change a locked row, un-match it (a draft reconciliation) or reopen the reconciliation
 * (newest first, with a reason; both are recorded).
 *
 * Model hooks call this for a friendly message; a database trigger on both engines refuses the same changes from any
 * other path.
 */
final class ReconciliationLock
{
    /** The columns that decide what a row does to a balance. */
    public const BALANCING = ['account_id', 'amount_cents', 'date', 'type', 'transfer_direction', 'transfer_group', 'currency'];

    /** @throws ReconciledTransactionLocked */
    public static function guardUpdate(Transaction $transaction): void
    {
        if ($transaction->getOriginal('bank_match_id') === null && $transaction->getOriginal('reconciliation_id') === null) {
            return;
        }

        $changed = array_values(array_intersect(array_keys($transaction->getDirty()), self::BALANCING));

        if ($changed === []) {
            return;
        }

        throw self::refusal($transaction, 'changed ('.implode(', ', $changed).')');
    }

    /** @throws ReconciledTransactionLocked */
    public static function guardDelete(Transaction $transaction): void
    {
        if ($transaction->getOriginal('bank_match_id') === null && $transaction->getOriginal('reconciliation_id') === null && $transaction->bank_match_id === null && $transaction->reconciliation_id === null) {
            return;
        }

        throw self::refusal($transaction, 'deleted');
    }

    private static function refusal(Transaction $transaction, string $what): ReconciledTransactionLocked
    {
        $reconciled = ($transaction->getOriginal('reconciliation_id') ?? $transaction->reconciliation_id) !== null;

        return new ReconciledTransactionLocked('locked', $reconciled
            ? 'This entry was reconciled with a bank statement, so it cannot be '.$what.'. Reopen that reconciliation (newest first, with a reason) to change it.'
            : 'This entry is matched to a bank statement line, so it cannot be '.$what.'. Un-match it in the open bank reconciliation first.');
    }
}
