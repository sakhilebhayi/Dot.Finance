<?php

namespace App\Services\Sales;

use App\Models\Accounting\JournalLine;
use App\Models\Sales\SalesApplication;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesRefund;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Models\Shared\PaymentAllocation;
use App\Services\Accounting\Chart;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Receivables, worked out from the documents and the applications, never from the journal: open items, what
 * each customer owes, ageing, and the check that the two agree.
 *
 *   what is owed   = open invoices and debit notes
 *                  - credit notes not yet applied or refunded
 *                  - payments received and not yet applied or refunded   (a customer credit)
 *
 * All sums are in the ledger's base currency (each document at the rate it was booked at), because that is how
 * the receivables account is kept. `reconcile()` sets that figure against the receivables account of the general
 * ledger, in total and customer by customer; any difference is shown, with its size, never hidden.
 *
 * Reads are bulk (a handful of grouped queries), not one query per invoice.
 */
final class ReceivablesLedger
{
    /** Bucket keys in order. Days past the due date (or past the invoice date, on the other basis). */
    public const BUCKETS = ['current' => 'Current', 'd30' => '1 to 30', 'd60' => '31 to 60', 'd90' => '61 to 90', 'd120' => '91 to 120', 'd120p' => 'Over 120'];

    public function __construct(private readonly Chart $chart) {}

    /**
     * Every invoice and debit note that still owes something.
     *
     * @return Collection<int, array{document: SalesDocument, party_id: int, currency: string, open_cents: int, open_base_cents: int, paid_cents: int}>
     */
    public function openItems(LedgerContext $context, ?int $partyId = null): Collection
    {
        // the payment relation below is read through its own ledger scope, so the context is made current
        return LedgerContext::run($context, fn () => $this->loadOpenItems($context, $partyId));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function loadOpenItems(LedgerContext $context, ?int $partyId): Collection
    {
        $documents = SalesDocument::query()->forContext($context)->open()
            ->when($partyId, fn ($q) => $q->where('party_id', $partyId))
            ->with('party')->orderBy('due_date')->orderBy('id')->get();

        if ($documents->isEmpty()) {
            return collect();
        }

        $ids = $documents->pluck('id');
        $credit = SalesApplication::query()->forContext($context)->whereIn('target_document_id', $ids)->where('kind', 'credit')->whereNull('released_at')
            ->selectRaw('target_document_id, SUM(amount_cents) as cents')->groupBy('target_document_id')->pluck('cents', 'target_document_id');
        $clearedBase = SalesApplication::query()->forContext($context)->whereIn('target_document_id', $ids)->whereNull('released_at')
            ->selectRaw('target_document_id, SUM(target_base_cents) as cents')->groupBy('target_document_id')->pluck('cents', 'target_document_id');
        $paid = PaymentAllocation::query()->forContext($context)->where('allocatable_type', (new SalesDocument)->getMorphClass())->whereIn('allocatable_id', $ids)
            ->whereNull('released_at')->whereHas('payment', fn ($q) => $q->where('status', 'recorded'))
            ->selectRaw('allocatable_id, SUM(amount_cents) as cents')->groupBy('allocatable_id')->pluck('cents', 'allocatable_id');

        return $documents->map(function (SalesDocument $d) use ($credit, $clearedBase, $paid) {
            $paidCents = (int) ($paid[$d->id] ?? 0);
            $open = (int) $d->total_cents - (int) ($credit[$d->id] ?? 0) - (int) $d->written_off_cents - $paidCents;
            $openBase = (int) $d->base_total_cents - (int) $d->written_off_base_cents - (int) ($clearedBase[$d->id] ?? 0);

            return ['document' => $d, 'party_id' => (int) $d->party_id, 'currency' => $d->currency, 'open_cents' => $open, 'open_base_cents' => $openBase, 'paid_cents' => $paidCents];
        })->filter(fn (array $row) => $row['open_cents'] > 0 || $row['open_base_cents'] > 0)->values();
    }

    /**
     * Credits a customer holds that are not applied to anything: payments received and credit notes.
     *
     * @return Collection<int, array{kind: string, id: int, label: string, date: CarbonImmutable, party_id: int, currency: string, cents: int, base_cents: int, model: Payment|SalesDocument}>
     */
    public function unappliedCredits(LedgerContext $context, ?int $partyId = null): Collection
    {
        $out = collect();

        $payments = Payment::query()->forContext($context)->where('direction', 'in')->where('status', 'recorded')->whereNotNull('party_id')
            ->when($partyId, fn ($q) => $q->where('party_id', $partyId))->get();
        if ($payments->isNotEmpty()) {
            $ids = $payments->pluck('id');
            $allocated = PaymentAllocation::query()->forContext($context)->whereIn('payment_id', $ids)->whereNull('released_at')
                ->selectRaw('payment_id, SUM(amount_cents) as cents')->groupBy('payment_id')->pluck('cents', 'payment_id');
            $usedBase = SalesApplication::query()->forContext($context)->whereIn('payment_id', $ids)->whereNull('released_at')
                ->selectRaw('payment_id, SUM(source_base_cents) as cents')->groupBy('payment_id')->pluck('cents', 'payment_id');
            $refundedBase = SalesRefund::query()->forContext($context)->whereIn('source_payment_id', $ids)->where('status', 'recorded')
                ->selectRaw('source_payment_id, SUM(source_base_cents) as cents')->groupBy('source_payment_id')->pluck('cents', 'source_payment_id');

            foreach ($payments as $p) {
                $left = (int) $p->amount_cents - (int) ($allocated[$p->id] ?? 0);
                $total = $p->base_amount_cents !== null ? (int) $p->base_amount_cents : (int) $p->amount_cents;
                $leftBase = $total - (int) ($usedBase[$p->id] ?? 0) - (int) ($refundedBase[$p->id] ?? 0);
                if ($left > 0 || $leftBase > 0) {
                    $out->push(['kind' => 'payment', 'id' => $p->id, 'label' => 'Payment received '.$p->paid_on->toDateString().($p->reference ? ' ('.$p->reference.')' : ''), 'date' => CarbonImmutable::parse($p->paid_on->toDateString()),
                        'party_id' => (int) $p->party_id, 'currency' => $p->currency, 'cents' => $left, 'base_cents' => $leftBase, 'model' => $p]);
                }
            }
        }

        $credits = SalesDocument::query()->forContext($context)->where('kind', 'credit_note')->whereNotNull('sealed_at')->where('status', '<>', 'void')
            ->when($partyId, fn ($q) => $q->where('party_id', $partyId))->get();
        if ($credits->isNotEmpty()) {
            $ids = $credits->pluck('id');
            $applied = SalesApplication::query()->forContext($context)->whereIn('source_document_id', $ids)->whereNull('released_at')
                ->selectRaw('source_document_id, SUM(amount_cents) as cents, SUM(source_base_cents) as base')->groupBy('source_document_id')->get()->keyBy('source_document_id');
            $refunded = SalesRefund::query()->forContext($context)->whereIn('source_document_id', $ids)->where('status', 'recorded')
                ->selectRaw('source_document_id, SUM(amount_cents) as cents, SUM(source_base_cents) as base')->groupBy('source_document_id')->get()->keyBy('source_document_id');

            foreach ($credits as $c) {
                $left = (int) $c->total_cents - (int) ($applied[$c->id]->cents ?? 0) - (int) ($refunded[$c->id]->cents ?? 0);
                $leftBase = (int) $c->base_total_cents - (int) ($applied[$c->id]->base ?? 0) - (int) ($refunded[$c->id]->base ?? 0);
                if ($left > 0 || $leftBase > 0) {
                    $out->push(['kind' => 'credit_note', 'id' => $c->id, 'label' => 'Credit note '.$c->number, 'date' => CarbonImmutable::parse($c->issue_date->toDateString()),
                        'party_id' => (int) $c->party_id, 'currency' => $c->currency, 'cents' => $left, 'base_cents' => $leftBase, 'model' => $c]);
                }
            }
        }

        return $out->sortBy('date')->values();
    }

    /** What one customer owes, in base currency (invoices less credits and unapplied money). */
    public function customerBalanceBase(LedgerContext $context, int $partyId): int
    {
        return (int) $this->openItems($context, $partyId)->sum('open_base_cents') - (int) $this->unappliedCredits($context, $partyId)->sum('base_cents');
    }

    /**
     * Ageing by customer. Columns are days past due (or past the invoice date), in base currency; "on account" is the
     * customer credit (unapplied payments and credit notes), shown as a negative.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>, as_of: string, basis: string}
     */
    public function ageing(LedgerContext $context, string $basis = 'due', ?CarbonImmutable $asOf = null, ?int $partyId = null): array
    {
        $asOf ??= CarbonImmutable::today();
        $rows = [];
        $blank = fn () => array_fill_keys(array_keys(self::BUCKETS), 0) + ['on_account' => 0, 'total' => 0, 'count' => 0];

        foreach ($this->openItems($context, $partyId) as $item) {
            /** @var SalesDocument $d */
            $d = $item['document'];
            $anchor = CarbonImmutable::parse(($basis === 'invoice' ? $d->issue_date : ($d->due_date ?? $d->issue_date))->toDateString());
            $days = (int) $anchor->diffInDays($asOf, false);
            $bucket = match (true) {
                $days <= 0 => 'current', $days <= 30 => 'd30', $days <= 60 => 'd60', $days <= 90 => 'd90', $days <= 120 => 'd120', default => 'd120p',
            };

            $rows[$item['party_id']] ??= ['party' => $d->party] + $blank();
            $rows[$item['party_id']][$bucket] += $item['open_base_cents'];
            $rows[$item['party_id']]['total'] += $item['open_base_cents'];
            $rows[$item['party_id']]['count']++;
        }

        foreach ($this->unappliedCredits($context, $partyId) as $credit) {
            $rows[$credit['party_id']] ??= ['party' => Party::query()->forContext($context)->withTrashed()->find($credit['party_id'])] + $blank();
            $rows[$credit['party_id']]['on_account'] -= $credit['base_cents'];
            $rows[$credit['party_id']]['total'] -= $credit['base_cents'];
        }

        usort($rows, fn ($a, $b) => strcasecmp((string) $a['party']?->name, (string) $b['party']?->name));
        $totals = $blank();
        foreach ($rows as $row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $row[$key];
            }
        }

        return ['rows' => array_values($rows), 'totals' => $totals, 'as_of' => $asOf->toDateString(), 'basis' => $basis];
    }

    /** The receivables account of the general ledger: debits less credits, in base currency. */
    public function glBalance(LedgerContext $context): int
    {
        $ar = $this->chart->systemIds($context)['ar'] ?? null;

        return $ar === null ? 0 : (int) JournalLine::query()->forContext($context)->where('gl_account_id', $ar)->selectRaw('COALESCE(SUM(debit_cents), 0) - COALESCE(SUM(credit_cents), 0) as net')->value('net');
    }

    /** @return array<int|string, int> party id (or 0 for no customer) => net debit */
    public function glBalanceByParty(LedgerContext $context): array
    {
        $ar = $this->chart->systemIds($context)['ar'] ?? null;
        if ($ar === null) {
            return [];
        }

        $out = [];
        foreach (JournalLine::query()->forContext($context)->where('gl_account_id', $ar)->selectRaw('party_id, SUM(debit_cents) - SUM(credit_cents) as net')->groupBy('party_id')->get() as $row) {
            $out[(int) $row->party_id] = (int) $row->net;
        }

        return $out;
    }

    /**
     * Does receivables, worked out from the documents, equal the receivables account? In total and per customer.
     *
     * @return array{ok: bool, documents_cents: int, ledger_cents: int, difference_cents: int, parties: list<array{party_id: int, name: string, documents_cents: int, ledger_cents: int, difference_cents: int}>, unattributed_cents: int}
     */
    public function reconcile(LedgerContext $context): array
    {
        $subledger = [];
        foreach ($this->openItems($context) as $item) {
            $subledger[$item['party_id']] = ($subledger[$item['party_id']] ?? 0) + $item['open_base_cents'];
        }
        foreach ($this->unappliedCredits($context) as $credit) {
            $subledger[$credit['party_id']] = ($subledger[$credit['party_id']] ?? 0) - $credit['base_cents'];
        }

        $gl = $this->glBalanceByParty($context);
        $names = Party::query()->forContext($context)->withTrashed()->whereIn('id', array_values(array_unique(array_merge(array_keys($subledger), array_keys($gl)))))->pluck('name', 'id');

        $parties = [];
        foreach (array_unique(array_merge(array_keys($subledger), array_keys($gl))) as $partyId) {
            if ((int) $partyId === 0) {
                continue;
            }
            $a = (int) ($subledger[$partyId] ?? 0);
            $b = (int) ($gl[$partyId] ?? 0);
            if ($a !== 0 || $b !== 0) {
                $parties[] = ['party_id' => (int) $partyId, 'name' => (string) ($names[$partyId] ?? 'Customer #'.$partyId), 'documents_cents' => $a, 'ledger_cents' => $b, 'difference_cents' => $b - $a];
            }
        }
        usort($parties, fn ($x, $y) => strcasecmp($x['name'], $y['name']));

        $documents = (int) array_sum($subledger);
        $ledger = $this->glBalance($context);

        return [
            'ok' => $documents === $ledger, 'documents_cents' => $documents, 'ledger_cents' => $ledger, 'difference_cents' => $ledger - $documents,
            'parties' => $parties, 'unattributed_cents' => (int) ($gl[0] ?? 0),
        ];
    }

    /**
     * Figures for the Command centre panel: what is owed, what is overdue, what falls due this week, and whether
     * the receivables account agrees.
     *
     * @return array<string, mixed>
     */
    public function summary(LedgerContext $context, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $items = $this->openItems($context);
        $credits = $this->unappliedCredits($context);

        $overdue = $items->filter(fn ($i) => $i['document']->isOverdue($today));
        $dueSoon = $items->filter(fn ($i) => ! $i['document']->isOverdue($today) && $i['document']->due_date !== null && $i['document']->due_date->toDateString() <= $today->addDays(7)->toDateString());
        $reconcile = $this->reconcile($context);

        return [
            'owed_base_cents' => (int) $items->sum('open_base_cents'),
            'credit_base_cents' => (int) $credits->sum('base_cents'),
            'net_base_cents' => (int) $items->sum('open_base_cents') - (int) $credits->sum('base_cents'),
            'overdue_base_cents' => (int) $overdue->sum('open_base_cents'),
            'overdue_count' => $overdue->count(),
            'due_soon_base_cents' => (int) $dueSoon->sum('open_base_cents'),
            'due_soon_count' => $dueSoon->count(),
            'open_count' => $items->count(),
            'reconciles' => $reconcile['ok'],
            'difference_cents' => $reconcile['difference_cents'],
            'oldest_overdue_days' => $overdue->isEmpty() ? 0 : (int) $overdue->map(fn ($i) => (int) CarbonImmutable::parse($i['document']->due_date->toDateString())->diffInDays($today))->max(),
        ];
    }
}
