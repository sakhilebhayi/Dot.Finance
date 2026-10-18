<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use App\Support\Money;

/**
 * Re-derives every account balance a second, independent way and checks the
 * ledger's structural invariants, so a corrupt row shows up as a named finding
 * instead of as a quietly wrong total.
 *
 * Balances are derived live in SQL (Account::withBalance), so they cannot
 * "drift" from a stored number the way the old typed balance did. What can go
 * wrong is the data underneath: a transfer with one leg, a transaction whose
 * currency disagrees with its account, an amount whose cents and legacy decimal
 * mirror disagree, a legacy row nobody backfilled. This checks all of those, and
 * recomputes each balance in PHP from the raw rows to confirm the SQL expression
 * itself.
 */
class LedgerReconciler
{
    /**
     * @return array{accounts: list<array<string, mixed>>, findings: list<array{severity: string, code: string, message: string}>}
     */
    public function run(?int $userId = null): array
    {
        $accountsQuery = Account::withoutGlobalScopes()->withBalance()->orderBy('user_id')->orderBy('name');
        if ($userId !== null) {
            $accountsQuery->where('user_id', $userId);
        }
        $accounts = $accountsQuery->get();

        $findings = [];
        $summary = [];

        foreach ($accounts as $account) {
            $rows = Transaction::withoutGlobalScopes()->where('account_id', $account->id)->get();

            $recomputed = $account->opening_balance_cents;
            $counted = 0;
            foreach ($rows as $row) {
                if ($account->opening_balance_date !== null && $row->date->lt($account->opening_balance_date)) {
                    continue;
                }
                $recomputed += $row->signedCents();
                $counted++;
            }

            $derived = $account->balanceCents();
            $summary[] = [
                'account_id' => $account->id,
                'user_id' => $account->user_id,
                'name' => $account->name,
                'currency' => $account->currency,
                'opening_cents' => $account->opening_balance_cents,
                'opening_date' => $account->opening_balance_date?->toDateString(),
                'transactions_counted' => $counted,
                'derived_cents' => $derived,
                'recomputed_cents' => $recomputed,
                'drift_cents' => $derived - $recomputed,
            ];

            if ($derived !== $recomputed) {
                $findings[] = $this->finding('error', 'balance_drift', sprintf(
                    'Account "%s": SQL balance %s does not match independent recompute %s.',
                    $account->name, Money::format($derived, $account->currency), Money::format($recomputed, $account->currency)
                ));
            }

            foreach ($rows as $row) {
                if ($row->currency !== $account->currency) {
                    $findings[] = $this->finding('error', 'currency_mismatch', sprintf(
                        'Transaction #%d is %s but its account "%s" is %s.', $row->id, $row->currency ?? 'unset', $account->name, $account->currency
                    ));
                }

                if (Money::fromDecimal($row->getRawOriginal('amount')) !== $row->amount_cents) {
                    $findings[] = $this->finding(
                        'error',
                        $row->amount_cents === 0 ? 'unmigrated_legacy_row' : 'amount_mirror_mismatch',
                        $row->amount_cents === 0
                            ? sprintf('Transaction #%d still has no cents value (run finance:backfill-money).', $row->id)
                            : sprintf('Transaction #%d: amount_cents %d disagrees with the legacy decimal %s.', $row->id, $row->amount_cents, $row->getRawOriginal('amount'))
                    );
                }

                if ($row->type === 'transfer' && $row->transfer_group === null) {
                    $findings[] = $this->finding('info', 'legacy_transfer_without_destination', sprintf(
                        'Transaction #%d is a legacy transfer with no destination account; it has never moved a balance and is not counted.', $row->id
                    ));
                }
            }
        }

        return ['accounts' => $summary, 'findings' => array_merge($findings, $this->transferFindings($userId))];
    }

    /** @return list<array{severity: string, code: string, message: string}> */
    private function transferFindings(?int $userId): array
    {
        $query = Transaction::withoutGlobalScopes()->whereNotNull('transfer_group');
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $findings = [];
        foreach ($query->get()->groupBy('transfer_group') as $group => $legs) {
            $out = $legs->where('transfer_direction', 'out');
            $in = $legs->where('transfer_direction', 'in');

            if ($legs->count() !== 2 || $out->count() !== 1 || $in->count() !== 1) {
                $findings[] = $this->finding('error', 'orphan_transfer_leg', sprintf(
                    'Transfer %s has %d leg(s); a transfer must have exactly one out and one in.', $group, $legs->count()
                ));

                continue;
            }

            $outLeg = $out->first();
            $inLeg = $in->first();

            if ($outLeg->currency === $inLeg->currency && $outLeg->amount_cents !== $inLeg->amount_cents) {
                $findings[] = $this->finding('error', 'transfer_amount_mismatch', sprintf(
                    'Transfer %s moves %d out but %d in within one currency.', $group, $outLeg->amount_cents, $inLeg->amount_cents
                ));
            }

            if ($outLeg->account_id === $inLeg->account_id) {
                $findings[] = $this->finding('error', 'transfer_same_account', sprintf('Transfer %s starts and ends in the same account.', $group));
            }
        }

        return $findings;
    }

    private function finding(string $severity, string $code, string $message): array
    {
        return ['severity' => $severity, 'code' => $code, 'message' => $message];
    }

    /** Findings that make the check fail (info findings are reported but pass). */
    public static function problems(array $report): array
    {
        return array_values(array_filter($report['findings'], fn (array $f) => $f['severity'] === 'error'));
    }
}
