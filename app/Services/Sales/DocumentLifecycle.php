<?php

namespace App\Services\Sales;

use App\Models\Accounting\JournalEntry;
use App\Models\Sales\SalesApplication;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesRefund;
use App\Models\User;
use App\Services\Accounting\Exceptions\AccountingException;
use App\Services\Accounting\Exceptions\PeriodClosed;
use App\Services\Accounting\JournalPoster;
use App\Services\Sales\Support\SalesViolation;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What can happen to a document after it has been issued, and the drafts that grow out of one: void it (with a
 * reversing entry and a reason), correct it with a credit or debit note, convert a quote or pro forma into an
 * invoice, mark a quote accepted, declined or expired. Nothing here edits an issued document.
 */
final class DocumentLifecycle
{
    public function __construct(
        private readonly JournalPoster $poster,
        private readonly DocumentDrafts $drafts,
        private readonly CreditNotes $creditNotes,
        private readonly ApplicationEngine $engine,
    ) {}

    /**
     * Void an issued document. The journal entry is reversed (same date, linked both ways), the number stays on
     * record as void, and the reason is kept. Refused while anything has been applied to it: reverse the payment,
     * or issue a credit note.
     *
     * @throws SalesViolation
     */
    public function void(SalesDocument $document, string $reason, User $by): SalesDocument
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new SalesViolation('Say why the document is being voided.', 'reason');
        }
        $context = $document->ledgerContext();

        return LedgerContext::run($context, function () use ($document, $reason, $by, $context) {
            try {
                return DB::transaction(function () use ($document, $reason, $by, $context) {
                    $locked = SalesDocument::query()->forContext($context)->whereKey($document->id)->lockForUpdate()->firstOrFail();

                    if ($locked->isVoid()) {
                        return $locked;
                    }
                    if (! $locked->isSealed()) {
                        throw new SalesViolation('A draft has no number, so there is nothing to void: discard it instead.', 'sealed');
                    }

                    $this->assertVoidable($locked, $context);

                    $reversalId = null;
                    if ($locked->postsToBooks() && $locked->gl_entry_id !== null) {
                        $entry = JournalEntry::query()->forContext($context)->find($locked->gl_entry_id);
                        if ($entry !== null && $entry->status === 'posted') {
                            $reversalId = $this->poster->reverse($entry, 'Voided: '.$reason)->id;
                        }
                    }

                    $locked->forceFill([
                        'status' => 'void', 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => mb_substr($reason, 0, 255), 'void_entry_id' => $reversalId,
                        'version' => (int) $locked->version + 1,
                    ])->save();

                    SalesTrail::event($locked, 'voided', $by->id, ['reason' => mb_substr($reason, 0, 255)]);
                    SalesTrail::audit($locked, 'voided', 'sales_document', $locked->auditLabel(), ['status' => ['before' => 'issued', 'after' => 'void']], $locked->currency, $by->id);

                    return $locked;
                });
            } catch (PeriodClosed $e) {
                throw new SalesViolation($e->getMessage().' An invoice in a closed period cannot be voided: issue a credit note in the current period instead.', 'period_closed');
            } catch (AccountingException $e) {
                throw new SalesViolation('The books refused the reversal: '.$e->getMessage(), 'posting');
            }
        });
    }

    /** @throws SalesViolation */
    private function assertVoidable(SalesDocument $document, LedgerContext $context): void
    {
        $name = $document->title().' '.$document->number;

        if ($document->isReceivable()) {
            $applied = SalesApplication::query()->forContext($context)->where('target_document_id', $document->id)->whereNull('released_at')->exists();
            if ($applied || (int) $document->written_off_cents > 0) {
                throw new SalesViolation("{$name} has payments, credits or a write-off applied to it. Reverse or release those first, or issue a credit note instead of voiding.", 'applied');
            }
            $children = SalesDocument::query()->forContext($context)->where('parent_id', $document->id)->whereNotNull('sealed_at')->where('status', '<>', 'void')->exists();
            if ($children) {
                throw new SalesViolation("{$name} has credit or debit notes against it. Void those first.", 'has_children');
            }
        }

        if ($document->isCredit()) {
            $used = SalesApplication::query()->forContext($context)->where('source_document_id', $document->id)->whereNull('released_at')->exists();
            $refunded = SalesRefund::query()->forContext($context)->where('source_document_id', $document->id)->where('status', 'recorded')->exists();
            if ($used || $refunded) {
                throw new SalesViolation("{$name} has been applied to an invoice or refunded. Release or reverse that first.", 'applied');
            }
        }
    }

    // ---- drafts that grow out of a document -----------------------------------------------------

    /** A draft credit note crediting what is left on every line of the invoice. Needs a reason before it can be issued. */
    public function draftCreditNote(SalesDocument $invoice, User $by, ?string $reason = null): SalesDocument
    {
        $context = $invoice->ledgerContext();
        if (! $invoice->isSealed() || $invoice->isVoid() || ! in_array($invoice->kind, ['tax_invoice', 'debit_note'], true)) {
            throw new SalesViolation('Only an issued invoice or debit note can be credited.', 'parent');
        }

        $lines = $this->creditNotes->remainingLines($invoice);
        if ($lines === []) {
            throw new SalesViolation('Everything on '.$invoice->number.' has already been credited.', 'credit_limit');
        }

        return $this->drafts->create($context, $by, [
            'kind' => 'credit_note', 'party_id' => $invoice->party_id, 'parent_id' => $invoice->id, 'currency' => $invoice->currency,
            'issue_date' => now()->toDateString(), 'prices_include_tax' => $invoice->prices_include_tax, 'reason' => $reason, 'reference' => $invoice->reference,
        ], $lines);
    }

    /** A draft debit note raising the invoice by one adjustment line (priced at zero until the person sets it). */
    public function draftDebitNote(SalesDocument $invoice, User $by, ?string $reason = null): SalesDocument
    {
        $context = $invoice->ledgerContext();
        if (! $invoice->isSealed() || $invoice->isVoid() || $invoice->kind !== 'tax_invoice') {
            throw new SalesViolation('A debit note corrects an issued tax invoice.', 'parent');
        }
        $first = $invoice->lines()->first();

        return $this->drafts->create($context, $by, [
            'kind' => 'debit_note', 'party_id' => $invoice->party_id, 'parent_id' => $invoice->id, 'currency' => $invoice->currency,
            'issue_date' => now()->toDateString(), 'prices_include_tax' => $invoice->prices_include_tax, 'reason' => $reason, 'reference' => $invoice->reference,
        ], [[
            'description' => 'Adjustment to '.$invoice->number, 'quantity' => '1', 'unit_cents' => 0, 'tax_code' => $first?->tax_code, 'income_account_id' => $first?->income_account_id,
        ]]);
    }

    /**
     * A draft tax invoice copied from a quote or pro forma. Idempotent: converting twice returns the same invoice.
     *
     * @throws SalesViolation
     */
    public function convert(SalesDocument $source, User $by): SalesDocument
    {
        $context = $source->ledgerContext();

        return LedgerContext::run($context, fn () => DB::transaction(function () use ($source, $by, $context) {
            $locked = SalesDocument::query()->forContext($context)->whereKey($source->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->kind, ['quote', 'pro_forma'], true) || ! $locked->isSealed() || $locked->isVoid()) {
                throw new SalesViolation('Only a quote or pro forma invoice that has been issued can be turned into an invoice.', 'invalid');
            }
            if (in_array($locked->status, ['declined', 'expired'], true)) {
                throw new SalesViolation('A '.$locked->status.' quote cannot be turned into an invoice.', 'invalid');
            }

            if ($locked->converted_to_id !== null) {
                $existing = SalesDocument::query()->forContext($context)->find($locked->converted_to_id);
                if ($existing !== null) {
                    return $existing;
                }
            }

            $invoice = $this->drafts->create($context, $by, [
                'kind' => 'tax_invoice', 'party_id' => $locked->party_id, 'currency' => $locked->currency, 'issue_date' => now()->toDateString(),
                'prices_include_tax' => $locked->prices_include_tax, 'reference' => $locked->reference ?: $locked->number, 'notes' => $locked->notes, 'terms' => $locked->terms,
                'source_document_id' => $locked->id,
            ], $locked->lines()->get()->map(fn ($l) => [
                'description' => $l->description, 'unit' => $l->unit, 'quantity' => $l->quantity(), 'unit_cents' => (int) $l->unit_cents, 'discount_bp' => (int) $l->discount_bp,
                'discount_cents' => (int) $l->discount_cents, 'tax_code' => $l->tax_code, 'income_account_id' => $l->income_account_id,
            ])->all());

            $locked->forceFill(['converted_to_id' => $invoice->id] + ($locked->kind === 'quote' && $locked->status === 'issued' ? ['status' => 'accepted', 'accepted_at' => now()] : []))->save();
            SalesTrail::event($locked, 'converted', $by->id, ['to' => $invoice->id]);

            return $invoice;
        }));
    }

    /** Record the customer's answer to a quote. */
    public function answerQuote(SalesDocument $quote, string $answer, User $by): SalesDocument
    {
        if (! in_array($answer, ['accepted', 'declined'], true)) {
            throw new SalesViolation('A quote is accepted or declined.', 'invalid');
        }
        $context = $quote->ledgerContext();

        return LedgerContext::run($context, fn () => DB::transaction(function () use ($quote, $answer, $by, $context) {
            $locked = SalesDocument::query()->forContext($context)->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($locked->kind !== 'quote' || ! $locked->isSealed() || $locked->isVoid()) {
                throw new SalesViolation('Only an issued quote can be accepted or declined.', 'invalid');
            }
            if ($locked->status === $answer) {
                return $locked;
            }
            if ($locked->status === 'expired' || ($locked->valid_until !== null && $locked->valid_until->toDateString() < now()->toDateString() && $locked->status === 'issued')) {
                throw new SalesViolation('This quote has expired. Issue a new one.', 'expired');
            }
            if ($locked->status !== 'issued') {
                throw new SalesViolation('This quote was already '.$locked->status.'.', 'invalid');
            }

            $locked->forceFill(['status' => $answer, $answer === 'accepted' ? 'accepted_at' : 'declined_at' => now()])->save();
            SalesTrail::event($locked, $answer, $by->id);

            return $locked;
        }));
    }

    /** Mark issued quotes past their date as expired. Idempotent. @return int how many */
    public function expireQuotes(LedgerContext $context, ?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();

        return LedgerContext::run($context, function () use ($context, $today) {
            $count = 0;
            $due = SalesDocument::query()->forContext($context)->where('kind', 'quote')->where('status', 'issued')->whereNotNull('valid_until')
                ->where('valid_until', '<', $today->toDateString())->whereNull('converted_to_id')->pluck('id');

            foreach ($due as $id) {
                DB::transaction(function () use ($id, $context, &$count) {
                    $quote = SalesDocument::query()->forContext($context)->whereKey($id)->lockForUpdate()->first();
                    if ($quote !== null && $quote->status === 'issued') {
                        $quote->forceFill(['status' => 'expired', 'expired_at' => now()])->save();
                        SalesTrail::event($quote, 'expired', null);
                        $count++;
                    }
                });
            }

            return $count;
        });
    }
}
