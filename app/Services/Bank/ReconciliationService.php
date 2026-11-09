<?php

namespace App\Services\Bank;

use App\Enums\Capability;
use App\Exceptions\Bank\ReconciliationRefused;
use App\Models\Account;
use App\Models\Bank\BankMatch;
use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankStatement;
use App\Models\Bank\BankStatementLine;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger\LedgerAccess;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * A reconciliation session's life: start, match and un-match, complete, reopen. Every state change runs in one database
 * transaction under a lock on the session, leaves a row in the session's append-only event log and in the audit log, and
 * is refused with a stable reason code (ReconciliationRefused) when a rule says no.
 *
 * The rule that matters most: a session completes only when the statement's closing balance equals the ledger's cleared
 * balance to the cent. A difference is shown, never hidden, and the only way past it is an authorised variance entry with a
 * written reason (accountant-level capability), which is itself a real ledger entry, posted through the normal path.
 */
class ReconciliationService
{
    use RecordsReconciliationHistory;

    public function __construct(
        private readonly ReconciliationBalances $balances,
        private readonly AdjustmentService $adjustments,
        private readonly ReconciliationReport $report,
        private readonly MatchRecorder $recorder,
    ) {}

    /**
     * Open a session for $account up to $statementDate. The opening balance defaults to the closing balance of the account's
     * last completed reconciliation; a statement can supply both balances.
     */
    public function start(User $user, Account $account, string $statementDate, int $closingCents, ?int $openingCents = null, ?BankStatement $statement = null): BankReconciliation
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $statementDate) || ! checkdate((int) substr($statementDate, 5, 2), (int) substr($statementDate, 8, 2), (int) substr($statementDate, 0, 4))) {
            throw new ReconciliationRefused('bad_date', 'The statement date is not a valid date.');
        }
        if ($statementDate > now()->toDateString()) {
            throw new ReconciliationRefused('bad_date', 'The statement date is in the future.');
        }
        if (abs($closingCents) > Money::MAX_CENTS) {
            throw new ReconciliationRefused('invalid', 'That closing balance is too large.');
        }
        if ($statement !== null && ($statement->account_id !== $account->id || ! $statement->ledgerContext()->equals($account->ledgerContext()))) {
            throw new ReconciliationRefused('not_in_ledger', 'That statement belongs to a different account.');
        }

        return DB::transaction(function () use ($user, $account, $statementDate, $closingCents, $openingCents, $statement) {
            $context = $account->ledgerContext();
            $locked = Account::query()->forContext($context)->whereKey($account->id)->lockForUpdate()->firstOrFail();

            if ($locked->trashed()) {
                throw new ReconciliationRefused('not_in_ledger', 'That account has been deleted.');
            }
            if (BankReconciliation::query()->forContext($context)->where('account_id', $locked->id)->where('status', 'draft')->exists()) {
                throw new ReconciliationRefused('already_open', 'This account already has a reconciliation in progress. Finish it, or leave it, before starting another.');
            }

            $last = BankReconciliation::query()->forContext($context)->where('account_id', $locked->id)->where('status', 'completed')->orderByDesc('statement_date')->first();
            if ($last !== null && $statementDate <= $last->statement_date->toDateString()) {
                throw new ReconciliationRefused('bad_date', 'This account was already reconciled to '.$last->statement_date->toDateString().'. A new reconciliation must end after that date.');
            }

            $openingCents ??= $last?->statement_closing_cents ?? $statement?->opening_balance_cents;

            // The first reconciliation of an account has no earlier one to start from: entries before the statement are taken as
            // already agreed, and the opening-balance check says whether they are. Later sessions start from what the last one cleared.
            $baseline = null;
            if ($last === null) {
                $firstLine = $statement?->date_from?->toDateString()
                    ?? BankStatementLine::query()->forContext($context)->where('account_id', $locked->id)->where('posted_on', '<=', $statementDate)->min('posted_on');
                $baseline = $firstLine !== null ? substr((string) $firstLine, 0, 10) : date('Y-m-d', strtotime($statementDate.' +1 day'));
            }

            $session = BankReconciliation::create($locked->ledgerOwner() + [
                'account_id' => $locked->id,
                'bank_statement_id' => $statement?->id,
                'status' => 'draft',
                'statement_date' => $statementDate,
                'baseline_date' => $baseline,
                'statement_opening_cents' => $openingCents,
                'statement_closing_cents' => $closingCents,
                'currency' => $locked->currency,
                'started_by' => $user->id,
                'started_at' => now(),
            ]);

            $this->event($session, 'started', $user, ['statement_date' => $statementDate, 'closing_cents' => $closingCents, 'opening_cents' => $openingCents, 'statement_id' => $statement?->id]);
            $this->audit($session, 'started', ['statement_date' => ['after' => $statementDate], 'closing_cents' => ['after' => $closingCents]], $user);

            return $session;
        });
    }

    /**
     * Match statement lines and cash-ledger rows by hand: one to one, one line to several rows, or several lines to one row.
     * The two sides must add up to the same amount; if they do not the person is told by how much.
     *
     * @param  list<int>  $lineIds
     * @param  list<int>  $entryIds
     */
    public function matchRows(BankReconciliation $session, array $lineIds, array $entryIds, User $user, string $method = 'manual', ?int $confidence = null, ?string $reason = null): BankMatch
    {
        $lineIds = array_values(array_unique(array_map('intval', $lineIds)));
        $entryIds = array_values(array_unique(array_map('intval', $entryIds)));

        if ($lineIds === [] || $entryIds === []) {
            throw new ReconciliationRefused('nothing_to_match', 'Pick at least one statement line and at least one ledger entry to match together.');
        }
        if (count($lineIds) > 50 || count($entryIds) > 50) {
            throw new ReconciliationRefused('too_many', 'A single match can hold at most 50 lines and 50 entries.');
        }

        return DB::transaction(function () use ($session, $lineIds, $entryIds, $user, $method, $confidence, $reason) {
            $locked = $this->lockDraft($session);
            $context = $locked->ledgerContext();

            $lines = BankStatementLine::query()->forContext($context)->where('account_id', $locked->account_id)->whereIn('id', $lineIds)->lockForUpdate()->get();
            $entries = Transaction::query()->forContext($context)->counted()->where('account_id', $locked->account_id)->whereIn('id', $entryIds)->lockForUpdate()->get();

            if ($lines->count() !== count($lineIds) || $entries->count() !== count($entryIds)) {
                throw new ReconciliationRefused('not_in_ledger', 'Some of those lines or entries are not in this account (or are dated before its opening balance, so they do not count).');
            }
            if ($lines->contains(fn ($l) => $l->bank_match_id !== null || $l->reconciliation_id !== null) || $entries->contains(fn ($e) => $e->bank_match_id !== null || $e->reconciliation_id !== null)) {
                throw new ReconciliationRefused('already_matched', 'Some of those are already matched. Un-match them first.');
            }
            if ($lines->contains(fn ($l) => $l->posted_on->toDateString() > $locked->statement_date->toDateString())) {
                throw new ReconciliationRefused('out_of_scope', 'Some of those lines are dated after this reconciliation\'s statement date.');
            }
            if ($entries->contains(fn (Transaction $e) => $e->signedCents() === 0)) {
                throw new ReconciliationRefused('invalid', 'A legacy transfer with no destination never moved a balance and cannot be matched.');
            }

            $lineTotal = (int) $lines->sum('amount_cents');
            $entryTotal = (int) $entries->sum(fn (Transaction $e) => $e->signedCents());

            if ($lineTotal !== $entryTotal) {
                throw new ReconciliationRefused('totals_differ', 'The statement lines add up to '.Money::format($lineTotal, $locked->currency).' but the ledger entries add up to '.Money::format($entryTotal, $locked->currency).' (a difference of '.Money::format(abs($lineTotal - $entryTotal), $locked->currency).'). A match needs both sides to agree.');
            }

            return $this->recorder->record($locked, $lines->pluck('id')->all(), $entries->pluck('id')->all(), $lineTotal, $user, $method, $confidence, $reason ?? 'Matched by hand: you said these say the same thing.');
        });
    }

    /** Un-clear: release a match while the session is still open. The match row stays, marked released, with who and why. */
    public function unmatch(BankMatch $match, User $user, ?string $reason = null): BankMatch
    {
        return DB::transaction(function () use ($match, $user, $reason) {
            $session = $this->lockDraft($match->reconciliation()->firstOrFail());

            $fresh = BankMatch::query()->forContext($session->ledgerContext())->whereKey($match->id)->lockForUpdate()->firstOrFail();

            if (! $fresh->isActive()) {
                throw new ReconciliationRefused('not_draft', 'That match has already been released.');
            }

            $lineIds = DB::table('bank_statement_lines')->where('bank_match_id', $fresh->id)->pluck('id')->all();
            $entryIds = DB::table('transactions')->where('bank_match_id', $fresh->id)->pluck('id')->all();

            if ($lineIds !== []) {
                DB::table('bank_statement_lines')->whereIn('id', $lineIds)->update(['bank_match_id' => null]);
            }
            DB::table('transactions')->whereIn('id', $entryIds)->update(['bank_match_id' => null, 'cleared_at' => null]);

            $reason = trim((string) $reason) !== '' ? mb_substr(trim((string) $reason), 0, 255) : 'Un-matched by '.$user->name;

            $fresh->forceFill([
                'state' => 'released', 'released_at' => now(), 'released_by' => $user->id, 'release_reason' => $reason,
                'snapshot' => ['line_ids' => $lineIds, 'entry_ids' => $entryIds, 'amount_cents' => $fresh->amount_cents],
            ])->save();

            $this->event($session, 'unmatched', $user, ['match_id' => $fresh->id, 'line_ids' => $lineIds, 'entry_ids' => $entryIds, 'amount_cents' => $fresh->amount_cents]);
            $this->audit($session, 'unmatched', ['match_id' => ['before' => $fresh->id], 'amount_cents' => ['before' => $fresh->amount_cents]], $user);

            return $fresh;
        });
    }

    /**
     * Finish the session. Requires closing balance = cleared balance, or a variance the person is authorised to accept
     * (with a reason) that brings it to zero; unmatched lines can only be left behind together with a variance.
     */
    public function complete(BankReconciliation $session, User $user, bool $acceptVariance = false, ?string $varianceReason = null): BankReconciliation
    {
        return DB::transaction(function () use ($session, $user, $acceptVariance, $varianceReason) {
            $locked = $this->lockDraft($session);
            $context = $locked->ledgerContext();
            $account = Account::query()->forContext($context)->whereKey($locked->account_id)->lockForUpdate()->firstOrFail();

            $baseline = $locked->baseline_date?->toDateString();
            $cleared = $this->balances->clearedBalance($account, $baseline);
            $difference = $locked->statement_closing_cents - $cleared;
            $unmatched = (int) BankStatementLine::query()->forContext($context)->where('account_id', $account->id)
                ->where('posted_on', '<=', $locked->statement_date->toDateString())->whereNull('bank_match_id')->whereNull('reconciliation_id')->count();

            $variance = 0;
            $varianceTransactionId = null;

            if ($difference !== 0) {
                if (! $acceptVariance) {
                    throw new ReconciliationRefused('difference', 'The statement closes at '.Money::format($locked->statement_closing_cents, $locked->currency).' but the ledger\'s cleared balance is '.Money::format($cleared, $locked->currency).'. The difference is '.Money::format($difference, $locked->currency, true).'. Find what is missing or wrong; it must be zero to complete.');
                }

                $varianceReason = trim((string) $varianceReason);
                if (mb_strlen($varianceReason) < 5) {
                    throw new ReconciliationRefused('no_reason', 'Say why the difference is being accepted (at least a few words). It is kept with the reconciliation.');
                }
                if (! LedgerAccess::allows(Capability::PostJournal, $user, $context)) {
                    throw new ReconciliationRefused('locked', 'Accepting a difference needs the accountant-level permission to post entries. Ask an accountant or finance manager.');
                }

                $transaction = $this->adjustments->variance($locked, $account, $user, $difference, $varianceReason);
                $variance = $difference;
                $varianceTransactionId = $transaction->id;
                $cleared = $this->balances->clearedBalance($account, $baseline);
            }

            if ($locked->statement_closing_cents - $cleared !== 0) {
                throw new ReconciliationRefused('difference', 'The balances still differ after the adjustment, so nothing was completed.');
            }
            if ($unmatched > 0 && $variance === 0) {
                throw new ReconciliationRefused('unmatched_lines', $unmatched.' statement '.($unmatched === 1 ? 'line is' : 'lines are').' still not matched. Match them, create the missing entries, or accept a difference with a reason.');
            }

            $matchIds = DB::table('bank_matches')->where('bank_reconciliation_id', $locked->id)->where('state', 'active')->pluck('id')->all();
            $reconciledRows = 0;
            $reconciledLines = 0;
            foreach (array_chunk($matchIds, 500) as $chunk) {
                $reconciledRows += DB::table('transactions')->whereIn('bank_match_id', $chunk)->update(['reconciliation_id' => $locked->id]);
                $reconciledLines += DB::table('bank_statement_lines')->whereIn('bank_match_id', $chunk)->update(['reconciliation_id' => $locked->id]);
            }

            // Entries dated before the baseline were counted as agreed with the bank; now that the session is complete they are, and they lock like the rest.
            $broughtForward = $baseline === null ? 0 : Transaction::query()->forContext($context)->counted()->where('account_id', $account->id)
                ->where('date', '<', $baseline)->whereNull('bank_match_id')->whereNull('reconciliation_id')->toBase()->update(['reconciliation_id' => $locked->id]);

            $snapshot = $this->report->snapshot($locked, $account, $cleared);

            $locked->forceFill([
                'status' => 'completed',
                'ledger_cleared_cents' => $cleared,
                'difference_cents' => 0,
                'variance_cents' => $variance,
                'variance_reason' => $variance !== 0 ? $varianceReason : null,
                'variance_by' => $variance !== 0 ? $user->id : null,
                'variance_transaction_id' => $varianceTransactionId,
                'unmatched_accepted' => $variance !== 0 ? $unmatched : 0,
                'snapshot' => $snapshot,
                'completed_by' => $user->id,
                'completed_at' => now(),
            ])->save();

            $this->event($locked, 'completed', $user, ['closing_cents' => $locked->statement_closing_cents, 'cleared_cents' => $cleared, 'variance_cents' => $variance, 'unmatched_accepted' => $variance !== 0 ? $unmatched : 0, 'rows_reconciled' => $reconciledRows, 'brought_forward' => $broughtForward, 'lines_reconciled' => $reconciledLines]);
            $this->audit($locked, 'reconciled', [
                'statement_date' => ['after' => $locked->statement_date->toDateString()],
                'closing_cents' => ['after' => $locked->statement_closing_cents],
                'cleared_cents' => ['after' => $cleared],
                'variance_cents' => ['after' => $variance],
                'rows_reconciled' => ['after' => $reconciledRows],
            ], $user);

            return $locked->refresh();
        });
    }

    /**
     * Un-reconcile: reopen the NEWEST completed session of an account, with a reason. The rows it locked are unlocked (they
     * stay matched, so they stay protected until un-matched). The completed state it had is kept in the event log.
     */
    public function reopen(BankReconciliation $session, User $user, string $reason): BankReconciliation
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new ReconciliationRefused('no_reason', 'Say why this reconciliation is being reopened (at least a few words). It is kept in the history.');
        }

        return DB::transaction(function () use ($session, $user, $reason) {
            $context = $session->ledgerContext();

            if (! LedgerAccess::allows(Capability::PostJournal, $user, $context)) {
                throw new ReconciliationRefused('locked', 'Reopening a reconciliation needs the accountant-level permission to post entries.');
            }

            Account::query()->forContext($context)->whereKey($session->account_id)->lockForUpdate()->first();
            $locked = BankReconciliation::query()->forContext($context)->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isCompleted()) {
                throw new ReconciliationRefused('not_draft', 'That reconciliation is not completed.');
            }
            if (BankReconciliation::query()->forContext($context)->where('account_id', $locked->account_id)->where('status', 'draft')->exists()) {
                throw new ReconciliationRefused('already_open', 'This account has a reconciliation in progress. Finish or discard that one first.');
            }
            $newer = BankReconciliation::query()->forContext($context)->where('account_id', $locked->account_id)->where('status', 'completed')->where('statement_date', '>', $locked->statement_date->toDateString())->exists();
            if ($newer) {
                throw new ReconciliationRefused('not_newest', 'A later reconciliation of this account is completed. Reopen the newest one first, then work back.');
            }

            $before = [
                'statement_date' => $locked->statement_date->toDateString(), 'closing_cents' => $locked->statement_closing_cents, 'cleared_cents' => $locked->ledger_cleared_cents,
                'variance_cents' => $locked->variance_cents, 'completed_by' => $locked->completed_by, 'completed_at' => $locked->completed_at?->toIso8601String(),
            ];

            $rows = DB::table('transactions')->where('reconciliation_id', $locked->id)->update(['reconciliation_id' => null]);
            $lines = DB::table('bank_statement_lines')->where('reconciliation_id', $locked->id)->update(['reconciliation_id' => null]);

            $locked->forceFill([
                'status' => 'draft', 'ledger_cleared_cents' => null, 'difference_cents' => null, 'snapshot' => null,
                'completed_by' => null, 'completed_at' => null,
                'reopened_count' => $locked->reopened_count + 1, 'last_reopened_at' => now(), 'last_reopen_reason' => mb_substr($reason, 0, 255),
            ])->save();

            $this->event($locked, 'reopened', $user, ['reason' => $reason, 'was' => $before, 'rows_unlocked' => $rows, 'lines_unlocked' => $lines]);
            $this->audit($locked, 'reopened', ['status' => ['before' => 'completed', 'after' => 'draft'], 'rows_unlocked' => ['after' => $rows]], $user);

            return $locked->refresh();
        });
    }

    /** The session, locked for update, and required to be a draft. */
    public function lockDraft(BankReconciliation $session): BankReconciliation
    {
        $locked = BankReconciliation::query()->forContext($session->ledgerContext())->whereKey($session->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isDraft()) {
            throw new ReconciliationRefused('not_draft', 'This reconciliation is completed. Reopen it (newest first, with a reason) to change it.');
        }

        return $locked;
    }
}
