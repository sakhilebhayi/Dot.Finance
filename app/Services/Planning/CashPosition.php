<?php

namespace App\Services\Planning;

use App\Models\Account;
use App\Models\Accounting\LedgerSetting;
use App\Support\Ledger\LedgerContext;

/** What cash the business has right now, from the cash ledger: liquid accounts (checking, savings, cash) in the base currency. */
class CashPosition
{
    public const LIQUID = ['checking', 'savings', 'cash'];

    /**
     * @return array{base_currency: string, accounts: list<array{id: int, name: string, type: string, currency: string, balance_cents: int, counted: bool}>, available_cents: int, other: list<array{currency: string, balance_cents: int}>, owed_cents: int, notes: list<string>}
     */
    public function now(LedgerContext $context, LedgerSetting $settings): array
    {
        $base = $settings->base_currency;
        $available = 0;
        $owed = 0;
        $foreign = [];
        $accounts = [];

        foreach (Account::query()->forContext($context)->withBalance()->orderBy('name')->get() as $account) {
            $liquid = in_array($account->type, self::LIQUID, true);
            $counted = $liquid && $account->currency === $base;
            $balance = $account->balanceCents();
            $accounts[] = ['id' => $account->id, 'name' => $account->name, 'type' => $account->type, 'currency' => $account->currency, 'balance_cents' => $balance, 'counted' => $counted];

            if ($counted) {
                $available += $balance;
            } elseif ($liquid) {
                $foreign[$account->currency] = ($foreign[$account->currency] ?? 0) + $balance;
            } elseif ($account->type === 'credit' && $account->currency === $base) {
                $owed += $balance;
            }
        }

        $notes = [];
        if ($foreign !== []) {
            $notes[] = 'Accounts in other currencies ('.implode(', ', array_keys($foreign)).') are listed but not added to the available cash or the projection: the projection is in '.$base.' and does not guess an exchange rate.';
        }
        $notes[] = 'Investment accounts are not cash. A credit account\'s balance is shown as owed and is not netted against cash.';

        return [
            'base_currency' => $base, 'accounts' => $accounts, 'available_cents' => $available,
            'other' => array_map(fn ($c, $v) => ['currency' => $c, 'balance_cents' => $v], array_keys($foreign), array_values($foreign)),
            'owed_cents' => $owed, 'notes' => $notes,
        ];
    }
}
