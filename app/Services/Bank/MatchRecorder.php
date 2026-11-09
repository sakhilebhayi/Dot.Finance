<?php

namespace App\Services\Bank;

use App\Models\Bank\BankMatch;
use App\Models\Bank\BankReconciliation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes one match and links its rows. Callers have already checked the rows and hold the session lock; this class only
 * records, so the manual matcher, the entry-from-line actions and the adjustments all leave the same trace.
 */
class MatchRecorder
{
    use RecordsReconciliationHistory;

    /**
     * Write one match and link its rows. The caller has already checked the rows and holds the session lock.
     *
     * @param  list<int>  $lineIds
     * @param  list<int>  $entryIds
     */
    public function record(BankReconciliation $locked, array $lineIds, array $entryIds, int $amountCents, ?User $user, string $method, ?int $confidence, string $reason): BankMatch
    {
        $shape = match (true) {
            $lineIds === [] => 'ledger_only',
            count($lineIds) === 1 && count($entryIds) === 1 => 'one_to_one',
            count($lineIds) === 1 => 'one_to_many',
            count($entryIds) === 1 => 'many_to_one',
            default => 'many_to_many',
        };

        $match = BankMatch::create($locked->ledgerOwner() + [
            'account_id' => $locked->account_id,
            'bank_reconciliation_id' => $locked->id,
            'ref' => (string) Str::uuid(),
            'method' => $method,
            'shape' => $shape,
            'confidence' => $confidence,
            'reason' => mb_substr($reason, 0, 500),
            'amount_cents' => $amountCents,
            'line_count' => count($lineIds),
            'transaction_count' => count($entryIds),
            'state' => 'active',
            'matched_by' => $user?->id,
            'matched_at' => now(),
        ]);

        if ($lineIds !== []) {
            DB::table('bank_statement_lines')->whereIn('id', $lineIds)->update(['bank_match_id' => $match->id]);
        }
        DB::table('transactions')->whereIn('id', $entryIds)->update(['bank_match_id' => $match->id, 'cleared_at' => now()]);

        $this->event($locked, 'matched', $user, ['match_id' => $match->id, 'method' => $method, 'shape' => $shape, 'line_ids' => $lineIds, 'entry_ids' => $entryIds, 'amount_cents' => $amountCents, 'confidence' => $confidence]);
        $this->audit($locked, 'matched', ['match_id' => ['after' => $match->id], 'method' => ['after' => $method], 'amount_cents' => ['after' => $amountCents]], $user);

        return $match;
    }
}
