<?php

namespace App\Services\Bank;

use App\Exceptions\Bank\ReconciliationRefused;
use App\Models\Account;
use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankStatementLine;
use App\Models\Shared\Payment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds and records matches between a session's statement lines and the account's cash-ledger rows.
 *
 * suggest() reads the open lines and the rows around them in a handful of queries (never one per line), runs the pure
 * MatchEngine and returns proposals with the evidence for each. Nothing is written. accept() writes proposals: it
 * re-reads every line and row under lock, refuses any proposal that is no longer true (already matched, totals differ),
 * and records the rest with chunked inserts and updates, so five thousand lines cost tens of queries, not thousands.
 */
class MatchingService
{
    use RecordsReconciliationHistory;

    public const WINDOW_DAYS = 7;

    private const READ_CHUNK = 900;

    private const WRITE_CHUNK = 300;

    /** Statement lines of a session that are still open: on or before the statement date, not matched, not reconciled. */
    public function openLines(BankReconciliation $session): Collection
    {
        return BankStatementLine::query()->forContext($session->ledgerContext())
            ->where('account_id', $session->account_id)
            ->where('posted_on', '<=', $session->statement_date->toDateString())
            ->whereNull('bank_match_id')->whereNull('reconciliation_id')
            ->orderBy('posted_on')->orderBy('id')
            ->toBase()->get(['id', 'posted_on', 'amount_cents', 'description', 'reference', 'bank_statement_id', 'line_no']);
    }

    /**
     * Cash-ledger rows that could match: not yet matched, counted, a real movement, dated around the lines.
     *
     * @param  Collection<int, object>  $lines
     */
    public function candidateEntries(Account $account, Collection $lines): Collection
    {
        if ($lines->isEmpty()) {
            return collect();
        }

        $first = $lines->min(fn ($l) => substr((string) $l->posted_on, 0, 10));
        $last = $lines->max(fn ($l) => substr((string) $l->posted_on, 0, 10));
        $signed = Transaction::signedCentsSql('transactions');

        return Transaction::query()->forContext($account->ledgerContext())->counted()
            ->where('account_id', $account->id)->whereNull('bank_match_id')->whereNull('reconciliation_id')
            ->whereBetween('date', [date('Y-m-d', strtotime($first.' -'.self::WINDOW_DAYS.' days')), date('Y-m-d', strtotime($last.' +'.self::WINDOW_DAYS.' days'))])
            ->whereRaw("({$signed}) <> 0")
            ->orderBy('date')->orderBy('id')
            ->toBase()->selectRaw("transactions.id, transactions.date, transactions.description, transactions.type, transactions.amount_cents, transactions.transfer_group, ({$signed}) AS signed")
            ->get();
    }

    /**
     * @return array{proposals: list<array<string, mixed>>, auto: list<array<string, mixed>>, review: list<array<string, mixed>>, lines: array<int, object>, entries: array<int, object>}
     */
    public function suggest(BankReconciliation $session): array
    {
        $account = Account::query()->forContext($session->ledgerContext())->withTrashed()->findOrFail($session->account_id);
        $lines = $this->openLines($session);
        $entries = $this->candidateEntries($account, $lines);

        $paymentRefs = $this->paymentReferences($account, $entries->pluck('id')->all());

        $engineLines = $lines->map(function ($l) {
            $t = MatchText::prepare($l->description, $l->reference);

            return ['id' => (int) $l->id, 'day' => MatchText::day(substr((string) $l->posted_on, 0, 10)), 'cents' => (int) $l->amount_cents] + $t;
        })->values()->all();

        $engineEntries = $entries->map(function ($e) use ($paymentRefs) {
            $t = MatchText::prepare($e->description);

            return ['id' => (int) $e->id, 'day' => MatchText::day(substr((string) $e->date, 0, 10)), 'cents' => (int) $e->signed, 'type' => (string) $e->type, 'payment_ref' => $paymentRefs[(int) $e->id] ?? null] + $t;
        })->values()->all();

        $proposals = (new MatchEngine(self::WINDOW_DAYS))->propose($engineLines, $engineEntries, $session->currency);

        return [
            'proposals' => $proposals,
            'auto' => array_values(array_filter($proposals, fn ($p) => $p['confidence'] >= MatchEngine::AUTO && $p['shape'] === 'one_to_one')),
            'review' => array_values(array_filter($proposals, fn ($p) => $p['confidence'] < MatchEngine::AUTO || $p['shape'] !== 'one_to_one')),
            'lines' => $lines->keyBy('id')->all(),
            'entries' => $entries->keyBy('id')->all(),
        ];
    }

    /**
     * The reference of a payment recorded against a cash row, when there is one (a payment the books already know about).
     *
     * @param  list<int>  $entryIds
     * @return array<int, string>
     */
    private function paymentReferences(Account $account, array $entryIds): array
    {
        $refs = [];

        foreach (array_chunk($entryIds, self::READ_CHUNK) as $chunk) {
            $rows = Payment::query()->forContext($account->ledgerContext())->where('cash_account_id', $account->id)
                ->where('status', 'recorded')->whereIn('transaction_id', $chunk)->whereNotNull('reference')
                ->toBase()->get(['transaction_id', 'reference']);

            foreach ($rows as $row) {
                $refs[(int) $row->transaction_id] = (string) $row->reference;
            }
        }

        return $refs;
    }

    /**
     * Record proposals as matches in $session. Each is re-checked first; the ones no longer true are skipped and counted.
     *
     * @param  list<array{line_ids: list<int>, entry_ids: list<int>, shape: string, confidence: int, reason: string, amount_cents: int}>  $proposals
     * @return array{accepted: int, skipped: int}
     */
    public function accept(BankReconciliation $session, array $proposals, User $user, string $method = 'auto'): array
    {
        if ($proposals === []) {
            return ['accepted' => 0, 'skipped' => 0];
        }

        return DB::transaction(function () use ($session, $proposals, $user, $method) {
            $context = $session->ledgerContext();
            $locked = BankReconciliation::query()->forContext($context)->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new ReconciliationRefused('not_draft', 'This reconciliation is completed. Reopen it to change the matches.');
            }

            $lineIds = array_values(array_unique(array_merge(...array_map(fn ($p) => $p['line_ids'], $proposals))));
            $entryIds = array_values(array_unique(array_merge(...array_map(fn ($p) => $p['entry_ids'], $proposals))));

            $lines = collect();
            foreach (array_chunk($lineIds, self::READ_CHUNK) as $chunk) {
                $lines = $lines->concat(BankStatementLine::query()->forContext($context)->where('account_id', $locked->account_id)
                    ->whereIn('id', $chunk)->whereNull('bank_match_id')->whereNull('reconciliation_id')
                    ->where('posted_on', '<=', $locked->statement_date->toDateString())
                    ->toBase()->lockForUpdate()->get(['id', 'amount_cents']));
            }
            $lines = $lines->keyBy('id');

            $signed = Transaction::signedCentsSql('transactions');
            $entries = collect();
            foreach (array_chunk($entryIds, self::READ_CHUNK) as $chunk) {
                $entries = $entries->concat(Transaction::query()->forContext($context)->counted()->where('account_id', $locked->account_id)
                    ->whereIn('id', $chunk)->whereNull('bank_match_id')->whereNull('reconciliation_id')
                    ->toBase()->lockForUpdate()->selectRaw("transactions.id, ({$signed}) AS signed")->get());
            }
            $entries = $entries->keyBy('id');

            $valid = [];
            $skipped = 0;
            $seenLines = [];
            $seenEntries = [];

            foreach ($proposals as $proposal) {
                $lineTotal = 0;
                $entryTotal = 0;
                $ok = $proposal['line_ids'] !== [] && $proposal['entry_ids'] !== [];

                foreach ($proposal['line_ids'] as $id) {
                    $ok = $ok && $lines->has($id) && ! isset($seenLines[$id]);
                    $lineTotal += (int) ($lines->get($id)->amount_cents ?? 0);
                }
                foreach ($proposal['entry_ids'] as $id) {
                    $ok = $ok && $entries->has($id) && ! isset($seenEntries[$id]);
                    $entryTotal += (int) ($entries->get($id)->signed ?? 0);
                }

                if (! $ok || $lineTotal !== $entryTotal || $lineTotal === 0) {
                    $skipped++;

                    continue;
                }

                foreach ($proposal['line_ids'] as $id) {
                    $seenLines[$id] = true;
                }
                foreach ($proposal['entry_ids'] as $id) {
                    $seenEntries[$id] = true;
                }
                $valid[] = $proposal + ['ref' => (string) Str::uuid()];
            }

            if ($valid === []) {
                return ['accepted' => 0, 'skipped' => $skipped];
            }

            $now = now()->toDateTimeString();
            $owner = $locked->ledgerOwner();

            foreach (array_chunk($valid, self::WRITE_CHUNK) as $chunk) {
                DB::table('bank_matches')->insert(array_map(fn ($p) => $owner + [
                    'account_id' => $locked->account_id,
                    'bank_reconciliation_id' => $locked->id,
                    'ref' => $p['ref'],
                    'method' => $method,
                    'shape' => $p['shape'],
                    'confidence' => $p['confidence'],
                    'reason' => mb_substr($p['reason'], 0, 500),
                    'amount_cents' => $p['amount_cents'],
                    'line_count' => count($p['line_ids']),
                    'transaction_count' => count($p['entry_ids']),
                    'state' => 'active',
                    'matched_by' => $user->id,
                    'matched_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            $ids = [];
            foreach (array_chunk(array_column($valid, 'ref'), self::READ_CHUNK) as $chunk) {
                $ids += DB::table('bank_matches')->whereIn('ref', $chunk)->pluck('id', 'ref')->all();
            }

            $lineLinks = [];
            $entryLinks = [];
            foreach ($valid as $p) {
                foreach ($p['line_ids'] as $id) {
                    $lineLinks[$id] = (int) $ids[$p['ref']];
                }
                foreach ($p['entry_ids'] as $id) {
                    $entryLinks[$id] = (int) $ids[$p['ref']];
                }
            }

            $this->link('bank_statement_lines', $lineLinks, null);
            $this->link('transactions', $entryLinks, $now);

            $this->event($locked, 'matched_in_bulk', $user, ['method' => $method, 'matches' => count($valid), 'skipped' => $skipped, 'lines' => count($lineLinks), 'entries' => count($entryLinks)]);
            $this->audit($locked, 'matched', ['method' => ['after' => $method], 'matches' => ['after' => count($valid)], 'lines' => ['after' => count($lineLinks)]], $user);

            return ['accepted' => count($valid), 'skipped' => $skipped];
        });
    }

    /**
     * Point rows at their matches with one CASE update per chunk (bank_match_id, and cleared_at on the cash-ledger side).
     *
     * @param  array<int, int>  $links  row id => match id
     */
    private function link(string $table, array $links, ?string $clearedAt): void
    {
        foreach (array_chunk($links, self::WRITE_CHUNK, true) as $chunk) {
            $case = 'CASE id'.str_repeat(' WHEN CAST(? AS BIGINT) THEN CAST(? AS BIGINT)', count($chunk)).' END';
            $bindings = [];
            foreach ($chunk as $id => $matchId) {
                $bindings[] = $id;
                $bindings[] = $matchId;
            }

            $sets = "bank_match_id = {$case}";
            if ($clearedAt !== null) {
                $sets .= DB::getDriverName() === 'pgsql' ? ', cleared_at = CAST(? AS TIMESTAMP)' : ', cleared_at = ?';
                $bindings[] = $clearedAt;
            }

            $ids = array_keys($chunk);
            DB::update("UPDATE {$table} SET {$sets} WHERE id IN (".implode(',', array_fill(0, count($ids), '?')).')', [...$bindings, ...$ids]);
        }
    }
}
