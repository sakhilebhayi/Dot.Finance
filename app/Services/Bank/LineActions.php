<?php

namespace App\Services\Bank;

use App\Contracts\Bank\OpenDocument;
use App\Exceptions\Bank\ReconciliationRefused;
use App\Models\Account;
use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankRule;
use App\Models\Bank\BankStatementLine;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\Exceptions\AccountingException;
use App\Services\Accounting\Exceptions\PeriodClosed;
use App\Services\Accounting\GlSync;
use App\Services\Controls\ApprovalService;
use App\Services\TransferService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one-click answers in the exceptions inbox. Every one of them creates its records through the normal path, never by
 * writing rows around it:
 *
 *   createEntry       an income or expense entry for a line (GlSync, then JournalPoster, in the same database transaction)
 *   createEntries     the same for a group of lines, each on its own, reporting the ones that could not be done
 *   createTransfer    a transfer between two of the ledger's accounts (TransferService), matching the side being reconciled
 *   settleDocument    pay an open invoice or bill from the line, through the document source that owns it
 *   matchWithDifference  record the difference to an entry that is close, then match the two
 *
 * and then matches the line to what was created, in the open session, with the reason in words. Where an approval policy
 * covers the amount the action is refused (needs_approval) rather than posting around the policy.
 */
class LineActions
{
    use RecordsReconciliationHistory;

    public function __construct(
        private readonly MatchRecorder $recorder,
        private readonly ReconciliationService $reconciliations,
        private readonly AdjustmentService $adjustments,
        private readonly DocumentSources $documents,
    ) {}

    /** @param  ?int  $categoryId  already validated to be in this ledger by the caller; its type is checked here */
    public function createEntry(BankReconciliation $session, User $user, BankStatementLine|int $line, ?int $categoryId, ?string $description = null, ?BankRule $rule = null): Transaction
    {
        return DB::transaction(function () use ($session, $user, $line, $categoryId, $description, $rule) {
            $locked = $this->reconciliations->lockDraft($session);
            $context = $locked->ledgerContext();
            $account = Account::query()->forContext($context)->findOrFail($locked->account_id);
            $line = $this->openLine($locked, $line);

            $type = $line->amount_cents > 0 ? 'income' : 'expense';
            $cents = abs($line->amount_cents);

            if ($account->opening_balance_date !== null && $line->posted_on->toDateString() < $account->opening_balance_date->toDateString()) {
                throw new ReconciliationRefused('out_of_scope', 'This line is dated before the account\'s opening balance date ('.$account->opening_balance_date->toDateString().'), so an entry for it would not count. Move the opening balance date if the statement starts earlier.');
            }

            $category = null;
            if ($categoryId !== null) {
                $category = Category::query()->forContext($context)->find($categoryId) ?? throw new ReconciliationRefused('not_in_ledger', 'That category is not in this ledger.');

                if ($category->type !== $type) {
                    throw new ReconciliationRefused('invalid', "\"{$category->name}\" is an {$category->type} category, so it cannot be used for an {$type}.");
                }
            }

            if ($type === 'expense' && ApprovalService::requiresApproval('expense', $cents, $account->currency, $context)) {
                throw new ReconciliationRefused('needs_approval', 'An approval policy covers expenses of '.Money::format($cents, $account->currency).', so this cannot be created from the reconciliation. Record it through the flow that collects the approval, then match it here.');
            }

            $transaction = $this->write($account, $type, $cents, $line->posted_on->toDateString(), $description !== null && trim($description) !== '' ? trim($description) : (string) $line->description, $category?->id, 'Created from a bank statement line');

            $reason = $rule instanceof BankRule
                ? 'Created from this statement line because '.mb_strtolower($rule->sentence()).' then categorise it as '.($category?->name ?? 'uncategorised').'.'
                : 'Created from this statement line: you recorded it as '.($type === 'income' ? 'money in' : 'money out').'.';

            $this->recorder->record($locked, [$line->id], [$transaction->id], $line->amount_cents, $user, $rule instanceof BankRule ? 'rule' : 'created', null, $reason);

            if ($rule instanceof BankRule) {
                DB::table('bank_rules')->where('id', $rule->id)->update(['times_applied' => DB::raw('times_applied + 1'), 'last_applied_at' => now()]);
            }

            return $transaction;
        });
    }

    /**
     * @param  list<int>  $lineIds
     * @param  string  $mode  entry (the chosen category), rule (each line's own rule), bank_charge, interest
     * @return array{created: int, failed: list<array{line_id: int, message: string}>}
     */
    public function createEntries(BankReconciliation $session, User $user, array $lineIds, string $mode, ?int $categoryId = null): array
    {
        $created = 0;
        $failed = [];
        $rules = null;

        foreach (array_values(array_unique(array_map('intval', $lineIds))) as $id) {
            try {
                DB::transaction(function () use ($session, $user, $id, $mode, $categoryId, &$rules) {
                    $line = $this->peek($session, $id);

                    match ($mode) {
                        'bank_charge', 'interest' => $this->adjustments->fromLine($session, $user, $line, $mode),
                        'rule' => $this->createFromRule($session, $user, $line, $rules ??= BankRuleEngine::for($session->ledgerContext(), $session->account_id)),
                        default => $this->createEntry($session, $user, $line, $categoryId),
                    };
                });
                $created++;
            } catch (ReconciliationRefused $e) {
                $failed[] = ['line_id' => $id, 'message' => $e->getMessage()];
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }

    private function createFromRule(BankReconciliation $session, User $user, BankStatementLine $line, BankRuleEngine $rules): Transaction
    {
        $rule = $rules->match($line) ?? throw new ReconciliationRefused('invalid', 'No bank rule fits this line any more.');

        return $this->createEntry($session, $user, $line, $rule->category_id, $rule->new_description, $rule);
    }

    /**
     * Record a transfer between two of the ledger's own accounts from a statement line, and match this account's side.
     * When the other account's matching line is given (and that account has an open reconciliation reaching its date) its side is
     * matched too; otherwise that side matches when that account is reconciled.
     *
     * @return array{transfer: array{out: Transaction, in: Transaction}, other_matched: bool}
     */
    public function createTransfer(BankReconciliation $session, User $user, BankStatementLine|int $line, int $otherAccountId, ?int $otherLineId = null): array
    {
        return DB::transaction(function () use ($session, $user, $line, $otherAccountId, $otherLineId) {
            $locked = $this->reconciliations->lockDraft($session);
            $context = $locked->ledgerContext();
            $mine = Account::query()->forContext($context)->findOrFail($locked->account_id);
            $other = Account::query()->forContext($context)->whereKeyNot($mine->id)->find($otherAccountId)
                ?? throw new ReconciliationRefused('not_in_ledger', 'That account is not in this ledger.');
            $line = $this->openLine($locked, $line);

            if ($other->currency !== $mine->currency) {
                throw new ReconciliationRefused('invalid', "These accounts hold different currencies ({$mine->currency} and {$other->currency}). Record a transfer between them with the transfer form, where you state both amounts.");
            }

            $otherLine = null;
            if ($otherLineId !== null) {
                $otherLine = BankStatementLine::query()->forContext($context)->where('account_id', $other->id)->whereKey($otherLineId)->lockForUpdate()->first();

                if ($otherLine === null || $otherLine->bank_match_id !== null || $otherLine->reconciliation_id !== null || $otherLine->amount_cents !== -$line->amount_cents) {
                    throw new ReconciliationRefused('totals_differ', 'The other statement line is not an unmatched line of exactly the opposite amount in that account.');
                }
            }

            $cents = abs($line->amount_cents);
            [$from, $to] = $line->amount_cents < 0 ? [$mine, $other] : [$other, $mine];

            try {
                $legs = app(TransferService::class)->create($user, $from, $to, $cents, null, Carbon::parse($line->posted_on->toDateString()), 'Transfer from '.$from->name.' to '.$to->name, 'Created during bank reconciliation');
            } catch (PeriodClosed $e) {
                throw new ReconciliationRefused('locked', $e->getMessage().' A transfer cannot be dated in a closed period.');
            } catch (AccountingException|InvalidArgumentException $e) {
                throw new ReconciliationRefused('invalid', $e->getMessage());
            }

            $myLeg = $line->amount_cents < 0 ? $legs['out'] : $legs['in'];
            $this->recorder->record($locked, [$line->id], [$myLeg->id], $line->amount_cents, $user, 'transfer', null, 'Recorded as a transfer from '.$from->name.' to '.$to->name.' from this statement line.');

            $otherMatched = false;
            if ($otherLine !== null) {
                $otherLeg = $line->amount_cents < 0 ? $legs['in'] : $legs['out'];
                $otherSession = BankReconciliation::query()->forContext($context)->where('account_id', $other->id)->where('status', 'draft')
                    ->where('statement_date', '>=', $otherLine->posted_on->toDateString())->lockForUpdate()->first();

                if ($otherSession !== null) {
                    $this->recorder->record($otherSession, [$otherLine->id], [$otherLeg->id], $otherLine->amount_cents, $user, 'transfer', null, 'Recorded as a transfer from '.$from->name.' to '.$to->name.' from the matching statement line in '.$mine->name.'.');
                    $otherMatched = true;
                }
            }

            return ['transfer' => $legs, 'other_matched' => $otherMatched];
        });
    }

    /** Pay an open invoice or bill with the money on this line, through the module that owns the document. */
    public function settleDocument(BankReconciliation $session, User $user, BankStatementLine|int $line, OpenDocument $document): Transaction
    {
        return DB::transaction(function () use ($session, $user, $line, $document) {
            $locked = $this->reconciliations->lockDraft($session);
            $line = $this->openLine($locked, $line);
            $cents = abs($line->amount_cents);

            if (($document->direction === 'in') !== ($line->amount_cents > 0)) {
                throw new ReconciliationRefused('invalid', $document->direction === 'in' ? 'Money in can settle an invoice, not a bill.' : 'Money out can settle a bill, not an invoice.');
            }
            if ($cents > $document->openCents) {
                throw new ReconciliationRefused('totals_differ', 'This line is '.Money::format($cents, $locked->currency).' but only '.Money::format($document->openCents, $locked->currency).' is still owed on '.$document->number.'. Settle part of it from the document, or match the line another way.');
            }

            $transaction = $this->documents->settle($locked->ledgerContext(), $user, $line, $document, $cents, 'bank-line-'.$line->id.'-'.$document->type.'-'.$document->id);

            if ((int) $transaction->account_id !== (int) $locked->account_id || $transaction->signedCents() !== $line->amount_cents) {
                throw new ReconciliationRefused('invalid', 'The document module returned an entry that does not match this line, so nothing was matched.');
            }

            $this->recorder->record($locked, [$line->id], [$transaction->id], $line->amount_cents, $user, 'manual', null, 'Settles '.$document->number.' ('.$document->partyName.'): '.$document->why.'.');

            return $transaction;
        });
    }

    /**
     * An entry exists for this line but at another amount: record the difference as its own entry, then match the line to both.
     */
    public function matchWithDifference(BankReconciliation $session, User $user, BankStatementLine|int $line, int $entryId, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new ReconciliationRefused('no_reason', 'Say what the difference is (a fee, a short payment...). It is kept with the entry.');
        }

        DB::transaction(function () use ($session, $user, $line, $entryId, $reason) {
            $locked = $this->reconciliations->lockDraft($session);
            $line = $this->openLine($locked, $line);

            $entry = Transaction::query()->forContext($locked->ledgerContext())->counted()->where('account_id', $locked->account_id)->whereNull('bank_match_id')->whereNull('reconciliation_id')->whereKey($entryId)->lockForUpdate()->first()
                ?? throw new ReconciliationRefused('not_in_ledger', 'That entry is not an unmatched entry of this account.');

            $difference = $line->amount_cents - $entry->signedCents();
            if ($difference === 0) {
                throw new ReconciliationRefused('invalid', 'The amounts already agree: match them directly.');
            }
            if (($entry->signedCents() > 0) !== ($line->amount_cents > 0)) {
                throw new ReconciliationRefused('invalid', 'One is money in and the other money out, so the difference is not the same event.');
            }

            $diffTransaction = $this->adjustments->difference($locked, $user, $difference, $line->posted_on->toDateString(), 'Difference on '.mb_substr((string) $entry->description, 0, 150), $reason);

            $this->reconciliations->matchRows($locked, [$line->id], [$entry->id, $diffTransaction->id], $user, 'manual', null, 'Matched with the difference of '.Money::format(abs($difference), $locked->currency).' recorded separately: '.mb_substr($reason, 0, 250).'.');
        });
    }

    // ── internals ───────────────────────────────────────────────────────────

    /** Write a cash-ledger row the normal way: model, audit observer, then GlSync (JournalPoster) in the same transaction. */
    private function write(Account $account, string $type, int $cents, string $date, string $description, ?int $categoryId, string $notes): Transaction
    {
        try {
            $transaction = Transaction::create($account->ledgerOwner() + [
                'account_id' => $account->id,
                'category_id' => $categoryId,
                'description' => mb_substr($description, 0, 255),
                'amount_cents' => $cents,
                'currency' => $account->currency,
                'type' => $type,
                'date' => $date,
                'notes' => $notes,
            ]);

            app(GlSync::class)->transaction($transaction);

            return $transaction;
        } catch (PeriodClosed $e) {
            throw new ReconciliationRefused('locked', $e->getMessage().' An entry cannot be created in a closed period; reopen the period or record it later.');
        } catch (AccountingException $e) {
            throw new ReconciliationRefused('invalid', $e->getMessage());
        }
    }

    /** The line, locked, and required to be open in this session. */
    private function openLine(BankReconciliation $session, BankStatementLine|int $line): BankStatementLine
    {
        $id = $line instanceof BankStatementLine ? $line->id : $line;

        $fresh = BankStatementLine::query()->forContext($session->ledgerContext())->where('account_id', $session->account_id)->whereKey($id)->lockForUpdate()->first()
            ?? throw new ReconciliationRefused('not_in_ledger', 'That statement line is not in this account.');

        if ($fresh->bank_match_id !== null || $fresh->reconciliation_id !== null) {
            throw new ReconciliationRefused('already_matched', 'That statement line is already matched.');
        }
        if ($fresh->posted_on->toDateString() > $session->statement_date->toDateString()) {
            throw new ReconciliationRefused('out_of_scope', 'That line is dated after this reconciliation\'s statement date.');
        }

        return $fresh;
    }

    private function peek(BankReconciliation $session, int $id): BankStatementLine
    {
        return BankStatementLine::query()->forContext($session->ledgerContext())->where('account_id', $session->account_id)->whereKey($id)->first()
            ?? throw new ReconciliationRefused('not_in_ledger', 'That statement line is not in this account.');
    }
}
