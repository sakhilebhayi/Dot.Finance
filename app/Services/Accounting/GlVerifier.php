<?php

namespace App\Services\Accounting;

use App\Models\Accounting\LedgerSetting;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Facades\DB;

/**
 * `finance:gl:verify`: recomputes, from the rows, every promise the accounting layer makes.
 *
 *   entries_balance       every entry has two or more lines and equal debits and credits
 *   trial_balance         total debits equal total credits, and assets = liabilities + equity + income - expenses
 *   cash_tie_out          every cash account's derived balance equals what the journals hold for it
 *   entry_numbers         numbers run 1..N with no gap, and the hash chain links each entry to the one before
 *   reversals             reversal links agree both ways
 *   periods               nothing was posted into a closed month after it closed; entries sit in their period
 *   ledger_isolation      no line or entry references an account, cash account or category of another ledger
 *   pending_sources       cash-ledger rows the journals do not yet reflect (the sweeper's work)
 *
 * Each check is {code, ok, message, details}. The report is ok only when every check is.
 */
class GlVerifier
{
    public function __construct(private readonly GlQuery $query, private readonly TrialBalance $trial, private readonly GlSweeper $sweeper) {}

    /** @return array{ok: bool, ledger: string, checks: list<array{code: string, ok: bool, message: string, details: array<int, mixed>}>} */
    public function verify(LedgerContext $context): array
    {
        $settings = LedgerSetting::query()->forContext($context)->first();
        $checks = [
            $this->entriesBalance($context),
            $this->trialBalance($context),
            $this->cashTieOut($context),
            $this->entryNumbers($context),
            $this->reversals($context),
            $this->periods($context, $settings),
            $this->isolation($context),
            $this->pending($context),
        ];

        return ['ok' => collect($checks)->every(fn ($c) => $c['ok']), 'ledger' => $context->key(), 'checks' => $checks];
    }

    private function check(string $code, bool $ok, string $okMessage, string $failMessage, array $details = []): array
    {
        return ['code' => $code, 'ok' => $ok, 'message' => $ok ? $okMessage : $failMessage, 'details' => $ok ? [] : $details];
    }

    private function entriesBalance(LedgerContext $context): array
    {
        $bad = $this->owned($context, 'journal_entries as e')
            ->leftJoin('journal_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->groupBy('e.id', 'e.entry_number')
            ->havingRaw('COUNT(l.id) < 2 OR SUM(l.debit_cents) <> SUM(l.credit_cents) OR SUM(l.debit_cents) <> MAX(e.total_cents)')
            ->selectRaw('e.id, e.entry_number, COUNT(l.id) AS lines, COALESCE(SUM(l.debit_cents),0) AS debits, COALESCE(SUM(l.credit_cents),0) AS credits')
            ->limit(25)->get();

        return $this->check('entries_balance', $bad->isEmpty(), 'Every entry balances.', $bad->count().' entr'.($bad->count() === 1 ? 'y does' : 'ies do').' not balance.', $bad->map(fn ($r) => "Entry #{$r->entry_number}: {$r->lines} line(s), debits {$r->debits}, credits {$r->credits}")->all());
    }

    private function trialBalance(LedgerContext $context): array
    {
        $tb = $this->trial->asOf($context);

        return $this->check('trial_balance', $tb['balanced'] && $tb['equation']['holds'], 'Debits equal credits and the accounting equation holds.', 'The trial balance does not agree.', [
            "debits {$tb['total_debits']}, credits {$tb['total_credits']}",
            'equation '.json_encode($tb['equation']),
        ]);
    }

    private function cashTieOut(LedgerContext $context): array
    {
        $bad = array_values(array_filter($this->query->cashTieOut($context), fn ($r) => ! $r['ok']));

        return $this->check('cash_tie_out', $bad === [], 'Every cash account agrees with its journal postings.', count($bad).' cash account(s) disagree with the journals.', array_map(fn ($r) => "{$r['name']} ({$r['currency']}): cash ledger {$r['cash_cents']}, journals {$r['gl_cents']}, difference {$r['difference_cents']}", $bad));
    }

    private function entryNumbers(LedgerContext $context): array
    {
        $problems = [];
        $expected = 1;
        $previous = null;

        $this->owned($context, 'journal_entries as e')->orderBy('e.entry_number')->select('e.id', 'e.entry_number', 'e.prev_hash', 'e.entry_hash')->chunk(2000, function ($rows) use (&$problems, &$expected, &$previous) {
            foreach ($rows as $row) {
                if ((int) $row->entry_number !== $expected) {
                    $problems[] = "Expected entry number {$expected}, found {$row->entry_number}.";
                    $expected = (int) $row->entry_number;
                }
                if ($row->prev_hash !== $previous) {
                    $problems[] = "Entry #{$row->entry_number} does not follow the hash of the entry before it.";
                }
                $previous = $row->entry_hash;
                $expected++;
            }
        });

        return $this->check('entry_numbers', $problems === [], 'Entry numbers are gap-free and the hash chain is intact.', 'Entry numbering or the hash chain is broken.', array_slice($problems, 0, 25));
    }

    private function reversals(LedgerContext $context): array
    {
        $problems = [];

        $reversed = $this->owned($context, 'journal_entries as e')->where('e.status', 'reversed')
            ->leftJoin('journal_entries as r', 'r.id', '=', 'e.reversed_by_entry_id')
            ->where(fn ($q) => $q->whereNull('r.id')->orWhereColumn('r.reverses_entry_id', '<>', 'e.id')->orWhereNull('r.reverses_entry_id'))
            ->limit(25)->pluck('e.entry_number');
        foreach ($reversed as $n) {
            $problems[] = "Entry #{$n} is marked reversed but its reversal does not point back at it.";
        }

        $orphans = $this->owned($context, 'journal_entries as r')->whereNotNull('r.reverses_entry_id')
            ->join('journal_entries as o', 'o.id', '=', 'r.reverses_entry_id')
            ->where(fn ($q) => $q->where('o.status', '<>', 'reversed')->orWhereColumn('o.reversed_by_entry_id', '<>', 'r.id'))
            ->limit(25)->pluck('r.entry_number');
        foreach ($orphans as $n) {
            $problems[] = "Reversal #{$n} reverses an entry that is not marked reversed by it.";
        }

        return $this->check('reversals', $problems === [], 'Reversal links agree both ways.', 'Reversal links are inconsistent.', $problems);
    }

    private function periods(LedgerContext $context, ?LedgerSetting $settings): array
    {
        $problems = [];

        $outside = $this->owned($context, 'journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.period_id')
            ->where(fn ($q) => $q->whereColumn('e.entry_date', '<', 'p.start_date')->orWhereColumn('e.entry_date', '>', 'p.end_date'))
            ->limit(25)->pluck('e.entry_number');
        foreach ($outside as $n) {
            $problems[] = "Entry #{$n} is dated outside its period.";
        }

        // Nothing may be posted into a month after that month was closed (until it is reopened and closed again).
        $late = $this->owned($context, 'journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.period_id')
            ->where('p.status', 'closed')->whereNotNull('p.closed_at')
            ->whereColumn('e.posted_at', '>', 'p.closed_at')
            ->limit(25)->pluck('e.entry_number');
        foreach ($late as $n) {
            $problems[] = "Entry #{$n} was posted into a period after it was closed.";
        }

        $through = DB::table('accounting_periods')
            ->where(fn ($q) => $context->isCompany() ? $q->where('team_id', $context->teamId) : $q->where('user_id', $context->userId))
            ->where('status', 'closed')->max('end_date');
        if (($settings?->books_closed_through?->toDateString()) !== ($through !== null ? substr((string) $through, 0, 10) : null)) {
            $problems[] = 'books_closed_through ('.($settings?->books_closed_through?->toDateString() ?? 'none').') does not match the last closed period ('.($through !== null ? substr((string) $through, 0, 10) : 'none').').';
        }

        return $this->check('periods', $problems === [], 'Periods and the closed-through date are consistent.', 'Period integrity is broken.', $problems);
    }

    private function isolation(LedgerContext $context): array
    {
        $differs = fn (string $a, string $b) => "(COALESCE({$a}.user_id, 0) <> COALESCE({$b}.user_id, 0) OR COALESCE({$a}.team_id, 0) <> COALESCE({$b}.team_id, 0))";
        $problems = [];

        foreach ([['gl_accounts', 'gl_account_id', 'g'], ['accounts', 'cash_account_id', 'a'], ['categories', 'category_id', 'c'], ['journal_entries', 'journal_entry_id', 'e']] as [$table, $column, $alias]) {
            $n = $this->owned($context, 'journal_lines as l')->join("{$table} as {$alias}", "{$alias}.id", '=', "l.{$column}")->whereRaw($differs('l', $alias))->count();
            if ($n > 0) {
                $problems[] = "{$n} journal line(s) point (via {$column}) at a record of another ledger.";
            }
        }

        return $this->check('ledger_isolation', $problems === [], 'No journal row references another ledger.', 'Journal rows cross ledgers.', $problems);
    }

    private function pending(LedgerContext $context): array
    {
        $p = $this->sweeper->pending($context);
        $count = count($p['transactions']) + count($p['transfers']) + count($p['openings']);

        return $this->check('pending_sources', $count === 0, 'The journals reflect every cash-ledger row.', "{$count} cash-ledger row(s) are not reflected in the journals (run finance:gl:post-pending).", [
            count($p['transactions']).' transaction(s)', count($p['transfers']).' transfer(s)', count($p['openings']).' opening balance(s)',
        ]);
    }

    private function owned(LedgerContext $context, string $table): \Illuminate\Database\Query\Builder
    {
        $alias = trim(explode(' as ', $table)[1] ?? $table);
        $query = DB::table($table);

        return $context->isCompany()
            ? $query->where("{$alias}.team_id", $context->teamId)
            : $query->where("{$alias}.user_id", $context->userId)->whereNull("{$alias}.team_id");
    }
}
