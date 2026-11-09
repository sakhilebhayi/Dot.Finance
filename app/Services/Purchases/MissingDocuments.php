<?php

namespace App\Services\Purchases;

use App\Models\Purchases\Bill;
use App\Models\Purchases\ExpenseClaimLine;
use App\Models\Purchases\PurchaseSetting;
use App\Models\Shared\Attachment;
use App\Models\Transaction;
use App\Services\Accounting\SettlementTransactions;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Spending with no document behind it. The tax authority wants a supporting document for what you claim, and the books are
 * only as good as the paper behind them, so this lists:
 *
 *   bill          a supplier bill that is on its way into the books or in them (not a draft, not void) with no file attached
 *   expense_claim a receipt on a submitted claim with no file attached
 *   transaction   an expense in the cash ledger (not a payment of a bill, which the bill's own document supports) with no file attached in the last 90 days;
 *                 only for a ledger that has accounting on
 *
 * ... when the amount is at or above the ledger's threshold (Purchases > Settings; by default the tax rule "no tax invoice is needed
 * under" amount, R50 for South Africa). It is the single source for "missing documents" in the product: the Tax module reads it
 * through this service, never by querying the tables.
 *
 *   $items = app(MissingDocuments::class)->forLedger($ledger);   // Collection of the arrays described below
 *
 * Each item: type, id, label, date (Y-m-d), amount_cents (in `currency`), currency, base_cents, party (name or null), reason, threshold_cents,
 * view (route name), view_parameters, attach (the same, for the page that takes an upload), counts_for_tax (true when the VAT on it
 * would be claimed, so the missing document puts that claim at risk).
 */
final class MissingDocuments
{
    private const TRANSACTION_LIMIT = 500;

    /** Cash-ledger expenses are chased for this many days back; older ones are not nagged about (the books keep them regardless). */
    private const TRANSACTION_DAYS = 90;

    public function __construct(private readonly PurchaseLedger $ledger) {}

    /**
     * The items, newest first. With a $limit only that many are built (each type is limited in the query, then the newest
     * $limit overall are kept), so a page that shows six does not read a ledger's worth.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forLedger(LedgerContext $context, ?int $limit = null): Collection
    {
        return LedgerContext::run($context, function () use ($context, $limit) {
            $threshold = PurchaseSetting::forLedger($context)->missingDocumentThresholdCents();
            $items = collect();

            $withFile = fn (string $type) => Attachment::query()->where('attachable_type', $type)->whereNull('archived_at')->pluck('attachable_id')->map(fn ($id) => (int) $id)->flip();
            $noFile = fn (string $type, string $table) => fn ($q) => $q->select(DB::raw('1'))->from('attachments')
                ->where('attachments.attachable_type', $type)->whereNull('attachments.archived_at')->whereColumn('attachments.attachable_id', $table.'.id')
                ->where(fn ($w) => $context->isCompany() ? $w->where('attachments.team_id', $context->teamId) : $w->where('attachments.user_id', $context->userId)->whereNull('attachments.team_id'));

            // the threshold and the missing file are decided in the query, so only the bills that belong in the list are read
            Bill::query()->with('party')->whereNotIn('status', ['draft', 'void'])->whereRaw('COALESCE(base_total_cents, total_cents) >= ?', [$threshold])
                ->whereNotExists($noFile((new Bill)->getMorphClass(), 'bills'))->orderByDesc('bill_date')->orderByDesc('id')->when($limit, fn ($q) => $q->limit($limit))->get()
                ->each(function (Bill $bill) use ($threshold, $items) {
                    $base = $bill->baseTotalCents();
                    if ($base < $threshold) {
                        return;
                    }
                    $items->push([
                        'type' => 'bill', 'id' => $bill->id, 'label' => $bill->kindLabel().' '.$bill->displayNumber().($bill->supplier_reference ? ' (supplier ref '.$bill->supplier_reference.')' : ''),
                        'date' => $bill->bill_date->toDateString(), 'amount_cents' => $bill->total_cents, 'currency' => $bill->currency, 'base_cents' => $base,
                        'party' => $bill->party?->name, 'reason' => 'No supplier invoice or credit note is attached.', 'threshold_cents' => $threshold,
                        'view' => 'purchases.bills.show', 'view_parameters' => [$bill->id], 'attach' => 'purchases.bills.show', 'attach_parameters' => [$bill->id],
                        'counts_for_tax' => $bill->claimable_tax_cents > 0,
                    ]);
                });

            ExpenseClaimLine::query()->with('claim')->where('gross_cents', '>=', $threshold)->whereHas('claim', fn ($q) => $q->whereNotIn('status', ['draft', 'void']))
                ->whereNotExists($noFile((new ExpenseClaimLine)->getMorphClass(), 'expense_claim_lines'))->orderByDesc('spent_on')->orderByDesc('id')->when($limit, fn ($q) => $q->limit($limit))->get()
                ->each(function (ExpenseClaimLine $line) use ($threshold, $items) {
                    $items->push([
                        'type' => 'expense_claim', 'id' => $line->id, 'label' => 'Receipt from '.$line->merchant.' on claim '.$line->claim->displayNumber(),
                        'date' => $line->spent_on->toDateString(), 'amount_cents' => $line->gross_cents, 'currency' => $line->claim->currency, 'base_cents' => $line->gross_cents,
                        'party' => $line->merchant, 'reason' => 'No receipt is attached.', 'threshold_cents' => $threshold,
                        'view' => 'purchases.claims.show', 'view_parameters' => [$line->expense_claim_id], 'attach' => 'purchases.claims.show', 'attach_parameters' => [$line->expense_claim_id],
                        'counts_for_tax' => $line->claimable_tax_cents > 0,
                    ]);
                });

            // Cash-ledger expenses are only listed for a ledger that keeps books (accounting on): a casual personal ledger is not nagged for receipts.
            $txFiles = $withFile((new Transaction)->getMorphClass());
            $candidates = $this->ledger->accountingEnabled($context)
                ? Transaction::query()->counted()->where('type', 'expense')->whereNull('transfer_group')->where('amount_cents', '>=', $threshold)->where('date', '>=', now()->subDays(self::TRANSACTION_DAYS)->toDateString())->orderByDesc('date')->limit(self::TRANSACTION_LIMIT)->get()
                : collect();
            $settlement = array_flip(SettlementTransactions::among($context, $candidates->pluck('id')->map(fn ($id) => (int) $id)->all()));

            foreach ($candidates as $tx) {
                if ($txFiles->has($tx->id) || isset($settlement[$tx->id])) {
                    continue;
                }
                $items->push([
                    'type' => 'transaction', 'id' => $tx->id, 'label' => 'Expense: '.($tx->description ?: 'no description'), 'date' => $tx->date->toDateString(),
                    'amount_cents' => (int) $tx->amount_cents, 'currency' => $tx->currency, 'base_cents' => (int) $tx->amount_cents, 'party' => null,
                    'reason' => 'No receipt or invoice is attached.', 'threshold_cents' => $threshold,
                    'view' => 'transactions.edit', 'view_parameters' => [$tx->id], 'attach' => 'purchases.documents.index', 'attach_parameters' => [],
                    'counts_for_tax' => false,
                ]);
            }

            $sorted = $items->sortByDesc('date')->values();

            return $limit !== null ? $sorted->take($limit)->values() : $sorted;
        });
    }

    /**
     * How many items forLedger() would list, counted in the database (the state line asks on every page, so it must not load
     * the ledger's bills). Same rules as forLedger(); one difference: a bill with no stored base amount (posted before the
     * column was filled) is compared by its own-currency total, where forLedger() converts it.
     */
    public function countForLedger(LedgerContext $context): int
    {
        return LedgerContext::run($context, function () use ($context) {
            $threshold = PurchaseSetting::forLedger($context)->missingDocumentThresholdCents();
            $hasFile = fn (string $type, string $table) => fn ($q) => $q->select(DB::raw('1'))->from('attachments')
                ->where('attachments.attachable_type', $type)->whereNull('attachments.archived_at')->whereColumn('attachments.attachable_id', $table.'.id')
                ->where(fn ($w) => $context->isCompany() ? $w->where('attachments.team_id', $context->teamId) : $w->where('attachments.user_id', $context->userId)->whereNull('attachments.team_id'));

            $bills = Bill::query()->whereNotIn('status', ['draft', 'void'])->whereRaw('COALESCE(base_total_cents, total_cents) >= ?', [$threshold])
                ->whereNotExists($hasFile((new Bill)->getMorphClass(), 'bills'))->count();

            $lines = ExpenseClaimLine::query()->where('gross_cents', '>=', $threshold)
                ->whereHas('claim', fn ($q) => $q->whereNotIn('status', ['draft', 'void']))
                ->whereNotExists($hasFile((new ExpenseClaimLine)->getMorphClass(), 'expense_claim_lines'))->count();

            $transactions = 0;
            if ($this->ledger->accountingEnabled($context)) {
                $ids = Transaction::query()->counted()->where('type', 'expense')->whereNull('transfer_group')->where('amount_cents', '>=', $threshold)
                    ->where('date', '>=', now()->subDays(self::TRANSACTION_DAYS)->toDateString())->orderByDesc('date')->limit(self::TRANSACTION_LIMIT)->pluck('id')->map(fn ($id) => (int) $id)->all();
                if ($ids !== []) {
                    $withFile = Attachment::query()->where('attachable_type', (new Transaction)->getMorphClass())->whereNull('archived_at')->whereIn('attachable_id', $ids)->pluck('attachable_id')->map(fn ($id) => (int) $id)->all();
                    $transactions = count(array_diff($ids, $withFile, SettlementTransactions::among($context, $ids)));
                }
            }

            return $bills + $lines + $transactions;
        });
    }

    /** The cutoff date helper for callers that only want recent items. */
    public function since(LedgerContext $context, CarbonImmutable $from): Collection
    {
        return $this->forLedger($context)->filter(fn ($i) => $i['date'] >= $from->toDateString())->values();
    }
}
