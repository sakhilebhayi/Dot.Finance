<?php

namespace App\Services\Purchases;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalLine;
use App\Models\Purchases\Bill;
use App\Models\Purchases\ExpenseClaim;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Models\Shared\PaymentAllocation;
use App\Services\Accounting\Chart;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The accounts-payable subledger: what is owed to each supplier, document by document, aged, and the check that it equals the
 * accounts-payable account in the books.
 *
 * Everything is read from rows, nothing is stored:
 *   a posted bill or claim      owes  total - posted credit notes against it - live allocations of recorded payments
 *   an unapplied credit note    is a (negative) amount the supplier owes us
 *   money paid on account       is a (negative) amount too: a debit balance with the supplier
 * In base currency the same three things are valued at what the books carry them at (see Settlement), so the sum of every
 * supplier's balance equals the credit balance of the payable account, to the cent. reconcile() proves it, supplier by supplier.
 * Balances are as they stand now; the "as at" date only moves the age buckets.
 */
final class ApLedger
{
    public const BUCKETS = ['current', 'd1_30', 'd31_60', 'd61_90', 'd91_120', 'over_120'];

    public const BUCKET_LABELS = ['current' => 'Not yet due', 'd1_30' => '1-30 days', 'd31_60' => '31-60 days', 'd61_90' => '61-90 days', 'd91_120' => '91-120 days', 'over_120' => 'Over 120 days'];

    public function __construct(private readonly Chart $chart) {}

    /**
     * Every open item, newest-owed first by due date.
     *
     * @return Collection<int, array<string, mixed>> each: type (bill|expense_claim|credit_note|on_account), id, route, number, party_id, party, date, due_date, currency, total, open, open_base, days_overdue, bucket
     */
    public function openItems(LedgerContext $context, ?CarbonImmutable $asAt = null, ?int $partyId = null): Collection
    {
        $asAt ??= CarbonImmutable::today();

        return LedgerContext::run($context, function () use ($context, $asAt, $partyId) {
            $items = collect();

            $bills = Bill::query()->with('party')->whereIn('status', Bill::POSTED)->when($partyId, fn ($q) => $q->where('party_id', $partyId))->get();
            $claims = ExpenseClaim::query()->with('party')->whereIn('status', Bill::POSTED)->when($partyId, fn ($q) => $q->where('party_id', $partyId))->get();

            // live allocations of recorded payments, summed per document, in two queries
            $allocated = $this->allocationSums();
            $credits = Bill::query()->where('kind', 'credit_note')->whereNotNull('credit_for_bill_id')->whereIn('status', Bill::POSTED)
                ->get(['credit_for_bill_id', 'total_cents', 'base_total_cents'])->groupBy('credit_for_bill_id');

            foreach ($bills as $bill) {
                if ($bill->isCreditNote()) {
                    if ($bill->credit_for_bill_id === null && $bill->status === 'open') {
                        $items->push($this->item($bill, 'credit_note', -$bill->total_cents, -($bill->base_total_cents ?? $bill->total_cents), $asAt));
                    }

                    continue;
                }

                $paid = $allocated['bill'][$bill->id] ?? ['amount' => 0, 'relief' => 0];
                $creditRows = $credits->get($bill->id, collect());
                $open = $bill->total_cents - (int) $creditRows->sum('total_cents') - $paid['amount'];
                $openBase = ($bill->base_total_cents ?? $bill->total_cents) - (int) $creditRows->sum('base_total_cents') - $paid['relief'];

                if ($open > 0) {
                    $items->push($this->item($bill, 'bill', $open, $openBase, $asAt));
                }
            }

            foreach ($claims as $claim) {
                $paid = $allocated['expense_claim'][$claim->id] ?? ['amount' => 0, 'relief' => 0];
                $open = $claim->total_cents - $paid['amount'];

                if ($open > 0) {
                    $items->push($this->item($claim, 'expense_claim', $open, $claim->total_cents - $paid['relief'], $asAt));
                }
            }

            $settlement = app(Settlement::class);
            Payment::query()->with('party')->where('direction', 'out')->where('status', 'recorded')->when($partyId, fn ($q) => $q->where('party_id', $partyId))->get()
                ->each(function (Payment $payment) use ($settlement, $items, $asAt) {
                    $unapplied = $settlement->unappliedOf($payment);
                    if ($unapplied['cents'] > 0) {
                        $items->push([
                            'type' => 'on_account', 'id' => $payment->id, 'route' => null, 'number' => 'Payment '.($payment->reference ?? '#'.$payment->id), 'party_id' => $payment->party_id,
                            'party' => $payment->party?->name ?? 'Unknown supplier', 'date' => $payment->paid_on->toDateString(), 'due_date' => null, 'currency' => $payment->currency,
                            'total' => -$payment->amount_cents, 'open' => -$unapplied['cents'], 'open_base' => -$unapplied['base_cents'], 'days_overdue' => 0, 'bucket' => 'current',
                        ]);
                    }
                });

            return $items->sortBy([['party', 'asc'], ['due_date', 'asc']])->values();
        });
    }

    /**
     * Ageing by supplier in base currency.
     *
     * @return array{as_at: string, rows: list<array{party_id: ?int, party: string, buckets: array<string, int>, total: int}>, totals: array<string, int>, grand_total: int}
     */
    public function ageing(LedgerContext $context, ?CarbonImmutable $asAt = null): array
    {
        $asAt ??= CarbonImmutable::today();
        $rows = [];
        $totals = array_fill_keys(self::BUCKETS, 0);

        foreach ($this->openItems($context, $asAt)->groupBy('party_id') as $partyId => $items) {
            $buckets = array_fill_keys(self::BUCKETS, 0);
            foreach ($items as $item) {
                $buckets[$item['bucket']] += $item['open_base'];
            }
            $rows[] = ['party_id' => $partyId === '' ? null : (int) $partyId, 'party' => (string) $items->first()['party'], 'buckets' => $buckets, 'total' => array_sum($buckets)];
            foreach ($buckets as $bucket => $amount) {
                $totals[$bucket] += $amount;
            }
        }

        usort($rows, fn ($a, $b) => strcasecmp($a['party'], $b['party']));

        return ['as_at' => $asAt->toDateString(), 'rows' => $rows, 'totals' => $totals, 'grand_total' => array_sum($totals)];
    }

    /**
     * Does the subledger equal the books? Total and supplier by supplier.
     *
     * @return array{ok: bool, books_cents: int, subledger_cents: int, difference_cents: int, suppliers: list<array{party_id: ?int, party: string, books_cents: int, subledger_cents: int, difference_cents: int, ok: bool}>, unassigned_books_cents: int, accounting_on: bool}
     */
    public function reconcile(LedgerContext $context): array
    {
        return LedgerContext::run($context, function () use ($context) {
            $ap = $this->apAccount($context);
            if ($ap === null) {
                return ['ok' => true, 'books_cents' => 0, 'subledger_cents' => 0, 'difference_cents' => 0, 'suppliers' => [], 'unassigned_books_cents' => 0, 'accounting_on' => false];
            }

            // credit balance of the payable account, per supplier (journal lines carry the supplier)
            $books = [];
            JournalLine::query()->where('gl_account_id', $ap->id)->selectRaw('party_id, SUM(credit_cents) - SUM(debit_cents) AS net')->groupBy('party_id')
                ->get()->each(function ($row) use (&$books) {
                    $books[$row->party_id === null ? 0 : (int) $row->party_id] = (int) $row->net;
                });

            $sub = [];
            foreach ($this->openItems($context) as $item) {
                $key = (int) ($item['party_id'] ?? 0);
                $sub[$key] = ($sub[$key] ?? 0) + $item['open_base'];
            }

            $names = Party::query()->withTrashed()->whereIn('id', array_filter(array_unique([...array_keys($books), ...array_keys($sub)])))->pluck('name', 'id');
            $suppliers = [];
            foreach (array_unique([...array_keys($books), ...array_keys($sub)]) as $partyId) {
                $b = $books[$partyId] ?? 0;
                $s = $sub[$partyId] ?? 0;
                if ($b === 0 && $s === 0) {
                    continue;
                }
                $suppliers[] = [
                    'party_id' => $partyId === 0 ? null : $partyId, 'party' => $partyId === 0 ? 'Not tied to a supplier' : (string) ($names[$partyId] ?? 'Supplier #'.$partyId),
                    'books_cents' => $b, 'subledger_cents' => $s, 'difference_cents' => $b - $s, 'ok' => $b === $s,
                ];
            }
            usort($suppliers, fn ($a, $b) => strcasecmp($a['party'], $b['party']));

            $booksTotal = array_sum($books);
            $subTotal = array_sum($sub);

            return [
                'ok' => $booksTotal === $subTotal && collect($suppliers)->every(fn ($s) => $s['ok']),
                'books_cents' => $booksTotal, 'subledger_cents' => $subTotal, 'difference_cents' => $booksTotal - $subTotal,
                'suppliers' => $suppliers, 'unassigned_books_cents' => $books[0] ?? 0, 'accounting_on' => true,
            ];
        });
    }

    public function apAccount(LedgerContext $context): ?GlAccount
    {
        $id = $this->chart->systemIds($context)['ap'] ?? null;

        return $id === null ? null : GlAccount::query()->forContext($context)->find($id);
    }

    /** @return array{bill: array<int, array{amount: int, relief: int}>, expense_claim: array<int, array{amount: int, relief: int}>} */
    private function allocationSums(): array
    {
        $out = ['bill' => [], 'expense_claim' => []];

        PaymentAllocation::query()->whereNull('released_at')->whereIn('allocatable_type', ['bill', 'expense_claim'])
            ->whereHas('payment', fn ($q) => $q->where('status', 'recorded'))
            ->selectRaw('allocatable_type, allocatable_id, SUM(amount_cents) AS amount, SUM(COALESCE(base_relief_cents, amount_cents)) AS relief')
            ->groupBy('allocatable_type', 'allocatable_id')->get()
            ->each(function ($row) use (&$out) {
                $out[$row->allocatable_type][(int) $row->allocatable_id] = ['amount' => (int) $row->amount, 'relief' => (int) $row->relief];
            });

        return $out;
    }

    /** @return array<string, mixed> */
    private function item(Bill|ExpenseClaim $doc, string $type, int $open, int $openBase, CarbonImmutable $asAt): array
    {
        $due = $doc instanceof Bill ? $doc->due_date : $doc->claim_date;
        $date = $doc instanceof Bill ? $doc->bill_date : $doc->claim_date;
        $overdue = $due === null || $type === 'credit_note' ? 0 : max(0, (int) CarbonImmutable::parse($due->toDateString())->diffInDays($asAt, false));

        return [
            'type' => $type, 'id' => $doc->id, 'route' => $doc instanceof Bill ? 'purchases.bills.show' : 'purchases.claims.show', 'number' => $doc->displayNumber(),
            'party_id' => $doc->party_id, 'party' => $doc->party?->name ?? 'Unknown supplier', 'date' => $date->toDateString(), 'due_date' => $due?->toDateString(),
            'currency' => $doc->currency, 'total' => $doc instanceof Bill ? $doc->total_cents : $doc->total_cents, 'open' => $open, 'open_base' => $openBase,
            'days_overdue' => $overdue, 'bucket' => self::bucket($overdue),
        ];
    }

    public static function bucket(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => 'd1_30',
            $daysOverdue <= 60 => 'd31_60',
            $daysOverdue <= 90 => 'd61_90',
            $daysOverdue <= 120 => 'd91_120',
            default => 'over_120',
        };
    }
}
