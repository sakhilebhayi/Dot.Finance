<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger\LedgerContextViolation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A transfer is two ledger legs written in one database transaction, linked by
 * a shared transfer_group: an 'out' leg on the source account and an 'in' leg on
 * the destination. The legs are excluded from income and spending everywhere;
 * they only move balances. Deleting either leg deletes both (see Transaction).
 *
 * Both accounts must belong to the same ledger (a person's or one company's): a transfer
 * never crosses from personal into a company, between companies, or between people.
 */
class TransferService
{
    /**
     * @param  User  $user  the person recording the transfer (the audit log takes the acting person from the session)
     * @return array{out: Transaction, in: Transaction}
     *
     * @throws InvalidArgumentException when the request would not produce a coherent pair
     * @throws LedgerContextViolation when the accounts belong to different ledgers
     */
    public function create(User $user, Account $from, Account $to, int $cents, ?int $convertedCents, Carbon $date, string $description, ?string $notes): array
    {
        if ($from->is($to)) {
            throw new InvalidArgumentException('A transfer needs two different accounts.');
        }

        if (! $from->ledgerContext()->equals($to->ledgerContext())) {
            throw new LedgerContextViolation('A transfer must stay inside one ledger: both accounts must belong to the same person or the same company.');
        }

        if ($cents <= 0) {
            throw new InvalidArgumentException('A transfer amount must be positive.');
        }

        $sameCurrency = $from->currency === $to->currency;

        if ($sameCurrency && $convertedCents !== null && $convertedCents !== $cents) {
            throw new InvalidArgumentException('Accounts share a currency; there is nothing to convert.');
        }

        if (! $sameCurrency && ($convertedCents === null || $convertedCents <= 0)) {
            throw new InvalidArgumentException('Accounts in different currencies need an explicit converted amount.');
        }

        $inCents = $sameCurrency ? $cents : $convertedCents;
        $group = (string) Str::uuid();
        $owner = $from->ledgerOwner();

        return DB::transaction(function () use ($owner, $from, $to, $cents, $inCents, $date, $description, $notes, $group) {
            $out = Transaction::create($owner + [
                'account_id' => $from->id,
                'description' => $description,
                'amount_cents' => $cents,
                'currency' => $from->currency,
                'type' => 'transfer',
                'transfer_direction' => 'out',
                'transfer_group' => $group,
                'date' => $date,
                'notes' => $notes,
            ]);

            $in = Transaction::create($owner + [
                'account_id' => $to->id,
                'description' => $description,
                'amount_cents' => $inCents,
                'currency' => $to->currency,
                'type' => 'transfer',
                'transfer_direction' => 'in',
                'transfer_group' => $group,
                'date' => $date,
                'notes' => $notes,
            ]);

            return ['out' => $out, 'in' => $in];
        });
    }
}
