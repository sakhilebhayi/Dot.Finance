<?php

namespace App\Services\Purchases;

use App\Models\Account;
use App\Models\Accounting\JournalEntry;
use App\Models\Purchases\Bill;
use App\Models\Purchases\ExpenseClaim;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Models\Shared\PaymentAllocation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\Chart;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Accounting\SettlementTransactions;
use App\Services\Controls\ApprovalService;
use App\Services\Shared\PaymentAllocator;
use App\Services\Shared\PaymentRecorder;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use App\Support\Shared\Exceptions\IdempotencyConflict;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pays suppliers, once. One call is one transaction that
 *
 *   1. records the payment (idempotent on the key) and applies it to the bills / claims named, never more than is owed;
 *   2. posts   Dr Accounts payable (what the paid documents were carried at)
 *                 Cr bank                  (what left the account, in base currency)
 *                 +/- Realised FX          (the difference, for a foreign-currency bill paid at another rate)
 *   3. writes the cash-ledger row for the bank account, linked to the payment, so the cash ledger and the books tie out;
 *   4. moves each paid document to partially_paid / paid.
 *
 * Money left over after the allocations is paid "on account" (a debit balance with the supplier) and can be applied later
 * (same-currency documents only). A payment in a currency other than the bill's is refused (shared layer rule, D-208).
 * The cash row is skipped by the cash-ledger posting (SettlementTransactions) because this entry already carries it.
 */
final class SupplierPayments
{
    public function __construct(
        private readonly PaymentRecorder $recorder,
        private readonly PaymentAllocator $allocator,
        private readonly PurchaseLedger $ledger,
        private readonly JournalPoster $poster,
        private readonly Chart $chart,
        private readonly BillStatus $status,
        private readonly BillWorkflow $bills,
        private readonly Settlement $settlement,
    ) {}

    /**
     * A retry must be the same payment in money terms too: the same total and the same bills (and the same amount on each bill
     * when the request names one). Otherwise a second request under a used key would be told "done" for money it never asked
     * for (QA security D-1410).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertSameMoney(Payment $existing, array $data, string $key): void
    {
        if (isset($data['amount_cents']) && (int) $data['amount_cents'] !== (int) $existing->amount_cents) {
            throw new IdempotencyConflict("The key {$key} was already used for a payment of a different amount. Repeat the same request to retry, or use a new key.");
        }

        $held = [];
        foreach ($existing->allocations()->get() as $allocation) {
            $held[$allocation->allocatable_type.':'.$allocation->allocatable_id] = (int) $allocation->amount_cents;
        }

        $asked = [];
        foreach ($data['allocations'] ?? [] as $row) {
            $class = match ($row['type'] ?? null) {
                'bill' => (new Bill)->getMorphClass(),
                'expense_claim' => (new ExpenseClaim)->getMorphClass(),
                default => (string) ($row['type'] ?? ''),
            };
            $asked[$class.':'.(int) $row['id']] = isset($row['amount_cents']) ? (int) $row['amount_cents'] : null;
        }

        $differs = array_keys($asked) !== array_keys($held) && (array_diff_key($asked, $held) !== [] || array_diff_key($held, $asked) !== []);
        foreach ($asked as $k => $cents) {
            $differs = $differs || ($cents !== null && isset($held[$k]) && $held[$k] !== $cents);
        }

        if ($differs) {
            throw new IdempotencyConflict("The key {$key} was already used for a payment of different bills or amounts. Repeat the same request to retry, or use a new key.");
        }
    }

    /**
     * @param  array{party_id: int, cash_account_id: int, paid_on: string, method?: string, reference?: ?string, idempotency_key: string, notes?: ?string, fx_rate_e8?: ?int, amount_cents?: ?int, allocations?: list<array{type: string, id: int, amount_cents?: int, take_discount?: bool}>}  $data
     * @param  bool  $approved  the caller already holds an approval for this payment (a payment run that was approved)
     *
     * @throws PurchasesException
     */
    public function pay(LedgerContext $context, User $by, array $data, bool $approved = false): Payment
    {
        return LedgerContext::run($context, fn () => DB::transaction(function () use ($context, $by, $data, $approved) {
            $settings = $this->ledger->requireAccounting($context);
            $base = $settings->base_currency;
            $key = trim((string) ($data['idempotency_key'] ?? ''));

            $existing = Payment::query()->where('idempotency_key', $key)->first();
            if ($existing !== null && $existing->gl_entry_id !== null) {
                // A retry: the same request returns the payment that was made; a different one under the same key is refused.
                foreach (['party_id' => 'supplier', 'cash_account_id' => 'account'] as $column => $label) {
                    if ((int) $existing->{$column} !== (int) ($data[$column] ?? 0)) {
                        throw new IdempotencyConflict("The key {$key} was already used for a payment with a different {$label}. Repeat the same request to retry, or use a new key.");
                    }
                }
                if ($existing->paid_on->toDateString() !== CarbonImmutable::parse($data['paid_on'])->toDateString()) {
                    throw new IdempotencyConflict("The key {$key} was already used for a payment on a different date. Repeat the same request to retry, or use a new key.");
                }
                $this->assertSameMoney($existing, $data, $key);

                return $existing;
            }

            $party = Party::query()->find((int) ($data['party_id'] ?? 0));
            if ($party === null || ! $party->isSupplier()) {
                throw new PurchasesException('Choose a supplier from this ledger.', 'bad_supplier');
            }

            $account = Account::query()->find((int) ($data['cash_account_id'] ?? 0));
            if ($account === null) {
                throw new PurchasesException('Choose the bank or cash account the money comes from.', 'bad_account');
            }
            $currency = strtoupper($account->currency);
            $paidOn = CarbonImmutable::parse($data['paid_on']);

            if ($account->opening_balance_date !== null && $paidOn->lt(CarbonImmutable::parse($account->opening_balance_date->toDateString()))) {
                throw new PurchasesException("{$account->name} opened on {$account->opening_balance_date->toDateString()}; a payment cannot be dated before it.", 'before_opening');
            }

            // lock the documents in a fixed order so two payments cannot deadlock
            $rows = collect($data['allocations'] ?? [])->map(fn ($a) => $a + ['take_discount' => false])->sortBy([['type', 'asc'], ['id', 'asc']])->values();
            $documents = [];
            foreach ($rows as $i => $row) {
                $doc = match ($row['type']) {
                    'bill' => Bill::query()->where('kind', 'bill')->whereKey((int) $row['id'])->lockForUpdate()->first(),
                    'expense_claim' => ExpenseClaim::query()->whereKey((int) $row['id'])->lockForUpdate()->first(),
                    default => null,
                };
                if ($doc === null || ! $doc->isPosted() || $doc->party_id !== $party->id) {
                    throw new PurchasesException('Every bill or claim paid must be posted and belong to this supplier.', 'bad_document');
                }
                if (strtoupper($doc->currency) !== $currency) {
                    throw new PurchasesException("{$doc->displayNumber()} is in {$doc->currency}. Pay it from a {$doc->currency} account (a payment cannot settle a bill in another currency).", 'currency_mismatch');
                }
                $documents[$i] = $doc;
            }

            // The documents are locked now. A retry that was waiting for the lock finds the payment its twin made, and returns it.
            if ($rows->isNotEmpty() && ($twin = Payment::query()->where('idempotency_key', $key)->first()) !== null && $twin->gl_entry_id !== null) {
                return $twin;
            }

            $allocations = [];
            foreach ($rows as $i => $row) {
                $doc = $documents[$i];

                if (! empty($row['take_discount']) && $doc instanceof Bill) {
                    $this->bills->takeEarlyPaymentDiscount($by, $doc, $paidOn);
                    $row['amount_cents'] = $this->status->openCents($doc);
                }

                $open = $this->status->openCents($doc);
                $amount = (int) ($row['amount_cents'] ?? $open);

                if ($amount <= 0 || $amount > $open) {
                    throw new PurchasesException("{$doc->displayNumber()}: pay an amount between 0.01 and what is still owed (".Money::format($open, $currency).').', 'bad_amount');
                }

                $allocations[] = ['doc' => $doc, 'amount' => $amount];
            }

            $allocated = array_sum(array_column($allocations, 'amount'));
            $total = (int) ($data['amount_cents'] ?? $allocated);

            if ($total <= 0 || $total < $allocated) {
                throw new PurchasesException('The payment cannot be less than the bills it pays.', 'bad_total');
            }

            $rate = $this->ledger->rateFor($context, $currency, $paidOn, isset($data['fx_rate_e8']) ? (int) $data['fx_rate_e8'] : null);
            $baseTotal = $this->ledger->toBase($context, $total, $currency, $rate);

            if (! $approved && ApprovalService::requiresApproval('payment', $baseTotal, $base, $context)) {
                throw new PurchasesException('Payments of this size need approval. Put it in a payment run and ask for approval.', 'approval_required');
            }

            $payment = $this->recorder->record([
                'direction' => 'out', 'party_id' => $party->id, 'cash_account_id' => $account->id, 'amount_cents' => $total, 'currency' => $currency,
                'fx_rate_e8' => $rate, 'base_amount_cents' => $baseTotal, 'paid_on' => $paidOn->toDateString(), 'method' => $data['method'] ?? 'eft',
                'reference' => $data['reference'] ?? null, 'idempotency_key' => $key, 'notes' => $data['notes'] ?? null,
            ], $by, $context);

            if (! $payment->wasRecentlyCreated) {
                return $payment; // a concurrent twin with the same key made it: that one posts it
            }

            // what the payment is worth in base currency, split by the cumulative method so the pieces add up exactly
            $pieces = [];
            $cumulative = 0;
            $previousBase = 0;
            foreach ($allocations as $i => $row) {
                $cumulative += $row['amount'];
                $upto = Money::mulDivRound($baseTotal, $cumulative, $total);
                $pieces[$i] = $upto - $previousBase;
                $previousBase = $upto;
            }
            $onAccountBase = $baseTotal - $previousBase;

            $relieved = 0;
            $fxTotal = 0;
            foreach ($allocations as $i => $row) {
                $doc = $row['doc'];
                $relief = $this->relief($doc, $row['amount'], $base);
                $fx = $relief - $pieces[$i];
                $taxPart = $doc->total_cents > 0 ? Money::mulDivRound($row['amount'], $doc->tax_cents, $doc->total_cents) : 0;

                $allocation = $this->allocator->allocate($payment, $doc, $row['amount'], $taxPart, $fx, $by);
                PaymentAllocation::query()->whereKey($allocation->id)->update(['base_relief_cents' => $relief]);

                $relieved += $relief;
                $fxTotal += $fx;
            }

            $glBank = $this->chart->mapAccount($account);
            $foreign = $currency !== $base;
            $apDebit = $relieved + $onAccountBase;

            $lines = [];
            if ($apDebit > 0) {
                $lines[] = new PostingLine('ap', debitCents: $apDebit, txnCurrency: $foreign ? $currency : null, txnAmountCents: $foreign ? $total : null, fxRateE8: $foreign ? $rate : null, partyId: $party->id, description: 'Payment '.($payment->reference ?? '#'.$payment->id));
            }
            $lines[] = new PostingLine($glBank->id, creditCents: $baseTotal, txnCurrency: $foreign ? $currency : null, txnAmountCents: $foreign ? $total : null, fxRateE8: $foreign ? $rate : null, partyId: $party->id, cashAccountId: $account->id, description: 'Payment to '.mb_substr($party->name, 0, 120));
            if ($fxTotal > 0) {
                $lines[] = new PostingLine('fx_realised', creditCents: $fxTotal, partyId: $party->id, description: 'Exchange gain on settlement');
            } elseif ($fxTotal < 0) {
                $lines[] = new PostingLine('fx_realised', debitCents: -$fxTotal, partyId: $party->id, description: 'Exchange loss on settlement');
            }

            $entry = $this->poster->post(new PostingRequest($paidOn, 'payment', $payment->id, 1, 'Payment to '.mb_substr($party->name, 0, 120).($payment->reference ? ' ('.$payment->reference.')' : ''), $lines, $by->id));

            $transaction = SettlementTransactions::write($payment, $this->ledger->supplierPaymentsCategory($context)->id, 'Payment to '.$party->name.($payment->reference ? ' ('.$payment->reference.')' : ''));

            $payment->forceFill(['gl_entry_id' => $entry->id, 'transaction_id' => $transaction->id])->save();

            foreach ($allocations as $row) {
                $this->status->refresh($row['doc']);
            }

            return $payment->refresh();
        }));
    }

    /**
     * Take back a payment: its journal entry is reversed, the cash row is removed from the cash ledger, the allocations are
     * released and every document it paid goes back to open or partially_paid.
     */
    public function reverse(User $by, Payment $payment, string $reason): Payment
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new PurchasesException('Say why this payment is being reversed.', 'reason_required');
        }

        return LedgerContext::run($payment->ledgerContext(), fn () => DB::transaction(function () use ($by, $payment, $reason) {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isReversed()) {
                return $locked;
            }
            if ($locked->direction !== 'out') {
                throw new PurchasesException('Only a payment to a supplier can be reversed here.', 'bad_payment');
            }

            $documents = $locked->allocations()->whereNull('released_at')->get()->map(fn (PaymentAllocation $a) => $a->allocatable)->filter()->unique(fn ($d) => $d->getMorphClass().':'.$d->getKey());

            if ($locked->gl_entry_id !== null) {
                $this->poster->reverse(JournalEntry::query()->findOrFail($locked->gl_entry_id), 'Payment reversed: '.$reason);
            }

            if ($locked->transaction_id !== null) {
                SettlementTransactions::remove($locked);
            }

            $this->allocator->reverse($locked, $reason, $by);

            foreach ($documents as $doc) {
                $this->status->refresh($doc);
            }

            return $locked->refresh();
        }));
    }

    /**
     * Apply money that was paid on account to a bill later. Same-currency documents in the ledger's base currency only: the
     * books already hold that money against the supplier, so nothing is posted, only the allocation is recorded.
     */
    public function applyOnAccount(User $by, Payment $payment, Bill|ExpenseClaim $doc, int $amountCents): PaymentAllocation
    {
        return LedgerContext::run($payment->ledgerContext(), fn () => DB::transaction(function () use ($by, $payment, $doc, $amountCents) {
            $base = $this->ledger->baseCurrencyOrDefault($payment->ledgerContext());

            if (strtoupper($payment->currency) !== $base || strtoupper($doc->currency) !== $base) {
                throw new PurchasesException('Money paid on account can be applied later only when both are in the ledger\'s own currency. Record the payment against the bill instead.', 'foreign_on_account');
            }

            $allocation = $this->allocator->allocate($payment, $doc, $amountCents, 0, 0, $by);
            PaymentAllocation::query()->whereKey($allocation->id)->update(['base_relief_cents' => $amountCents]);
            $this->status->refresh($doc);

            FinanceAudit::record($payment->ledgerOwner(), 'applied_on_account', 'payment', $payment->id, FinanceAudit::label($payment), ['allocatable_id' => ['after' => $doc->getKey()], 'amount_cents' => ['after' => $amountCents]], null, $payment->currency, $by->id);

            return $allocation;
        }));
    }

    /** What the allocation takes off the payable, in base currency: the same share of what the document is carried at. */
    private function relief(Bill|ExpenseClaim $doc, int $amount, string $base): int
    {
        if (strtoupper($doc->currency) === $base) {
            return $amount;
        }

        $open = $this->status->openCents($doc);
        $carrying = $this->settlement->carryingBase($doc);

        return $amount >= $open ? $carrying : Money::mulDivRound($carrying, $amount, $open);
    }
}
