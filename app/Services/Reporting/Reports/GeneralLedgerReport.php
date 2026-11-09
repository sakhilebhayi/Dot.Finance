<?php

namespace App\Services\Reporting\Reports;

use App\Models\Accounting\GlAccount;
use App\Services\Accounting\GlQuery;
use App\Services\Reporting\Books;
use App\Services\Reporting\Reconciliations;
use App\Services\Reporting\ReportRequest;
use App\Services\Reporting\ReportResult;
use Carbon\CarbonImmutable;

/**
 * The general ledger: each account's lines in date order with a running balance (debit positive), from an opening
 * balance at the start of the period. One account, or every account that has activity when none is chosen.
 */
class GeneralLedgerReport extends Report
{
    public const PAGE_ACCOUNT_LIMIT = 400;

    /** The page shows at most this many lines (a year of one busy account can be tens of thousands); an export has no cap. */
    public const PAGE_LINE_LIMIT = 1000;

    public function __construct(Books $books, Reconciliations $recon, private readonly GlQuery $query)
    {
        parent::__construct($books, $recon);
    }

    public function key(): string
    {
        return 'general-ledger';
    }

    public function title(): string
    {
        return 'General ledger';
    }

    public function description(): string
    {
        return 'Every line posted to an account in date order, with a running balance and a link to the journal and its source.';
    }

    public function optionKeys(): array
    {
        return ['account', 'cap'];
    }

    public function group(): string
    {
        return 'ledgers';
    }

    public function estimatedRows(ReportRequest $r): int
    {
        $q = $this->query->ownedLines($r->context)->whereBetween('l.entry_date', [$r->period->from, $r->period->to]);
        if ($r->option('account')) {
            $q->where('l.gl_account_id', (int) $r->option('account'));
        }

        return (int) $q->count();
    }

    public function build(ReportRequest $r): ReportResult
    {
        $accounts = $this->books->accounts($r->context);
        $only = $r->option('account') ? (int) $r->option('account') : null;
        if ($only !== null && ! $accounts->has($only)) {
            abort(404);
        }

        $from = $r->period->from;
        $to = $r->period->to;
        $rows = [];

        $selected = $accounts->filter(fn (GlAccount $a) => $only === null || $a->id === $only);

        // One grouped query for the openings and one for the lines of every selected account (no query per account).
        $opening = $this->query->ownedLines($r->context)->where('l.entry_date', '<', $from)->groupBy('l.gl_account_id')
            ->selectRaw('l.gl_account_id, COALESCE(SUM(l.debit_cents), 0) - COALESCE(SUM(l.credit_cents), 0) AS net')->pluck('net', 'gl_account_id');

        $capped = $r->option('cap') === '1';
        $lines = $this->query->ownedLines($r->context)->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereBetween('l.entry_date', [$from, $to])->when($only !== null, fn ($q) => $q->where('l.gl_account_id', $only))
            ->orderBy('l.gl_account_id')->orderBy('l.entry_date')->orderBy('e.entry_number')->orderBy('l.line_no')
            ->when($capped, fn ($q) => $q->limit(self::PAGE_LINE_LIMIT + 1))
            ->get(['l.id', 'l.gl_account_id', 'l.entry_date', 'l.debit_cents', 'l.credit_cents', 'l.description', 'e.id as entry_id', 'e.entry_number', 'e.memo']);

        $truncated = $capped && $lines->count() > self::PAGE_LINE_LIMIT;
        $lines = ($truncated ? $lines->take(self::PAGE_LINE_LIMIT) : $lines)->groupBy('gl_account_id');

        $shown = 0;
        foreach ($selected as $a) {
            $open = (int) ($opening[$a->id] ?? 0);
            $accountLines = $lines->get($a->id, collect());
            if ($only === null && $open === 0 && $accountLines->isEmpty()) {
                continue;
            }
            if (++$shown > self::PAGE_ACCOUNT_LIMIT) {
                break;
            }

            $rows[] = $this->section($this->accountLabel($a));
            $rows[] = $this->row('line', 'Opening balance', ['balance' => $open], ['balance' => $this->drill('', $this->dayBefore($from), ['account' => $a->id, 'sign' => 'debit'])], 1);
            $running = $open;
            $debits = 0;
            $credits = 0;
            foreach ($accountLines as $l) {
                $running += (int) $l->debit_cents - (int) $l->credit_cents;
                $debits += (int) $l->debit_cents;
                $credits += (int) $l->credit_cents;
                $rows[] = $this->row('line', trim(($l->description ?: $l->memo)), [
                    'date' => substr((string) $l->entry_date, 0, 10), 'ref' => 'JE-'.str_pad((string) $l->entry_number, 6, '0', STR_PAD_LEFT),
                    'debit' => (int) $l->debit_cents ?: null, 'credit' => (int) $l->credit_cents ?: null, 'balance' => $running,
                ], [], 1, route('accounting.journals.show', $l->entry_id));
            }
            $rows[] = $this->row('subtotal', 'Closing balance', ['debit' => $debits, 'credit' => $credits, 'balance' => $running], [
                'debit' => $this->drill($from, $to, ['account' => $a->id, 'sign' => 'debits']),
                'credit' => $this->drill($from, $to, ['account' => $a->id, 'sign' => 'credits']),
                'balance' => $this->drill('', $to, ['account' => $a->id, 'sign' => 'debit']),
            ], 0);
        }

        return new ReportResult($this->key(), $this->title(), $this->header($r, ['Balances are debit positive. Opening balance is everything posted before the start date.']),
            [$this->col('label', 'Description', 'text'), $this->col('date', 'Date', 'date'), $this->col('ref', 'Entry', 'text'), $this->col('debit', 'Debit'), $this->col('credit', 'Credit'), $this->col('balance', 'Balance')],
            $rows, [], array_values(array_filter([
                $shown > self::PAGE_ACCOUNT_LIMIT ? 'Only the first '.self::PAGE_ACCOUNT_LIMIT.' accounts are shown. Choose an account, or export.' : null,
                $truncated ? 'Showing the first '.number_format(self::PAGE_LINE_LIMIT).' lines. The balances after the last line shown are not the closing balance. Narrow the dates or choose an account, or export the whole ledger.' : null,
            ])),
            $rows === [] ? 'No lines in this period.' : null);
    }

    private function dayBefore(string $date): string
    {
        return CarbonImmutable::parse($date)->subDay()->toDateString();
    }
}
