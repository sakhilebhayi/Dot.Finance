<?php

namespace App\Services\Purchases;

use App\Models\Accounting\JournalEntry;
use App\Models\Controls\ApprovalRequest;
use App\Models\Purchases\ExpenseClaim;
use App\Models\Purchases\ExpenseClaimLine;
use App\Models\Shared\Party;
use App\Models\User;
use App\Services\Accounting\Exceptions\AccountingException;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Controls\ApprovalService;
use App\Services\Shared\Numbering;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Expense claims: a member spent their own money on the business and is owed it back. Each receipt is a line, taxed on its own
 * (a receipt is its own tax document). On approval the claim posts   Dr expense (+ Dr VAT input where the receipt allows it)
 * / Cr accounts payable   in the name of a supplier-type party that stands for the claimant, so paying them back is an ordinary supplier
 * payment and the payable still ties to the books. Claims are in the ledger's own currency.
 *
 * The states and rules match the bill workflow (draft, awaiting approval, approved but not posted, open, partially paid, paid, void),
 * and so does the separation of duties: the approvals engine refuses the person who asked.
 */
final class ClaimWorkflow
{
    public function __construct(
        private readonly BillCalculator $calculator,
        private readonly PurchaseLedger $ledger,
        private readonly JournalPoster $poster,
        private readonly Numbering $numbering,
        private readonly ApprovalService $approvals,
        private readonly BillStatus $status,
    ) {}

    /**
     * @param  array<string, mixed>  $data  claimant_user_id, title, claim_date, notes, client_token, lines[]{spent_on, merchant, description, receipt_type, merchant_vat_number, receipt_reference, gl_account_id, tax_code, dimension, gross_cents}
     *
     * @throws PurchasesException
     */
    public function saveDraft(LedgerContext $context, User $by, array $data, ?ExpenseClaim $claim = null): ExpenseClaim
    {
        return LedgerContext::run($context, function () use ($context, $by, $data, $claim) {
            if ($claim === null && ($token = $data['client_token'] ?? null)) {
                $existing = ExpenseClaim::query()->where('client_token', $token)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            $claimant = User::query()->find((int) ($data['claimant_user_id'] ?? $by->id));
            if ($claimant === null || LedgerAccess::for($claimant, $context) === null) {
                throw new PurchasesException('The person claiming must be a member of this ledger.', 'bad_claimant');
            }

            $title = trim((string) ($data['title'] ?? ''));
            if ($title === '') {
                throw new PurchasesException('Give the claim a title, for example "Client visit, Durban, October".', 'title_required');
            }

            $base = $this->ledger->baseCurrencyOrDefault($context);
            $computed = $this->computeLines($context, $data['lines'] ?? []);

            $attributes = [
                'claimant_user_id' => $claimant->id, 'title' => mb_substr($title, 0, 120), 'claim_date' => CarbonImmutable::parse($data['claim_date'] ?? now()->toDateString())->toDateString(),
                'currency' => $base, 'subtotal_cents' => $computed['subtotal'], 'tax_cents' => $computed['tax'], 'total_cents' => $computed['total'],
                'claimable_tax_cents' => $computed['claimable'], 'vat_review' => $computed['review'], 'rule_versions' => $computed['versions'],
                'notes' => isset($data['notes']) ? mb_substr((string) $data['notes'], 0, 5000) : null,
            ];

            try {
                return DB::transaction(function () use ($claim, $context, $by, $attributes, $computed, $data) {
                    if ($claim === null) {
                        $claim = ExpenseClaim::query()->create($context->ownerAttributes() + $attributes + ['status' => 'draft', 'client_token' => $data['client_token'] ?? null, 'created_by' => $by->id]);
                    } else {
                        $locked = ExpenseClaim::query()->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();
                        if ($locked->status !== 'draft') {
                            throw new PurchasesException('Only a draft claim can be edited.', 'not_draft');
                        }
                        if (isset($data['expected_version']) && (int) $data['expected_version'] !== $locked->version) {
                            throw new PurchasesException('Someone saved this claim while you were editing it. Open it again to see their changes.', 'stale');
                        }
                        $locked->fill($attributes + ['version' => $locked->version + 1, 'rejection_reason' => null, 'post_error' => null])->save();

                        // keep the receipt files with their receipts: lines are updated in place by position, extra ones removed
                        $this->syncLines($locked, $computed['lines'], $context);
                        $claim = $locked;

                        return $claim->load('lines');
                    }

                    foreach ($computed['lines'] as $line) {
                        $claim->lines()->create($context->ownerAttributes() + $line);
                    }

                    return $claim->load('lines');
                });
            } catch (UniqueConstraintViolationException) {
                $token = $data['client_token'] ?? null;
                $existing = $token ? ExpenseClaim::query()->where('client_token', $token)->first() : null;
                if ($existing !== null) {
                    return $existing;
                }
                throw new PurchasesException('That claim was already created.', 'already_created');
            }
        });
    }

    /** Add one receipt to a draft claim (used when a captured receipt is added to a claim). */
    public function addReceipt(LedgerContext $context, User $by, ExpenseClaim $claim, array $receipt): ExpenseClaim
    {
        return LedgerContext::run($context, function () use ($context, $by, $claim, $receipt) {
            $existing = $claim->lines()->get()->map(fn (ExpenseClaimLine $l) => [
                'spent_on' => $l->spent_on->toDateString(), 'merchant' => $l->merchant, 'description' => $l->description, 'receipt_type' => $l->receipt_type,
                'merchant_vat_number' => $l->merchant_vat_number, 'receipt_reference' => $l->receipt_reference, 'gl_account_id' => $l->gl_account_id,
                'tax_code' => $l->tax_code, 'dimension' => $l->dimension, 'gross_cents' => $l->gross_cents,
            ])->all();

            return $this->saveDraft($context, $by, [
                'claimant_user_id' => $claim->claimant_user_id, 'title' => $claim->title, 'claim_date' => $claim->claim_date->toDateString(), 'notes' => $claim->notes,
                'lines' => [...$existing, $receipt],
            ], $claim);
        });
    }

    public function submit(User $by, ExpenseClaim $claim): ExpenseClaim
    {
        $context = $claim->ledgerContext();

        return LedgerContext::run($context, function () use ($by, $claim, $context) {
            $this->ledger->requireAccounting($context);

            $claim = DB::transaction(function () use ($by, $claim, $context) {
                $locked = ExpenseClaim::query()->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();

                if (in_array($locked->status, ['awaiting_approval', 'approved', 'open', 'partially_paid', 'paid'], true)) {
                    return $locked;
                }
                if ($locked->status !== 'draft') {
                    throw new PurchasesException('This claim is '.$locked->status.' and cannot be submitted.', 'bad_state');
                }
                if ($locked->total_cents <= 0 || ! $locked->lines()->exists()) {
                    throw new PurchasesException('There is nothing to submit: add at least one receipt.', 'empty');
                }

                $party = $this->claimantParty($context, User::query()->findOrFail($locked->claimant_user_id));
                $locked->forceFill(['party_id' => $party->id, 'submitted_at' => now(), 'rejection_reason' => null, 'post_error' => null])->save();

                return $locked;
            });

            if ($claim->status !== 'draft') {
                return $claim;
            }

            if (ApprovalService::requiresApproval('expense', $claim->total_cents, $this->ledger->baseCurrency($context), $context) && ! $this->approvals->isApproved($claim)) {
                DB::transaction(function () use ($by, $claim) {
                    $claim->forceFill(['status' => 'awaiting_approval'])->save();
                    $this->approvals->request($by, $claim);
                });

                return $claim->refresh();
            }

            return $this->post($by, $claim);
        });
    }

    public function approvalDecided(ExpenseClaim $claim, ApprovalRequest $request): void
    {
        LedgerContext::run($claim->ledgerContext(), function () use ($claim, $request) {
            $locked = ExpenseClaim::query()->whereKey($claim->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->status !== 'awaiting_approval') {
                return;
            }

            if ($request->status === ApprovalRequest::APPROVED) {
                $locked->forceFill(['status' => 'approved', 'post_error' => null])->save();

                try {
                    DB::transaction(fn () => $this->post(User::query()->find($request->decided_by) ?? User::query()->findOrFail($locked->claimant_user_id), $locked));
                } catch (PurchasesException|AccountingException $e) {
                    $locked->refresh()->forceFill(['status' => 'approved', 'post_error' => mb_substr($e->getMessage(), 0, 255)])->save();
                }

                return;
            }

            $locked->forceFill(['status' => 'draft', 'rejection_reason' => $request->status === ApprovalRequest::REJECTED ? mb_substr((string) $request->reason, 0, 1000) : null])->save();
        });
    }

    /** Post a claim that needs no approval, or one that was approved but could not be posted at the time. */
    public function post(User $by, ExpenseClaim $claim): ExpenseClaim
    {
        $context = $claim->ledgerContext();

        return LedgerContext::run($context, fn () => DB::transaction(function () use ($by, $claim, $context) {
            $locked = ExpenseClaim::query()->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isPosted()) {
                return $locked;
            }
            if (! in_array($locked->status, ['draft', 'approved', 'awaiting_approval'], true)) {
                throw new PurchasesException('This claim is '.$locked->status.' and cannot be posted.', 'bad_state');
            }

            $settings = $this->ledger->requireAccounting($context);
            $lines = $locked->lines()->get();

            if ($lines->isEmpty() || $locked->total_cents <= 0) {
                throw new PurchasesException('There is nothing to post.', 'empty');
            }

            $fresh = $this->computeLines($context, $lines->map(fn (ExpenseClaimLine $l) => $this->lineInput($l))->all());
            if ($fresh['total'] !== $locked->total_cents) {
                throw new PurchasesException('The tax rules now give a different total from the amount that was submitted. Open the claim, save it and submit it again.', 'total_changed');
            }
            foreach ($lines as $i => $line) {
                $line->forceFill(['claimable_tax_cents' => $fresh['lines'][$i]['claimable_tax_cents'], 'claim_reason' => $fresh['lines'][$i]['claim_reason']])->save();
            }
            $locked->forceFill(['claimable_tax_cents' => $fresh['claimable'], 'vat_review' => $fresh['review'], 'rule_versions' => $fresh['versions']])->save();

            $party = $locked->party_id ? $locked->party : $this->claimantParty($context, User::query()->findOrFail($locked->claimant_user_id));
            $locked->party_id = $party->id;

            $issue = CarbonImmutable::now();
            if ($this->numbering->peek('expense_claim', $issue, $context) === null) {
                $this->numbering->configure('expense_claim', 'EXP', 5, true, $context);
            }
            $number = $this->numbering->next('expense_claim', $issue, $context);

            $out = [];
            $vat = [];
            foreach ($lines as $line) {
                $cost = $line->net_cents + ($line->tax_cents - $line->claimable_tax_cents);
                if ($cost > 0) {
                    $out[] = new PostingLine($line->gl_account_id, debitCents: $cost, partyId: $party->id, dimension: $line->dimension, description: mb_substr($line->merchant.': '.$line->description, 0, 255));
                }
                if ($line->claimable_tax_cents > 0) {
                    $vat[$line->tax_code_id ?? 0] = ($vat[$line->tax_code_id ?? 0] ?? 0) + $line->claimable_tax_cents;
                }
            }
            foreach ($vat as $taxCodeId => $claim) {
                $out[] = new PostingLine('vat_input', debitCents: $claim, partyId: $party->id, taxCodeId: $taxCodeId ?: null, description: 'Input VAT');
            }
            $out[] = new PostingLine('ap', creditCents: $locked->total_cents, partyId: $party->id, description: 'Expense claim '.$number);

            $entry = $this->poster->post(new PostingRequest(CarbonImmutable::parse($locked->claim_date->toDateString()), 'bill', 'claim-'.$locked->id, 1, 'Expense claim '.$number.' for '.mb_substr($party->name, 0, 100), $out, $by->id));

            $locked->forceFill(['number' => $number, 'status' => 'open', 'posted_entry_id' => $entry->id, 'posted_at' => now(), 'posted_by' => $by->id, 'post_error' => null, 'version' => $locked->version + 1])->save();

            return $locked;
        }));
    }

    public function withdraw(User $by, ExpenseClaim $claim): ExpenseClaim
    {
        return LedgerContext::run($claim->ledgerContext(), fn () => DB::transaction(function () use ($by, $claim) {
            $locked = ExpenseClaim::query()->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === 'draft') {
                return $locked;
            }
            if (! in_array($locked->status, ['awaiting_approval', 'approved'], true)) {
                throw new PurchasesException('Only a claim that has not been posted can be taken back to draft.', 'bad_state');
            }

            $pending = $locked->status === 'awaiting_approval' ? $this->approvals->latestFor($locked) : null;
            $locked->forceFill(['status' => 'draft', 'post_error' => null])->save();

            if ($pending?->isPending()) {
                $this->approvals->cancel($by, $pending, 'Withdrawn by the person who asked');
            }

            return $locked->refresh();
        }));
    }

    public function deleteDraft(ExpenseClaim $claim): void
    {
        LedgerContext::run($claim->ledgerContext(), fn () => DB::transaction(function () use ($claim) {
            $locked = ExpenseClaim::query()->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft' || $locked->number !== null) {
                throw new PurchasesException('Only a draft claim can be deleted.', 'not_draft');
            }

            foreach ($locked->lines as $line) {
                $line->attachments()->update(['attachable_type' => null, 'attachable_id' => null]);
            }
            $locked->delete();
        }));
    }

    public function void(User $by, ExpenseClaim $claim, string $reason): ExpenseClaim
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new PurchasesException('Say why this claim is being voided.', 'reason_required');
        }

        return LedgerContext::run($claim->ledgerContext(), fn () => DB::transaction(function () use ($by, $claim, $reason) {
            $locked = ExpenseClaim::query()->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === 'void') {
                return $locked;
            }

            if ($locked->isPosted()) {
                if ($this->status->allocatedCents($locked) > 0) {
                    throw new PurchasesException('Money has been paid against this claim. Reverse the payment first.', 'has_payments');
                }
                $entry = $this->poster->reverse(JournalEntry::query()->findOrFail($locked->posted_entry_id), 'Voided: '.$reason);
                $locked->void_entry_id = $entry->id;
            }

            $locked->forceFill(['status' => 'void', 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => mb_substr($reason, 0, 255), 'version' => $locked->version + 1])->save();

            return $locked;
        }));
    }

    /** The supplier-type party that stands for a member who claims expenses (made once, then reused). */
    public function claimantParty(LedgerContext $context, User $claimant): Party
    {
        return LedgerContext::run($context, function () use ($context, $claimant) {
            $ref = 'claimant:'.$claimant->id;

            return Party::query()->withTrashed()->where('external_ref', $ref)->first()
                ?? Party::query()->create($context->ownerAttributes() + [
                    'type' => 'supplier', 'name' => mb_substr($claimant->name, 0, 200).' (expense claims)', 'external_ref' => $ref, 'status' => 'active',
                    'currency' => $this->ledger->baseCurrencyOrDefault($context), 'payment_terms_days' => 0,
                ]);
        });
    }

    /** @return array<string, mixed> */
    private function lineInput(ExpenseClaimLine $l): array
    {
        return [
            'spent_on' => $l->spent_on->toDateString(), 'merchant' => $l->merchant, 'description' => $l->description, 'receipt_type' => $l->receipt_type,
            'merchant_vat_number' => $l->merchant_vat_number, 'receipt_reference' => $l->receipt_reference, 'gl_account_id' => $l->gl_account_id,
            'tax_code' => $l->tax_code, 'dimension' => $l->dimension, 'gross_cents' => $l->gross_cents,
        ];
    }

    /**
     * Each receipt is taxed as its own document (VAT inclusive, quantity 1).
     *
     * @param  list<array<string, mixed>>  $receipts
     * @return array{lines: list<array<string, mixed>>, subtotal: int, tax: int, total: int, claimable: int, review: list<array<string, mixed>>, versions: list<string>}
     */
    private function computeLines(LedgerContext $context, array $receipts): array
    {
        if ($receipts === []) {
            throw new PurchasesException('Add at least one receipt.', 'no_lines');
        }

        $base = $this->ledger->baseCurrencyOrDefault($context);
        $lines = [];
        $review = [];
        $versions = [];
        $sum = ['subtotal' => 0, 'tax' => 0, 'total' => 0, 'claimable' => 0];

        foreach (array_values($receipts) as $i => $r) {
            $n = $i + 1;
            $merchant = trim((string) ($r['merchant'] ?? ''));
            if ($merchant === '') {
                throw new PurchasesException("Receipt {$n}: say who it is from.", 'no_merchant');
            }
            $gross = $r['gross_cents'] ?? null;
            if (! is_int($gross) || $gross <= 0) {
                throw new PurchasesException("Receipt {$n}: the amount must be greater than zero.", 'bad_amount');
            }
            $type = $r['receipt_type'] ?? 'unknown';
            if (! in_array($type, ExpenseClaimLine::RECEIPT_TYPES, true)) {
                throw new PurchasesException("Receipt {$n}: unknown receipt type.", 'bad_receipt_type');
            }
            $spent = CarbonImmutable::parse($r['spent_on'] ?? now()->toDateString());
            $vatNumber = isset($r['merchant_vat_number']) ? preg_replace('/\s+/', '', trim((string) $r['merchant_vat_number'])) : null;

            try {
                $c = $this->calculator->compute($context, [[
                    'description' => (string) ($r['description'] ?? ''), 'gl_account_id' => $r['gl_account_id'] ?? 0, 'quantity' => '1', 'unit_price_cents' => $gross,
                    'tax_code' => (string) ($r['tax_code'] ?? ''), 'dimension' => $r['dimension'] ?? null,
                ]], ['date' => $spent, 'currency' => $base, 'tax_inclusive' => true, 'document_type' => $type === 'none' ? 'none' : $type, 'supplier_vat_number' => $vatNumber]);
            } catch (PurchasesException $e) {
                throw new PurchasesException("Receipt {$n}: ".$e->getMessage(), $e->reason);
            }

            $row = $c->lines[0];
            $lines[] = [
                'line_no' => $n, 'spent_on' => $spent->toDateString(), 'merchant' => mb_substr($merchant, 0, 120), 'description' => $row['description'], 'receipt_type' => $type,
                'merchant_vat_number' => $vatNumber !== '' ? $vatNumber : null, 'receipt_reference' => isset($r['receipt_reference']) ? mb_substr(trim((string) $r['receipt_reference']), 0, 60) : null,
                'gl_account_id' => $row['gl_account_id'], 'tax_code' => $row['tax_code'], 'tax_code_id' => $row['tax_code_id'], 'tax_rate_bp' => $row['tax_rate_bp'],
                'dimension' => $row['dimension'], 'gross_cents' => $row['gross_cents'], 'net_cents' => $row['net_cents'], 'tax_cents' => $row['tax_cents'],
                'claimable_tax_cents' => $row['claimable_tax_cents'], 'claim_reason' => $row['claim_reason'],
            ];
            foreach ($c->vatReview as $item) {
                $review[] = ['line_no' => $n] + $item;
            }
            $versions = array_values(array_unique([...$versions, ...$c->ruleVersions]));
            $sum['subtotal'] += $c->subtotalCents;
            $sum['tax'] += $c->taxCents;
            $sum['total'] += $c->totalCents;
            $sum['claimable'] += $c->claimableTaxCents;
        }

        return $sum + ['lines' => $lines, 'review' => $review, 'versions' => $versions];
    }

    /**
     * Replace a draft claim's lines by position, so a receipt keeps the file attached to it when its details are corrected.
     *
     * @param  list<array<string, mixed>>  $new
     */
    private function syncLines(ExpenseClaim $claim, array $new, LedgerContext $context): void
    {
        $existing = $claim->lines()->get()->keyBy('line_no');

        foreach ($new as $line) {
            if ($existing->has($line['line_no'])) {
                $existing[$line['line_no']]->forceFill($line)->save();
            } else {
                $claim->lines()->create($context->ownerAttributes() + $line);
            }
        }

        foreach ($existing as $no => $old) {
            if ($no > count($new)) {
                $old->attachments()->update(['attachable_type' => null, 'attachable_id' => null]);
                $old->delete();
            }
        }
    }
}
