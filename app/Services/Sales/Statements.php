<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesRefund;
use App\Models\Sales\SalesWriteOff;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A customer statement: what the customer owed at the start of a period, every invoice, credit note, payment,
 * refund and write-off in it, and what they owe at the end, plus the open invoices with their age. One section
 * per currency, in that currency (a customer can owe in several). The closing balance is the same figure the
 * receivables ledger gives for the customer, because both come from the same documents.
 */
final class Statements
{
    public function __construct(private readonly ApplicationEngine $engine) {}

    /**
     * @return array{party: Party, from: string, to: string, sections: list<array<string, mixed>>}
     */
    public function build(LedgerContext $context, Party $party, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return LedgerContext::run($context, function () use ($context, $party, $from, $to) {
            $events = [];

            foreach (SalesDocument::query()->forContext($context)->where('party_id', $party->id)->whereNotNull('sealed_at')->where('status', '<>', 'void')
                ->whereIn('kind', ['tax_invoice', 'debit_note', 'credit_note'])->get() as $d) {
                $credit = $d->isCredit();
                $events[$d->currency][] = [
                    'date' => $d->issue_date->toDateString(), 'sort' => 1, 'type' => $credit ? 'credit_note' : ($d->kind === 'debit_note' ? 'debit_note' : 'invoice'),
                    'ref' => $d->number, 'description' => $d->title().' '.$d->number.($d->reference ? ' ('.$d->reference.')' : ''), 'delta' => $credit ? -(int) $d->total_cents : (int) $d->total_cents,
                    'due' => $d->due_date?->toDateString(), 'document_id' => $d->id,
                ];
            }

            foreach (Payment::query()->forContext($context)->where('party_id', $party->id)->where('status', 'recorded')->get() as $p) {
                $isRefund = $p->direction === 'out';
                if ($isRefund && ! SalesRefund::query()->forContext($context)->where('payment_id', $p->id)->where('status', 'recorded')->exists()) {
                    continue;
                }
                $events[$p->currency][] = [
                    'date' => $p->paid_on->toDateString(), 'sort' => 2, 'type' => $isRefund ? 'refund' : 'payment', 'ref' => $p->reference,
                    'description' => ($isRefund ? 'Refund paid' : 'Payment received').($p->reference ? ' ('.$p->reference.')' : ''), 'delta' => $isRefund ? (int) $p->amount_cents : -(int) $p->amount_cents,
                    'due' => null, 'document_id' => null,
                ];
            }

            foreach (SalesWriteOff::query()->forContext($context)->where('status', 'applied')->whereHas('document', fn ($q) => $q->where('party_id', $party->id))->with('document')->get() as $w) {
                $events[$w->document->currency][] = [
                    'date' => $w->applied_at->toDateString(), 'sort' => 3, 'type' => 'write_off', 'ref' => $w->document->number,
                    'description' => 'Written off: '.$w->document->number, 'delta' => -(int) $w->amount_cents, 'due' => null, 'document_id' => $w->document_id,
                ];
            }

            $open = app(ReceivablesLedger::class)->openItems($context, $party->id)->groupBy('currency');
            $sections = [];

            foreach (array_unique(array_merge(array_keys($events), $open->keys()->all())) as $currency) {
                $rows = collect($events[$currency] ?? [])->sortBy([['date', 'asc'], ['sort', 'asc']])->values();
                $opening = (int) $rows->filter(fn ($r) => $r['date'] < $from->toDateString())->sum('delta');
                $inPeriod = $rows->filter(fn ($r) => $r['date'] >= $from->toDateString() && $r['date'] <= $to->toDateString())->values();

                $running = $opening;
                $lines = $inPeriod->map(function ($r) use (&$running) {
                    $running += $r['delta'];

                    return $r + ['balance' => $running];
                })->all();

                $ageing = array_fill_keys(array_keys(ReceivablesLedger::BUCKETS), 0);
                $openRows = [];
                foreach ($open->get($currency, collect()) as $item) {
                    /** @var SalesDocument $d */
                    $d = $item['document'];
                    $days = (int) CarbonImmutable::parse(($d->due_date ?? $d->issue_date)->toDateString())->diffInDays($to, false);
                    $bucket = match (true) {
                        $days <= 0 => 'current', $days <= 30 => 'd30', $days <= 60 => 'd60', $days <= 90 => 'd90', $days <= 120 => 'd120', default => 'd120p'
                    };
                    $ageing[$bucket] += $item['open_cents'];
                    $openRows[] = ['number' => $d->number, 'issued' => $d->issue_date->toDateString(), 'due' => $d->due_date?->toDateString(), 'total_cents' => (int) $d->total_cents, 'open_cents' => $item['open_cents'], 'days_past_due' => max(0, $days)];
                }

                $sections[] = [
                    'currency' => $currency, 'opening_cents' => $opening, 'lines' => $lines, 'closing_cents' => $running,
                    'balance_now_cents' => (int) $rows->sum('delta'), 'open' => $openRows, 'ageing' => $ageing,
                ];
            }

            return ['party' => $party, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'sections' => $sections];
        });
    }

    /** CSV rows for a statement, formula-safe (a cell that begins = + - @ is prefixed so a spreadsheet will not run it). @return list<list<string>> */
    public function csv(array $statement): array
    {
        $safe = fn (string $v) => $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
        $rows = [['Statement for', $safe($statement['party']->name), 'From', $statement['from'], 'To', $statement['to']], []];

        foreach ($statement['sections'] as $section) {
            $rows[] = ['Currency', $section['currency']];
            $rows[] = ['Date', 'Type', 'Description', 'Amount', 'Balance'];
            $rows[] = [$statement['from'], 'Opening balance', '', '', Money::toDecimalString($section['opening_cents'])];
            foreach ($section['lines'] as $line) {
                $rows[] = [$line['date'], str_replace('_', ' ', $line['type']), $safe((string) $line['description']), Money::toDecimalString($line['delta']), Money::toDecimalString($line['balance'])];
            }
            $rows[] = [$statement['to'], 'Closing balance', '', '', Money::toDecimalString($section['closing_cents'])];
            $rows[] = [];
        }

        return $rows;
    }
}
