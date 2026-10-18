<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A transfer is two ledger legs written in one database transaction, linked by
 * a shared transfer_group: an 'out' leg on the source account and an 'in' leg on
 * the destination. The legs are excluded from income and spending everywhere;
 * they only move balances. Deleting either leg deletes both (see Transaction).
 */
class TransferService
{
    /**
     * @return array{out: Transaction, in: Transaction}
     *
     * @throws InvalidArgumentException when the request would not produce a coherent pair
     */
    public function create(User $user, Account $from, Account $to, int $cents, ?int $convertedCents, Carbon $date, string $description, ?string $notes): array
    {
        if ($from->is($to)) {
            throw new InvalidArgumentException('A transfer needs two different accounts.');
        }

        if ($from->user_id !== $user->id || $to->user_id !== $user->id) {
            throw new InvalidArgumentException('Both accounts must belong to the same user.');
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

        return DB::transaction(function () use ($user, $from, $to, $cents, $inCents, $date, $description, $notes, $group) {
            $out = Transaction::create([
                'account_id' => $from->id,
                'user_id' => $user->id,
                'description' => $description,
                'amount_cents' => $cents,
                'currency' => $from->currency,
                'type' => 'transfer',
                'transfer_direction' => 'out',
                'transfer_group' => $group,
                'date' => $date,
                'notes' => $notes,
            ]);

            $in = Transaction::create([
                'account_id' => $to->id,
                'user_id' => $user->id,
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
