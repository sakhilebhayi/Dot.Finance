<?php

namespace App\Services\Purchases;

use App\Models\Accounting\JournalEntry;
use App\Models\Controls\ApprovalRequest;
use App\Models\Purchases\Bill;
use App\Models\Purchases\SupplierProfile;
use App\Models\Shared\Currency;
use App\Models\Shared\Party;
use App\Models\User;
use App\Services\Accounting\Exceptions\AccountingException;
use App\Services\Accounting\JournalPoster;
use App\Services\Controls\ApprovalService;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The life of a bill: draft -> (approval) -> posted -> paid, or void. Every state change is one transaction and is idempotent.
 *
 *   saveDraft   create or replace a draft; the server computes every line, the tax and the totals
 *   submit      check for duplicates; if an approval policy covers the amount, ask for approval, else post
 *   approvalDecided   called by the approvals engine inside its transaction: approved -> post (or "approved, not posted yet" with
 *               the reason), rejected / cancelled -> back to draft with the reason
 *   post        for a bill that was approved but could not be posted at the time (a closed period, a missing exchange rate)
 *   withdraw    take an awaiting / approved-but-unposted bill back to draft
 *   void        reverse a posted bill that nothing has been paid against, with a reason
 *
 * Capabilities are checked by the caller (policy / controller); this class enforces the state rules. Separation of duties
 * (the person who asked cannot approve) lives in the approvals engine.
 */
final class BillWorkflow
{
    public function __construct(
        private readonly BillCalculator $calculator,
        private readonly BillPoster $poster,
        private readonly BillStatus $status,
        private readonly PurchaseLedger $ledger,
        private readonly DuplicateDetector $duplicates,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $data  party_id, kind, bill_date, due_date, currency, fx_rate_e8, tax_inclusive, supplier_reference, document_type,
     *                                      supplier_vat_number, notes, early_payment_discount_bp, early_payment_days, credit_for_bill_id, credit_reason,
     *                                      client_token, source, recurring_bill_id, recurring_period, lines[]
     *
     * @throws PurchasesException
     */
    public function saveDraft(LedgerContext $context, User $by, array $data, ?Bill $bill = null): Bill
    {
        return LedgerContext::run($context, function () use ($context, $by, $data, $bill) {
            if ($bill === null && ($token = $data['client_token'] ?? null)) {
                $existing = Bill::query()->where('client_token', $token)->first();
                if ($existing !== null) {
                    return $existing; // the form was submitted twice: the first one stands
                }
            }

            $party = Party::query()->find((int) ($data['party_id'] ?? 0));
            if ($party === null || ! $party->isSupplier() || $party->status !== 'active') {
                throw new PurchasesException('Choose a supplier from this ledger (an archived or customer-only record cannot be billed).', 'bad_supplier');
            }

            $kind = ($data['kind'] ?? $bill?->kind ?? 'bill') === 'credit_note' ? 'credit_note' : 'bill';
            $base = $this->ledger->baseCurrencyOrDefault($context);
            $currency = strtoupper((string) ($data['currency'] ?? $party->currency ?? $base));
            if (! Currency::query()->where('code', $currency)->exists()) {
                throw new PurchasesException("{$currency} is not a currency this app knows.", 'bad_currency');
            }

            $date = CarbonImmutable::parse($data['bill_date'] ?? now()->toDateString());
            $terms = $party->payment_terms_days ?? 30;
            $due = ! empty($data['due_date']) ? CarbonImmutable::parse($data['due_date']) : $date->addDays($terms);
            if ($due->lt($date)) {
                throw new PurchasesException('The due date cannot be before the bill date.', 'bad_due_date');
            }

            $creditFor = null;
            if ($kind === 'credit_note' && ! empty($data['credit_for_bill_id'])) {
                $creditFor = Bill::query()->where('kind', 'bill')->find((int) $data['credit_for_bill_id']);
                if ($creditFor === null || ! $creditFor->isPosted() || $creditFor->party_id !== $party->id) {
                    throw new PurchasesException('A credit note can only credit a posted bill from the same supplier.', 'bad_credit_target');
                }
                $currency = strtoupper($creditFor->currency);
            }
            if ($kind === 'credit_note' && $creditFor === null && $currency !== $base) {
                throw new PurchasesException('A credit note in a foreign currency must say which bill it credits, so it is valued at that bill\'s exchange rate.', 'credit_needs_bill');
            }

            $rate = $creditFor !== null ? $creditFor->fx_rate_e8 : $this->ledger->rateFor($context, $currency, $date, isset($data['fx_rate_e8']) ? (int) $data['fx_rate_e8'] : null);

            $documentType = $data['document_type'] ?? ($kind === 'credit_note' ? 'credit_note' : 'unknown');
            $typeFits = in_array($documentType, Bill::DOCUMENT_TYPES, true)
                && ($kind === 'credit_note' ? in_array($documentType, ['credit_note', 'unknown'], true) : $documentType !== 'credit_note');
            if (! $typeFits) {
                throw new PurchasesException('That document type does not fit this '.($kind === 'credit_note' ? 'credit note' : 'bill').'.', 'bad_document_type');
            }

            $vatNumber = array_key_exists('supplier_vat_number', $data) ? $this->cleanVat($data['supplier_vat_number']) : $this->cleanVat($party->tax_number);
            $inclusive = (bool) ($data['tax_inclusive'] ?? false);

            $ratio = null;
            if ($creditFor !== null) {
                $ratio = $creditFor->tax_cents > 0 ? [$creditFor->claimable_tax_cents, $creditFor->tax_cents] : [0, 1];
            }

            $computed = $this->calculator->compute($context, $data['lines'] ?? [], [
                'date' => $date, 'currency' => $currency, 'fx_rate_e8' => $rate, 'tax_inclusive' => $inclusive, 'document_type' => $documentType,
                'supplier_vat_number' => $vatNumber, 'claim_ratio' => $ratio,
            ]);

            $profile = SupplierProfile::query()->where('party_id', $party->id)->first();
            $discountBp = array_key_exists('early_payment_discount_bp', $data) ? $data['early_payment_discount_bp'] : $profile?->early_payment_discount_bp;
            $discountDays = array_key_exists('early_payment_days', $data) ? $data['early_payment_days'] : $profile?->early_payment_days;

            $reference = isset($data['supplier_reference']) ? mb_substr(trim((string) $data['supplier_reference']), 0, 60) : null;
            $creditReason = isset($data['credit_reason']) ? mb_substr(trim((string) $data['credit_reason']), 0, 255) : null;

            if ($kind === 'credit_note' && ($creditReason === null || $creditReason === '')) {
                throw new PurchasesException('Say why the supplier issued this credit note (returned goods, a price correction, a discount).', 'credit_reason_required');
            }

            $attributes = [
                'kind' => $kind, 'party_id' => $party->id, 'supplier_reference' => $reference !== '' ? $reference : null,
                'supplier_reference_norm' => DuplicateDetector::normalise($reference), 'bill_date' => $date->toDateString(), 'due_date' => $due->toDateString(),
                'currency' => $currency, 'fx_rate_e8' => $rate, 'tax_inclusive' => $inclusive, 'subtotal_cents' => $computed->subtotalCents,
                'tax_cents' => $computed->taxCents, 'total_cents' => $computed->totalCents, 'claimable_tax_cents' => $computed->claimableTaxCents,
                'document_type' => $documentType, 'supplier_vat_number' => $vatNumber, 'vat_review' => $computed->vatReview,
                'rule_versions' => $computed->ruleVersions, 'rounding_level' => $computed->roundingLevel, 'tax_confidence' => $computed->confidence,
                'credit_for_bill_id' => $creditFor?->id, 'credit_reason' => $kind === 'credit_note' ? $creditReason : null,
                'early_payment_discount_bp' => $discountBp !== null && (int) $discountBp > 0 ? (int) $discountBp : null,
                'early_payment_days' => $discountDays !== null && (int) $discountDays > 0 ? (int) $discountDays : null,
                'notes' => isset($data['notes']) ? mb_substr((string) $data['notes'], 0, 5000) : null,
            ];

            try {
                return DB::transaction(function () use ($bill, $context, $by, $attributes, $computed, $data) {
                    if ($bill === null) {
                        $bill = Bill::query()->create($context->ownerAttributes() + $attributes + [
                            'status' => 'draft', 'source' => $data['source'] ?? 'manual', 'recurring_bill_id' => $data['recurring_bill_id'] ?? null,
                            'recurring_period' => $data['recurring_period'] ?? null, 'client_token' => $data['client_token'] ?? null, 'created_by' => $by->id,
                        ]);
                    } else {
                        $locked = Bill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();
                        if ($locked->status !== 'draft') {
                            throw new PurchasesException('Only a draft can be edited. Withdraw it from approval first, or void it.', 'not_draft');
                        }
                        if (isset($data['expected_version']) && (int) $data['expected_version'] !== $locked->version) {
                            throw new PurchasesException('Someone saved this bill while you were editing it. Open it again to see their changes.', 'stale');
                        }
                        $locked->fill($attributes + ['version' => $locked->version + 1, 'rejection_reason' => null, 'post_error' => null])->save();
                        $locked->lines()->delete();
                        $bill = $locked;
                    }

                    foreach ($computed->lines as $line) {
                        $bill->lines()->create($context->ownerAttributes() + $line);
                    }

                    return $bill->load('lines');
                });
            } catch (UniqueConstraintViolationException) {
                // a concurrent request with the same client token (or the same recurring period) won the race
                $token = $data['client_token'] ?? null;
                $existing = $token ? Bill::query()->where('client_token', $token)->first() : null;
                if ($existing !== null) {
                    return $existing;
                }
                throw new PurchasesException('That bill was already created.', 'already_created');
            }
        });
    }

    /**
     * Send a draft on its way: duplicate check, then approval (when a policy covers it) or posting.
     *
     * @param  array{confirm_duplicate?: bool}  $options
     *
     * @throws PurchasesException
     */
    public function submit(User $by, Bill $bill, array $options = []): Bill
    {
        $context = $bill->ledgerContext();

        return LedgerContext::run($context, function () use ($by, $bill, $options, $context) {
            $this->ledger->requireAccounting($context);

            $bill = DB::transaction(function () use ($by, $bill, $options, $context) {
                $locked = Bill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

                if (in_array($locked->status, ['awaiting_approval', 'open', 'partially_paid', 'paid', 'approved'], true)) {
                    return $locked; // already submitted: harmless
                }
                if ($locked->status !== 'draft') {
                    throw new PurchasesException('This '.strtolower($locked->kindLabel()).' is '.$locked->status.' and cannot be submitted.', 'bad_state');
                }
                if ($locked->total_cents <= 0) {
                    throw new PurchasesException('There is nothing to submit: the total is zero.', 'empty');
                }

                $matches = $this->duplicates->forBill($context, $locked->party_id, $locked->supplier_reference, $locked->total_cents, $locked->currency, CarbonImmutable::parse($locked->bill_date->toDateString()), $locked->id, $locked->kind);
                if ($matches->isNotEmpty()) {
                    if (empty($options['confirm_duplicate'])) {
                        throw new DuplicateSuspected($matches);
                    }
                    $locked->duplicate_ack = ['by' => $by->id, 'at' => now()->toIso8601String(), 'matches' => $matches->map(fn ($m) => ['id' => $m['bill']->id, 'reason' => $m['reason']])->all()];
                }

                $locked->forceFill(['submitted_by' => $by->id, 'submitted_at' => now(), 'rejection_reason' => null, 'post_error' => null])->save();

                return $locked;
            });

            if (in_array($bill->status, ['awaiting_approval', 'open', 'partially_paid', 'paid'], true)) {
                return $bill;
            }

            $baseCurrency = $this->ledger->baseCurrency($context);

            if (ApprovalService::requiresApproval('bill', $bill->baseTotalCents(), $baseCurrency, $context) && ! $this->approvals->isApproved($bill)) {
                DB::transaction(function () use ($by, $bill) {
                    $bill->forceFill(['status' => 'awaiting_approval'])->save();
                    $this->approvals->request($by, $bill);
                });

                return $bill->refresh();
            }

            return $this->poster->post($bill, $by);
        });
    }

    /** Called by the approvals engine inside the decision's transaction. */
    public function approvalDecided(Bill $bill, ApprovalRequest $request): void
    {
        LedgerContext::run($bill->ledgerContext(), function () use ($bill, $request) {
            $locked = Bill::query()->whereKey($bill->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->status !== 'awaiting_approval') {
                return;
            }

            if ($request->status === ApprovalRequest::APPROVED) {
                $locked->forceFill(['status' => 'approved', 'post_error' => null])->save();

                try {
                    DB::transaction(fn () => $this->poster->post($locked, User::query()->find($request->decided_by)));
                } catch (PurchasesException|AccountingException $e) {
                    // approved is approved; the posting waits for its cause to be fixed (an open period, an exchange rate)
                    $locked->refresh()->forceFill(['status' => 'approved', 'post_error' => mb_substr($e->getMessage(), 0, 255)])->save();
                }

                return;
            }

            $locked->forceFill([
                'status' => 'draft',
                'rejection_reason' => $request->status === ApprovalRequest::REJECTED ? mb_substr((string) $request->reason, 0, 1000) : null,
            ])->save();
        });
    }

    /** Post a bill that was approved but could not be posted at the time. */
    public function post(User $by, Bill $bill): Bill
    {
        $context = $bill->ledgerContext();

        return LedgerContext::run($context, function () use ($by, $bill, $context) {
            $fresh = Bill::query()->findOrFail($bill->getKey());

            if ($fresh->isPosted()) {
                return $fresh;
            }
            if ($fresh->status !== 'approved') {
                throw new PurchasesException('Only an approved bill can be posted from here. Submit a draft instead.', 'bad_state');
            }
            if (ApprovalService::requiresApproval('bill', $fresh->baseTotalCents(), $this->ledger->baseCurrency($context), $context) && ! $this->approvals->isApproved($fresh)) {
                throw new PurchasesException('The amount changed since it was approved. Ask for approval again.', 'not_approved');
            }

            return $this->poster->post($fresh, $by);
        });
    }

    /** Take a bill that is waiting for approval, or approved but not posted, back to draft. */
    public function withdraw(User $by, Bill $bill): Bill
    {
        return LedgerContext::run($bill->ledgerContext(), fn () => DB::transaction(function () use ($by, $bill) {
            $locked = Bill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === 'draft') {
                return $locked;
            }
            if (! in_array($locked->status, ['awaiting_approval', 'approved'], true)) {
                throw new PurchasesException('Only a bill that has not been posted can be taken back to draft. Void a posted bill instead.', 'bad_state');
            }

            $pending = $locked->status === 'awaiting_approval' ? $this->approvals->latestFor($locked) : null;

            $locked->forceFill(['status' => 'draft', 'post_error' => null])->save();

            if ($pending?->isPending()) {
                $this->approvals->cancel($by, $pending, 'Withdrawn by the person who asked');
            }

            return $locked->refresh();
        }));
    }

    /** Delete a draft that never went anywhere. Nothing else is ever deleted. */
    public function deleteDraft(Bill $bill): void
    {
        LedgerContext::run($bill->ledgerContext(), fn () => DB::transaction(function () use ($bill) {
            $locked = Bill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft' || $locked->number !== null) {
                throw new PurchasesException('Only a draft can be deleted. A bill that was posted is voided, with a reason.', 'not_draft');
            }

            $locked->attachments()->update(['attachable_type' => null, 'attachable_id' => null]); // the files stay in the inbox
            $locked->delete();
        }));
    }

    /** Reverse a posted bill nothing has been paid against. A reversing entry is posted; the bill stays on file as void. */
    public function void(User $by, Bill $bill, string $reason): Bill
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new PurchasesException('Say why this is being voided.', 'reason_required');
        }

        return LedgerContext::run($bill->ledgerContext(), fn () => DB::transaction(function () use ($by, $bill, $reason) {
            $locked = Bill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === 'void') {
                return $locked;
            }

            if (! $locked->isPosted()) {
                $locked->forceFill(['status' => 'void', 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => mb_substr($reason, 0, 255)])->save();

                return $locked;
            }

            if ($this->status->allocatedCents($locked) > 0) {
                throw new PurchasesException('Money has been paid against this '.strtolower($locked->kindLabel()).'. Reverse the payment first, then void it.', 'has_payments');
            }
            if (! $locked->isCreditNote() && $locked->creditedCents() > 0) {
                throw new PurchasesException('A credit note is applied to this bill. Void the credit note first.', 'has_credit');
            }

            $entry = app(JournalPoster::class)->reverse(JournalEntry::query()->findOrFail($locked->posted_entry_id), 'Voided: '.$reason);

            $locked->forceFill(['status' => 'void', 'void_entry_id' => $entry->id, 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => mb_substr($reason, 0, 255), 'version' => $locked->version + 1])->save();

            if ($locked->isCreditNote() && $locked->credit_for_bill_id !== null) {
                $target = Bill::query()->find($locked->credit_for_bill_id);
                if ($target !== null) {
                    $this->status->refresh($target);
                }
            }

            return $locked;
        }));
    }

    /**
     * Apply a supplier credit that is not linked to a bill to a bill from the same supplier (base currency only). The credit
     * must not be more than is still owed on the bill.
     */
    public function applyCredit(User $by, Bill $credit, Bill $bill): Bill
    {
        return LedgerContext::run($credit->ledgerContext(), fn () => DB::transaction(function () use ($by, $credit, $bill) {
            $c = Bill::query()->whereKey($credit->getKey())->lockForUpdate()->firstOrFail();
            $b = Bill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            if (! $c->isCreditNote() || ! $c->isPosted() || $c->credit_for_bill_id !== null || $c->status !== 'open') {
                throw new PurchasesException('Only a posted supplier credit that has not been applied can be applied.', 'bad_credit');
            }
            if ($b->isCreditNote() || ! $b->isPosted() || $b->party_id !== $c->party_id || strtoupper($b->currency) !== strtoupper($c->currency)) {
                throw new PurchasesException('A credit can only be applied to a posted bill from the same supplier in the same currency.', 'bad_credit_target');
            }
            if (strtoupper($c->currency) !== $this->ledger->baseCurrencyOrDefault($c->ledgerContext())) {
                throw new PurchasesException('Only credits in the ledger\'s own currency can be applied after the fact.', 'foreign_credit');
            }
            if ($c->total_cents > $this->status->openCents($b)) {
                throw new PurchasesException('This credit is more than is still owed on '.$b->displayNumber().'.', 'credit_exceeds');
            }

            $c->forceFill(['credit_for_bill_id' => $b->id, 'status' => 'paid', 'version' => $c->version + 1])->save();
            FinanceAudit::record($c->ledgerOwner(), 'credit_applied', 'bill', $c->id, FinanceAudit::label($c), ['credit_for_bill_id' => ['after' => $b->id]], null, $c->currency, $by->id);
            $this->status->refresh($b);

            return $c;
        }));
    }

    /**
     * The early-payment discount on a bill as a supplier credit note, built from the bill's own lines so the VAT adjusts the way the
     * VAT Act expects (a credit note, not a silent write-off). Posted at once. Returns the credit note, or null when no discount applies.
     */
    public function takeEarlyPaymentDiscount(User $by, Bill $bill, CarbonImmutable $paidOn): ?Bill
    {
        return LedgerContext::run($bill->ledgerContext(), fn () => DB::transaction(function () use ($by, $bill, $paidOn) {
            $locked = Bill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            $existing = Bill::query()->where('credit_for_bill_id', $locked->id)->where('credit_reason', 'Early-payment discount')->where('status', '!=', 'void')->first();
            if ($existing !== null) {
                return $existing; // taken already: idempotent
            }

            $bp = (int) $locked->early_payment_discount_bp;
            $days = (int) $locked->early_payment_days;

            if ($bp <= 0 || $days <= 0 || ! $locked->isPosted() || $locked->isCreditNote()) {
                return null;
            }
            if ($paidOn->greaterThan(CarbonImmutable::parse($locked->bill_date->toDateString())->addDays($days))) {
                throw new PurchasesException("The early-payment discount applies when the bill is paid within {$days} days of the bill date.", 'discount_expired');
            }

            $lines = [];
            foreach ($locked->lines as $line) {
                $discount = Money::mulDivRound($line->gross_cents, $bp, 10_000);
                if ($discount > 0) {
                    $lines[] = ['description' => 'Early-payment discount: '.$line->description, 'gl_account_id' => $line->gl_account_id, 'quantity' => '1', 'unit_price_cents' => $discount, 'tax_code' => $line->tax_code, 'dimension' => $line->dimension];
                }
            }
            if ($lines === []) {
                return null;
            }

            $credit = $this->saveDraft($locked->ledgerContext(), $by, [
                'kind' => 'credit_note', 'party_id' => $locked->party_id, 'bill_date' => $paidOn->toDateString(), 'currency' => $locked->currency,
                'tax_inclusive' => true, 'document_type' => 'credit_note', 'supplier_reference' => 'DISC-'.($locked->number ?? $locked->id),
                'credit_for_bill_id' => $locked->id, 'credit_reason' => 'Early-payment discount', 'lines' => $lines,
            ]);

            return $this->poster->post($credit, $by);
        }));
    }

    private function cleanVat(mixed $value): ?string
    {
        $clean = preg_replace('/\s+/', '', trim((string) $value));

        return $clean === '' || $clean === null ? null : mb_substr($clean, 0, 30);
    }
}
