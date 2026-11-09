<?php

namespace App\Services\Sales;

use App\Models\Account;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\LedgerSetting;
use App\Models\Sales\SalesApplication;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesRefund;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Models\User;
use App\Services\Accounting\Chart;
use App\Services\Accounting\Exceptions\PeriodClosed;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Sales\Support\SalesViolation;
use App\Services\Shared\FxMath;
use App\Services\Shared\FxService;
use App\Services\Shared\PaymentAllocator;
use App\Services\Shared\PaymentRecorder;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\AllocationRejected;
use App\Support\Shared\Exceptions\IdempotencyConflict;
use App\Support\Shared\Exceptions\NoFxRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Money from customers: record a payment, apply it to invoices (in full, in part, over or under), keep what is not
 * applied as a customer credit, give money back, take a payment back.
 *
 * One call, one database transaction, all or nothing: the Payment row (idempotent on its key), the cash-ledger row
 * that moves the account balance, the journal entry (Dr bank / Cr receivables), and the applications with any
 * realised exchange difference. A retry with the same key does nothing the second time.
 */
final class ReceiptService
{
    public function __construct(
        private readonly PaymentRecorder $recorder,
        private readonly PaymentAllocator $allocator,
        private readonly ApplicationEngine $engine,
        private readonly CashSettlement $cash,
        private readonly JournalPoster $poster,
        private readonly Chart $chart,
        private readonly FxService $fx,
    ) {}

    /**
     * @param  array{party_id: int, cash_account_id: int, amount_cents: int, paid_on: string, method?: string, reference?: ?string, idempotency_key: string, notes?: ?string, fx_rate?: ?string, allocations?: list<array{document_id: int, amount_cents: int}>, auto_allocate?: bool}  $data
     * @return array{payment: Payment, applications: list<SalesApplication>, replayed: bool, unapplied_cents: int}
     *
     * @throws SalesViolation
     */
    public function receive(LedgerContext $context, User $by, array $data): array
    {
        return LedgerContext::run($context, function () use ($context, $by, $data) {
            $settings = $this->settings($context);
            app(DocumentIssuer::class)->assertChartReady($context, ['ar']);
            $party = $this->customer($context, (int) $data['party_id']);
            $account = Account::query()->forContext($context)->find((int) $data['cash_account_id'])
                ?? throw new SalesViolation('Pick one of this ledger\'s accounts for the money.', 'account');
            $amount = (int) $data['amount_cents'];
            $paidOn = $this->date($data['paid_on'], 'The date received');
            $this->assertNotBeforeOpening($account, $paidOn);
            $currency = $account->currency;
            $allocations = $this->normaliseAllocations($context, $party, $currency, $data['allocations'] ?? [], $amount);

            [$rate, $baseAmount] = $this->baseValue($currency, $settings->base_currency, $amount, $paidOn, $data['fx_rate'] ?? null);

            try {
                return DB::transaction(function () use ($context, $by, $data, $party, $account, $amount, $paidOn, $currency, $rate, $baseAmount, $allocations, $settings) {
                    $payment = $this->recorder->record([
                        'direction' => 'in', 'party_id' => $party->id, 'cash_account_id' => $account->id, 'amount_cents' => $amount, 'currency' => $currency,
                        'fx_rate_e8' => $rate, 'base_amount_cents' => $baseAmount, 'paid_on' => $paidOn->toDateString(), 'method' => $data['method'] ?? 'eft',
                        'reference' => $data['reference'] ?? null, 'idempotency_key' => $data['idempotency_key'], 'notes' => $data['notes'] ?? null,
                    ], $by, $context);

                    if ($payment->gl_entry_id !== null) {
                        return ['payment' => $payment, 'applications' => [], 'replayed' => true, 'unapplied_cents' => $payment->unallocatedCents()];
                    }

                    $transaction = $this->cash->record($payment, 'Payment received from '.$party->name.($payment->reference ? ' ('.$payment->reference.')' : ''));
                    $entry = $this->poster->post(new PostingRequest(
                        $paidOn, 'payment', $payment->id, 1, 'Payment received from '.$party->name,
                        [
                            $this->bankLine($context, $account, true, $baseAmount, $currency, $amount, $rate, $settings->base_currency, 'Payment received'),
                            new PostingLine('ar', 0, $baseAmount, $currency !== $settings->base_currency ? $currency : null, $currency !== $settings->base_currency ? $amount : null, $rate, partyId: $party->id, description: 'Payment received'),
                        ],
                        $by->id,
                    ));
                    $payment->forceFill(['gl_entry_id' => $entry->id, 'transaction_id' => $transaction->id])->save();

                    $applications = [];
                    $plan = $allocations !== [] ? $allocations : (($data['auto_allocate'] ?? false) ? $this->oldestFirst($context, $party, $currency, $amount) : []);
                    foreach ($plan as $row) {
                        $document = SalesDocument::query()->forContext($context)->findOrFail($row['document_id']);
                        $applications[] = $this->engine->applyPayment($payment, $document, $row['amount_cents'], $by);
                    }

                    return ['payment' => $payment->refresh(), 'applications' => $applications, 'replayed' => false, 'unapplied_cents' => $payment->refresh()->unallocatedCents()];
                });
            } catch (PeriodClosed $e) {
                throw new SalesViolation($e->getMessage().' Record the payment on a date in an open period.', 'period_closed');
            } catch (IdempotencyConflict $e) {
                throw new SalesViolation($e->getMessage(), 'idempotency');
            }
        });
    }

    /**
     * The cash ledger does not count rows dated before an account opened (D-100), so a receipt or refund dated before then would be
     * journalled but missing from the account's balance and the books would stop tying out. Purchases refuses the same thing.
     */
    private function assertNotBeforeOpening(Account $account, CarbonImmutable $on): void
    {
        if ($account->opening_balance_date !== null && $on->lt(CarbonImmutable::parse($account->opening_balance_date->toDateString()))) {
            throw new SalesViolation("{$account->name} opened on {$account->opening_balance_date->toDateString()}; money cannot be dated before it.", 'before_opening');
        }
    }

    /** Apply some of a payment's unapplied money to an invoice, later. */
    public function allocate(LedgerContext $context, User $by, Payment $payment, SalesDocument $document, int $amountCents): SalesApplication
    {
        return LedgerContext::run($context, function () use ($by, $payment, $document, $amountCents) {
            try {
                return DB::transaction(fn () => $this->engine->applyPayment($payment, $document, $amountCents, $by));
            } catch (PeriodClosed $e) {
                throw new SalesViolation($e->getMessage(), 'period_closed');
            }
        });
    }

    /**
     * Give money back to a customer out of a credit they hold: an unapplied payment or an unapplied credit note.
     *
     * @param  array{source_payment_id?: ?int, source_document_id?: ?int, amount_cents: int, cash_account_id: int, paid_on: string, reference?: ?string, idempotency_key: string, reason: string, fx_rate?: ?string}  $data
     *
     * @throws SalesViolation
     */
    public function refund(LedgerContext $context, User $by, array $data): SalesRefund
    {
        return LedgerContext::run($context, function () use ($context, $by, $data) {
            $settings = $this->settings($context);
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '') {
                throw new SalesViolation('Say why the money is being given back.', 'reason');
            }
            $amount = (int) $data['amount_cents'];
            $paidOn = $this->date($data['paid_on'], 'The date paid');
            $account = Account::query()->forContext($context)->find((int) $data['cash_account_id'])
                ?? throw new SalesViolation('Pick one of this ledger\'s accounts to pay from.', 'account');
            $this->assertNotBeforeOpening($account, $paidOn);

            try {
                return DB::transaction(function () use ($context, $by, $data, $settings, $reason, $amount, $paidOn, $account) {
                    // a retry of a refund that already happened returns it, before anything is measured against the pools
                    $earlier = Payment::query()->forContext($context)->where('idempotency_key', $data['idempotency_key'])->first();
                    $replay = $earlier !== null ? SalesRefund::query()->forContext($context)->where('payment_id', $earlier->id)->first() : null;
                    if ($replay !== null) {
                        // the same request returns the refund that was made; a different one under the same key is refused (D-1410)
                        $sameSource = $replay->source_kind === 'payment'
                            ? (int) $replay->source_payment_id === (int) ($data['source_payment_id'] ?? 0)
                            : (int) $replay->source_document_id === (int) ($data['source_document_id'] ?? 0);
                        if (! $sameSource || (int) $earlier->amount_cents !== $amount || (int) $earlier->cash_account_id !== (int) $account->id || $earlier->paid_on->toDateString() !== $paidOn->toDateString()) {
                            throw new SalesViolation("The key {$data['idempotency_key']} was already used for a different refund. Repeat the same request to retry, or use a new key.", 'idempotency');
                        }

                        return $replay;
                    }

                    $fromPayment = ! empty($data['source_payment_id']);
                    if ($fromPayment) {
                        $source = Payment::query()->forContext($context)->whereKey((int) $data['source_payment_id'])->lockForUpdate()->first();
                        if ($source === null || $source->direction !== 'in' || $source->status !== 'recorded') {
                            throw new SalesViolation('That is not a payment received that can be refunded.', 'source');
                        }
                        $pool = $this->engine->paymentPool($source);
                        $partyId = (int) $source->party_id;
                        $currency = $source->currency;
                    } else {
                        $source = SalesDocument::query()->forContext($context)->whereKey((int) ($data['source_document_id'] ?? 0))->lockForUpdate()->first();
                        if ($source === null || ! $source->isCredit() || ! $source->isSealed() || $source->isVoid()) {
                            throw new SalesViolation('That is not an issued credit note.', 'source');
                        }
                        $pool = $this->engine->creditPool($source);
                        $partyId = (int) $source->party_id;
                        $currency = $source->currency;
                    }

                    if ($account->currency !== $currency) {
                        throw new SalesViolation("The credit is in {$currency} and {$account->name} holds {$account->currency}. Pay it back from an account in {$currency}.", 'account');
                    }
                    $sourceBase = $this->engine->share($pool, $amount);
                    [$rate, $bankBase] = $this->baseValue($currency, $settings->base_currency, $amount, $paidOn, $data['fx_rate'] ?? null);
                    $party = Party::query()->forContext($context)->withTrashed()->findOrFail($partyId);

                    $payment = $this->recorder->record([
                        'direction' => 'out', 'party_id' => $partyId, 'cash_account_id' => $account->id, 'amount_cents' => $amount, 'currency' => $currency,
                        'fx_rate_e8' => $rate, 'base_amount_cents' => $bankBase, 'paid_on' => $paidOn->toDateString(), 'method' => 'eft',
                        'reference' => $data['reference'] ?? null, 'idempotency_key' => $data['idempotency_key'], 'notes' => mb_substr($reason, 0, 500),
                    ], $by, $context);

                    $existing = SalesRefund::query()->forContext($context)->where('payment_id', $payment->id)->first();
                    if ($existing !== null) {
                        return $existing;
                    }

                    $refund = SalesRefund::query()->create($context->ownerAttributes() + [
                        'party_id' => $partyId, 'payment_id' => $payment->id, 'source_kind' => $fromPayment ? 'payment' : 'credit_note',
                        'source_payment_id' => $fromPayment ? $source->id : null, 'source_document_id' => $fromPayment ? null : $source->id,
                        'currency' => $currency, 'amount_cents' => $amount, 'source_base_cents' => $sourceBase, 'bank_base_cents' => $bankBase,
                        'reason' => mb_substr($reason, 0, 255), 'status' => 'recorded', 'created_by' => $by->id,
                    ]);

                    if ($fromPayment) {
                        try {
                            $this->allocator->allocate($source, $refund, $amount, 0, 0, $by);
                        } catch (AllocationRejected $e) {
                            throw new SalesViolation($e->getMessage(), 'allocation');
                        }
                    }

                    $transaction = $this->cash->record($payment, 'Refund to '.$party->name);
                    $diff = $bankBase - $sourceBase;   // more left the bank than the credit carried: a loss
                    $foreign = $currency !== $settings->base_currency;
                    $lines = [
                        new PostingLine('ar', $sourceBase, 0, $foreign ? $currency : null, $foreign ? $amount : null, $foreign ? $rate : null, partyId: $partyId, description: 'Refund'),
                        $this->bankLine($context, $account, false, $bankBase, $currency, $amount, $rate, $settings->base_currency, 'Refund'),
                    ];
                    if ($diff !== 0) {
                        $lines[] = new PostingLine('fx_realised', $diff > 0 ? $diff : 0, $diff > 0 ? 0 : -$diff, description: 'Realised exchange '.($diff > 0 ? 'loss' : 'gain').' on refund');
                    }

                    $entry = $this->poster->post(new PostingRequest($paidOn, 'payment', $payment->id, 1, 'Refund to '.$party->name, $lines, $by->id));
                    $payment->forceFill(['gl_entry_id' => $entry->id, 'transaction_id' => $transaction->id])->save();

                    if (! $fromPayment) {
                        SalesTrail::event($source, 'credit_refunded', $by->id, ['amount_cents' => $amount]);
                    }

                    return $refund->refresh();
                });
            } catch (PeriodClosed $e) {
                throw new SalesViolation($e->getMessage().' Record it on a date in an open period.', 'period_closed');
            }
        });
    }

    /**
     * Take a payment (or a refund) back, with a reason. Everything it did is undone in one transaction: the journal
     * entry is reversed, the cash-ledger row removed, the applications released (and their exchange differences
     * reversed) so the invoices owe again, and statuses move back.
     */
    public function reverse(LedgerContext $context, User $by, Payment $payment, string $reason): Payment
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new SalesViolation('Say why the payment is being reversed.', 'reason');
        }

        return LedgerContext::run($context, function () use ($context, $by, $payment, $reason) {
            try {
                return DB::transaction(function () use ($context, $by, $payment, $reason) {
                    $locked = Payment::query()->forContext($context)->whereKey($payment->id)->lockForUpdate()->firstOrFail();
                    if ($locked->status === 'reversed') {
                        return $locked;
                    }

                    if ($locked->direction === 'in') {
                        $refunded = SalesRefund::query()->forContext($context)->where('source_payment_id', $locked->id)->where('status', 'recorded')->exists();
                        if ($refunded) {
                            throw new SalesViolation('Part of this payment was given back to the customer. Reverse that refund first.', 'refunded');
                        }
                    }

                    $this->allocator->reverse($locked, $reason, $by);
                    $this->engine->releaseAllOfPayment($locked, $reason, $by);

                    if ($locked->direction === 'out') {
                        $refund = SalesRefund::query()->forContext($context)->where('payment_id', $locked->id)->first();
                        $refund?->forceFill(['status' => 'reversed', 'reversed_at' => now()])->save();
                    }

                    if ($locked->gl_entry_id !== null) {
                        $entry = JournalEntry::query()->forContext($context)->find($locked->gl_entry_id);
                        if ($entry !== null && $entry->status === 'posted') {
                            $this->poster->reverse($entry, 'Payment reversed: '.$reason);
                        }
                    }

                    $this->cash->remove($locked);

                    return $locked->refresh();
                });
            } catch (PeriodClosed $e) {
                throw new SalesViolation($e->getMessage().' A payment in a closed period cannot be reversed; record a refund instead.', 'period_closed');
            } catch (AllocationRejected $e) {
                throw new SalesViolation($e->getMessage(), 'allocation');
            }
        });
    }

    // ---- internals ----------------------------------------------------------------------------

    private function settings(LedgerContext $context): LedgerSetting
    {
        $settings = LedgerSetting::query()->forContext($context)->first();
        if ($settings === null || ! $settings->isEnabled()) {
            throw new SalesViolation('Accounting is not switched on for this ledger yet, so money received cannot be posted. Finish the accounting set-up first.', 'accounting_not_ready');
        }

        return $settings;
    }

    private function customer(LedgerContext $context, int $id): Party
    {
        $party = Party::query()->forContext($context)->find($id) ?? throw new SalesViolation('Pick a customer from this ledger.', 'party');
        if (! $party->isCustomer()) {
            throw new SalesViolation($party->name.' is not a customer.', 'party');
        }

        return $party;
    }

    private function date(string $value, string $label): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', substr($value, 0, 10)) ?: throw new \InvalidArgumentException;
        } catch (\Throwable) {
            throw new SalesViolation("{$label} is not a valid date.", 'date');
        }
        if ($date->isFuture() && ! $date->isToday()) {
            throw new SalesViolation("{$label} cannot be in the future.", 'date');
        }

        return $date->startOfDay();
    }

    /**
     * The rate and base-currency value of an amount of money that moved. The rate a person types wins; else the newest
     * stored rate on or before the day; with neither, the money cannot be valued and nothing is posted.
     *
     * @return array{0: ?int, 1: int}
     */
    private function baseValue(string $currency, string $base, int $amount, CarbonImmutable $on, ?string $typedRate): array
    {
        if ($currency === $base) {
            return [null, $amount];
        }

        try {
            $rate = $typedRate !== null && trim($typedRate) !== '' ? FxMath::parseRate($typedRate) : $this->fx->rate($currency, $base, $on)->rateE8;
        } catch (NoFxRate) {
            throw new SalesViolation("There is no {$currency} to {$base} exchange rate on or before {$on->toDateString()}. Enter the rate you were given, or record it under exchange rates first.", 'no_fx_rate');
        } catch (\InvalidArgumentException $e) {
            throw new SalesViolation('The exchange rate is not valid: '.$e->getMessage(), 'fx');
        }

        $baseAmount = FxMath::convert($amount, $rate, 2, 2);
        if ($baseAmount <= 0) {
            throw new SalesViolation('That amount is worth nothing in '.$base.' at that rate.', 'fx');
        }

        return [$rate, $baseAmount];
    }

    private function bankLine(LedgerContext $context, Account $account, bool $debit, int $base, string $currency, int $amount, ?int $rate, string $baseCurrency, string $description): PostingLine
    {
        $map = $this->chart->map($context, $baseCurrency);
        if (! isset($map->cash[$account->id])) {
            $this->chart->mapAccount($account);
            $map = $this->chart->map($context, $baseCurrency);
        }
        $foreign = $currency !== $baseCurrency;

        return new PostingLine($map->cashAccount($account->id)['gl'], $debit ? $base : 0, $debit ? 0 : $base, $foreign ? $currency : null, $foreign ? $amount : null, $foreign ? $rate : null, cashAccountId: $account->id, description: $description);
    }

    /**
     * @param  list<array{document_id: int, amount_cents: int}>  $rows
     * @return list<array{document_id: int, amount_cents: int}>
     */
    private function normaliseAllocations(LedgerContext $context, Party $party, string $currency, array $rows, int $payment): array
    {
        $seen = [];
        $total = 0;
        $out = [];

        foreach ($rows as $row) {
            $id = (int) ($row['document_id'] ?? 0);
            $cents = (int) ($row['amount_cents'] ?? 0);
            if ($cents <= 0) {
                continue;
            }
            if (isset($seen[$id])) {
                throw new SalesViolation('The same invoice appears twice in the allocation.', 'allocation');
            }
            $seen[$id] = true;

            $document = SalesDocument::query()->forContext($context)->find($id);
            if ($document === null || ! $document->isReceivable() || (int) $document->party_id !== $party->id) {
                throw new SalesViolation('An invoice in the allocation is not one of '.$party->name.'\'s open invoices.', 'allocation');
            }
            if ($document->currency !== $currency) {
                throw new SalesViolation("{$document->displayRef()} is in {$document->currency} and the money is in {$currency}. Allocation across currencies is not supported: receive the payment into an account in {$document->currency}.", 'allocation');
            }
            $total += $cents;
            $out[] = ['document_id' => $id, 'amount_cents' => $cents];
        }

        if ($total > $payment) {
            throw new SalesViolation('The invoices add up to more than the money received.', 'allocation');
        }

        return $out;
    }

    /** @return list<array{document_id: int, amount_cents: int}> */
    private function oldestFirst(LedgerContext $context, Party $party, string $currency, int $amount): array
    {
        $plan = [];
        $left = $amount;

        $open = SalesDocument::query()->forContext($context)->open()->where('party_id', $party->id)->where('currency', $currency)
            ->orderBy('due_date')->orderBy('id')->get();

        foreach ($open as $document) {
            if ($left <= 0) {
                break;
            }
            $balance = $this->engine->openBalance($document);
            if ($balance <= 0) {
                continue;
            }
            $take = min($balance, $left);
            $plan[] = ['document_id' => $document->id, 'amount_cents' => $take];
            $left -= $take;
        }

        return $plan;
    }
}
