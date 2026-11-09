<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Accounting\LedgerSetting;
use App\Models\Category;
use App\Services\Accounting\Exceptions\AccountingNotReady;
use App\Services\Accounting\Exceptions\AlreadyReversed;
use App\Services\Accounting\Exceptions\CrossLedger;
use App\Services\Accounting\Exceptions\PeriodClosed;
use App\Services\Accounting\Exceptions\UnbalancedEntry;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of journal rows. One posting is one balanced entry in the base currency, in an
 * open period, with the next gap-free number, all under a lock on the ledger's settings row.
 *
 * Contract (docs/R44_ARCHITECTURE.md section 3):
 *  - post(): idempotent on (ledger, source type, source id, source version); throws UnbalancedEntry,
 *    PeriodClosed, CrossLedger. It opens its own transaction when the caller has none, and joins the
 *    caller's when there is one.
 *  - reverse(): writes the mirror-image entry (linked both ways) and marks the original reversed.
 *  - repost(): reverses the live version of a source and posts the new one, atomically.
 *
 * Every line's GL account, cash account and category must belong to the entry's ledger, checked in
 * one query per kind before anything is written.
 */
class JournalPoster
{
    public function __construct(private readonly Chart $chart, private readonly Periods $periods) {}

    public function post(PostingRequest $request, bool $isReversal = false, ?int $reversesEntryId = null): JournalEntry
    {
        $context = LedgerContext::current() ?? throw new CrossLedger('There is no active ledger to post to.');

        $this->assertShape($request);

        return DB::transaction(function () use ($request, $context, $isReversal, $reversesEntryId) {
            // The settings row is the lock: it serialises numbering and the period check.
            $settings = LedgerSetting::query()->forContext($context)->lockForUpdate()->first()
                ?? throw new AccountingNotReady('This ledger has no accounting settings yet (base currency and chart).');

            $existing = JournalEntry::query()->forContext($context)
                ->where('source_type', $request->sourceType)
                ->where('source_id', (string) $request->sourceId)
                ->where('source_version', $request->sourceVersion)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($settings->books_closed_through !== null && $request->entryDate->lte(CarbonImmutable::parse($settings->books_closed_through->toDateString()))) {
                throw new PeriodClosed("The books are closed through {$settings->books_closed_through->toDateString()}: {$request->entryDate->toDateString()} falls in a closed period.");
            }

            $accountIds = $this->resolveAccounts($request, $context, $isReversal);
            $this->assertSameLedger($request, $context);

            $period = $this->periods->forDate($context, $request->entryDate, $settings);

            // The next number and the hash it chains from come from the newest entry itself, read while the settings
            // row is locked. (Counting on a column of that row would rewrite one row per posting; PostgreSQL keeps every
            // version of a row updated inside one transaction, so a bulk post slowed down with every entry.)
            $last = JournalEntry::query()->forContext($context)->orderByDesc('entry_number')->first(['entry_number', 'entry_hash']);
            $number = $last === null ? 1 : (int) $last->entry_number + 1;
            $previousHash = $last?->entry_hash;
            $postedBy = $request->postedByUserId ?? Auth::id();
            $now = now();
            $total = $request->totalDebits();

            $entry = JournalEntry::create($context->ownerAttributes() + [
                'entry_number' => $number,
                'entry_date' => $request->entryDate->toDateString(),
                'period_id' => $period->id,
                'status' => 'posted',
                'reverses_entry_id' => $reversesEntryId,
                'source_type' => $request->sourceType,
                'source_id' => (string) $request->sourceId,
                'source_version' => $request->sourceVersion,
                'memo' => mb_substr($request->memo, 0, 500),
                'currency' => $settings->base_currency,
                'total_cents' => $total,
                'posted_by_user_id' => $postedBy,
                'posted_at' => $now,
                'posting_rule_version' => $settings->posting_rule_version,
                'prev_hash' => $previousHash,
                'entry_hash' => $this->hash($previousHash, $context, $number, $request, $accountIds, $settings->base_currency),
            ]);

            $rows = [];
            foreach (array_values($request->lines) as $i => $line) {
                $rows[] = $context->ownerAttributes() + [
                    'journal_entry_id' => $entry->id,
                    'line_no' => $i + 1,
                    'entry_date' => $request->entryDate->toDateString(),
                    'gl_account_id' => $accountIds[$i],
                    'debit_cents' => $line->debitCents,
                    'credit_cents' => $line->creditCents,
                    'txn_currency' => $line->txnCurrency,
                    'txn_amount_cents' => $line->txnAmountCents,
                    'fx_rate_e8' => $line->fxRateE8,
                    'party_id' => $line->partyId,
                    'cash_account_id' => $line->cashAccountId,
                    'category_id' => $line->categoryId,
                    'tax_code_id' => $line->taxCodeId,
                    'dimension' => $line->dimension,
                    'description' => $line->description !== null ? mb_substr($line->description, 0, 255) : null,
                    'created_at' => $now,
                ];
            }
            DB::table('journal_lines')->insert($rows);

            return $entry;
        });
    }

    /**
     * Write the mirror image of $entry and mark $entry reversed. An entry is reversed once, a reversal
     * is never itself reversed (post a new entry instead). The reversal is dated $on, else the original's date.
     */
    public function reverse(JournalEntry $entry, string $reason, ?CarbonImmutable $on = null): JournalEntry
    {
        $context = LedgerContext::current() ?? throw new CrossLedger('There is no active ledger.');

        if (! $entry->ledgerContext()->equals($context)) {
            throw new CrossLedger('That journal entry belongs to another ledger.');
        }

        return DB::transaction(function () use ($entry, $reason, $on, $context) {
            // Lock the settings first (same order as post()) so two reversals cannot interleave.
            LedgerSetting::query()->forContext($context)->lockForUpdate()->first();

            $fresh = JournalEntry::query()->forContext($context)->findOrFail($entry->id);

            if ($fresh->isReversal()) {
                throw new AlreadyReversed('A reversal cannot be reversed. Post a new entry to put the original effect back.');
            }
            if ($fresh->isReversed()) {
                throw new AlreadyReversed("Entry {$fresh->reference()} is already reversed.");
            }

            $lines = $fresh->lines()->get()->map(fn (JournalLine $l) => (new PostingLine(
                $l->gl_account_id, $l->credit_cents, $l->debit_cents, $l->txn_currency, $l->txn_amount_cents, $l->fx_rate_e8,
                $l->party_id, $l->cash_account_id, $l->category_id, $l->tax_code_id, $l->dimension, $l->description,
            )))->all();

            $date = $on ?? CarbonImmutable::parse($fresh->entry_date->toDateString());

            $reversal = $this->post(new PostingRequest(
                $date, 'reversal', $fresh->id, 1,
                'Reversal of '.$fresh->reference().': '.mb_substr($reason, 0, 380),
                $lines,
            ), isReversal: true, reversesEntryId: $fresh->id);

            // The single update an entry ever receives; the trigger permits exactly this one.
            DB::table('journal_entries')->where('id', $fresh->id)->update(['status' => 'reversed', 'reversed_by_entry_id' => $reversal->id]);

            return $reversal->refresh();
        });
    }

    /**
     * Replace the live version of a source with a new one: reverse it, post the new entry as the next
     * version. If there is no live version this simply posts. Atomic.
     */
    public function repost(string $sourceType, int|string $sourceId, PostingRequest $new, string $reason = 'Superseded by a corrected posting'): JournalEntry
    {
        $context = LedgerContext::current() ?? throw new CrossLedger('There is no active ledger.');

        return DB::transaction(function () use ($sourceType, $sourceId, $new, $reason, $context) {
            LedgerSetting::query()->forContext($context)->lockForUpdate()->first();

            $live = $this->liveEntry($context, $sourceType, (string) $sourceId);
            $maxVersion = (int) JournalEntry::query()->forContext($context)->where('source_type', $sourceType)->where('source_id', (string) $sourceId)->max('source_version');

            if ($live !== null) {
                $this->reverse($live, $reason);
            }

            return $this->post($new->withVersion(max($new->sourceVersion, $maxVersion + 1)));
        });
    }

    /** The posted (not reversed) entry for a source, newest version first. */
    public function liveEntry(LedgerContext $context, string $sourceType, string $sourceId): ?JournalEntry
    {
        return JournalEntry::query()->forContext($context)
            ->where('source_type', $sourceType)->where('source_id', $sourceId)->where('status', 'posted')
            ->orderByDesc('source_version')->first();
    }

    private function assertShape(PostingRequest $request): void
    {
        if (count($request->lines) < 2) {
            throw new UnbalancedEntry('A journal entry needs at least two lines.');
        }

        foreach ($request->lines as $line) {
            if (! $line instanceof PostingLine) {
                throw new InvalidArgumentException('Posting lines must be PostingLine instances.');
            }
        }

        if (trim($request->memo) === '') {
            throw new InvalidArgumentException('A journal entry needs a memo.');
        }

        if ($request->totalDebits() !== $request->totalCredits()) {
            throw new UnbalancedEntry(sprintf('Debits %d and credits %d are not equal.', $request->totalDebits(), $request->totalCredits()));
        }
    }

    /**
     * Turn each line's account (id or system key) into a gl_accounts.id, refusing accounts from
     * another ledger, inactive accounts (unless reversing) and non-postable accounts.
     *
     * @return list<int>
     */
    private function resolveAccounts(PostingRequest $request, LedgerContext $context, bool $isReversal): array
    {
        $systemIds = $this->chart->systemIds($context);
        $ids = [];

        foreach (array_values($request->lines) as $line) {
            if (is_string($line->account) && ! ctype_digit($line->account)) {
                $ids[] = $systemIds[$line->account] ?? throw new AccountingNotReady("This ledger's chart has no '{$line->account}' account.");
            } else {
                $ids[] = (int) $line->account;
            }
        }

        $accounts = GlAccount::query()->forContext($context)->whereIn('id', array_unique($ids))->get()->keyBy('id');

        foreach ($ids as $id) {
            $account = $accounts->get($id) ?? throw new CrossLedger("GL account #{$id} does not exist in this ledger.");

            if (! $account->is_postable) {
                throw new UnbalancedEntry("Account {$account->label()} is a report line and cannot be posted to.");
            }
            if (! $account->is_active && ! $isReversal) {
                throw new UnbalancedEntry("Account {$account->label()} is inactive.");
            }
        }

        return $ids;
    }

    private function assertSameLedger(PostingRequest $request, LedgerContext $context): void
    {
        $cash = array_unique(array_filter(array_map(fn (PostingLine $l) => $l->cashAccountId, $request->lines)));
        if ($cash !== [] && Account::query()->forContext($context)->withTrashed()->whereIn('id', $cash)->count() !== count($cash)) {
            throw new CrossLedger('A line names a cash account from another ledger.');
        }

        $categories = array_unique(array_filter(array_map(fn (PostingLine $l) => $l->categoryId, $request->lines)));
        if ($categories !== [] && Category::query()->forContext($context)->withTrashed()->whereIn('id', $categories)->count() !== count($categories)) {
            throw new CrossLedger('A line names a category from another ledger.');
        }
    }

    /** @param list<int> $accountIds */
    private function hash(?string $previous, LedgerContext $context, int $number, PostingRequest $request, array $accountIds, string $currency): string
    {
        $lines = [];
        foreach (array_values($request->lines) as $i => $l) {
            $lines[] = implode(':', [$accountIds[$i], $l->debitCents, $l->creditCents, $l->txnCurrency, $l->txnAmountCents, $l->fxRateE8, $l->cashAccountId, $l->categoryId]);
        }

        return hash('sha256', implode('|', [
            $previous ?? '', $context->key(), $number, $request->entryDate->toDateString(), $request->sourceType, $request->sourceId,
            $request->sourceVersion, $currency, implode(',', $lines),
        ]));
    }

    public function period(JournalEntry $entry): AccountingPeriod
    {
        return $entry->period;
    }
}
