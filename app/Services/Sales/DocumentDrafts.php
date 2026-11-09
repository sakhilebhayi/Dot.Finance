<?php

namespace App\Services\Sales;

use App\Models\Accounting\LedgerSetting;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesDocumentLine;
use App\Models\Shared\Party;
use App\Models\User;
use App\Services\Sales\Support\ComputedDocument;
use App\Services\Sales\Support\SalesViolation;
use App\Services\Shared\FxMath;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creating, editing and discarding drafts. A draft is the only editable state; it has no number and posts nothing.
 * Every figure is worked out by DocumentComputer, never taken from the request.
 */
final class DocumentDrafts
{
    public function __construct(
        private readonly DocumentComputer $computer,
        private readonly SalesProfiles $profiles,
        private readonly CreditNotes $creditNotes,
    ) {}

    /**
     * What the editor shows while a person types: the worked-out document, the tax invoice checklist and warnings.
     * Saves nothing.
     *
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    public function preview(LedgerContext $context, array $header, array $lines, ?SalesDocument $existing = null): array
    {
        $header = $this->normaliseHeader($context, $header, $existing);
        $parent = $this->parent($context, $header);
        if ($parent !== null && $header['kind'] === 'credit_note') {
            $lines = $this->creditNotes->inherit($parent, $lines);
        }
        $computed = $this->computer->compute($this->computeInput($header, $lines, $parent, $existing), $context);

        $document = $this->transient($context, $header, $computed, $parent);
        $profile = $this->profiles->for($context);
        $party = Party::query()->forContext($context)->withTrashed()->find($header['party_id']);
        $checklist = app(TaxInvoiceChecklist::class)->evaluate($document, $profile, $party);

        $warnings = [];
        if ($parent !== null && $header['kind'] === 'credit_note') {
            try {
                $this->creditNotes->assertWithinLimits($parent, $computed, $existing?->id);
            } catch (SalesViolation $e) {
                $warnings[] = $e->getMessage();
            }
        }

        return ['document' => $computed->toArray(), 'checklist' => $checklist, 'warnings' => $warnings, 'due_date' => $header['due_date'], 'valid_until' => $header['valid_until']];
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(LedgerContext $context, User $by, array $header, array $lines): SalesDocument
    {
        $header = $this->normaliseHeader($context, $header, null);
        $token = isset($header['client_token']) ? trim((string) $header['client_token']) : '';

        // a double-submitted form returns the draft the first submit made
        if ($token !== '') {
            $existing = SalesDocument::query()->forContext($context)->where('client_token', $token)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $parent = $this->parent($context, $header);
        if ($parent !== null && $header['kind'] === 'credit_note') {
            $lines = $this->creditNotes->inherit($parent, $lines);
        }
        $computed = $this->computer->compute($this->computeInput($header, $lines, $parent, null), $context);
        $this->guardKind($header, $parent, $computed, null);

        try {
            return DB::transaction(function () use ($context, $by, $header, $computed, $parent, $token) {
                $document = SalesDocument::query()->create($context->ownerAttributes() + $this->columns($header, $computed, $parent) + [
                    'kind' => $header['kind'], 'status' => 'draft', 'party_id' => $header['party_id'], 'created_by' => $by->id,
                    'client_token' => $token !== '' ? mb_substr($token, 0, 64) : null,
                ]);
                $this->writeLines($document, $computed);
                SalesTrail::audit($document, 'created', 'sales_document', $document->auditLabel(), ['kind' => ['after' => $document->kind]], $document->currency, $by->id);

                return $document;
            });
        } catch (UniqueConstraintViolationException) {
            return SalesDocument::query()->forContext($context)->where('client_token', $token)->firstOrFail();
        }
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(SalesDocument $document, User $by, array $header, array $lines, ?int $expectedVersion = null): SalesDocument
    {
        $context = $document->ledgerContext();

        return DB::transaction(function () use ($document, $header, $lines, $expectedVersion, $context) {
            $locked = SalesDocument::query()->forContext($context)->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft') {
                throw new SalesViolation($locked->isSealed()
                    ? 'This document has been issued, so it can no longer be edited. Void it, or issue a credit note.'
                    : 'This document is waiting for approval. Cancel the approval request to edit it.', 'sealed');
            }
            if ($expectedVersion !== null && (int) $locked->version !== $expectedVersion) {
                throw new SalesViolation('This draft was changed by someone else while you were editing it. Reload it and try again.', 'stale');
            }

            $header = $this->normaliseHeader($context, $header + ['kind' => $locked->kind, 'parent_id' => $locked->parent_id], $locked);
            $header['kind'] = $locked->kind;
            $parent = $this->parent($context, $header);
            if ($parent !== null && $locked->kind === 'credit_note') {
                $lines = $this->creditNotes->inherit($parent, $lines);
            }
            $computed = $this->computer->compute($this->computeInput($header, $lines, $parent, $locked), $context);
            $this->guardKind($header, $parent, $computed, $locked);

            $locked->update($this->columns($header, $computed, $parent) + ['party_id' => $header['party_id'], 'version' => (int) $locked->version + 1]);
            $this->writeLines($locked, $computed);

            return $locked->refresh();
        });
    }

    /** Discard a draft. A numbered document is never deleted: this only works while it has no number. */
    public function discard(SalesDocument $document, User $by): void
    {
        $context = $document->ledgerContext();

        DB::transaction(function () use ($document, $by, $context) {
            $locked = SalesDocument::query()->forContext($context)->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($locked->isSealed() || ! in_array($locked->status, ['draft', 'awaiting_approval'], true)) {
                throw new SalesViolation('Only a draft can be discarded. An issued document is voided or credited.', 'sealed');
            }

            SalesTrail::audit($locked, 'deleted', 'sales_document', $locked->auditLabel(), ['kind' => ['before' => $locked->kind]], $locked->currency, $by->id);
            $locked->lines()->delete();
            $locked->delete();
        });
    }

    // ---- internals ---------------------------------------------------------------------------

    /**
     * Fill in defaults and check what the header refers to. Returns the header with dates as strings.
     *
     * @param  array<string, mixed>  $header
     * @return array<string, mixed>
     */
    private function normaliseHeader(LedgerContext $context, array $header, ?SalesDocument $existing): array
    {
        $kind = $header['kind'] ?? $existing?->kind ?? 'tax_invoice';
        if (! in_array($kind, SalesDocument::KINDS, true)) {
            throw new SalesViolation('Unknown document type.', 'invalid');
        }

        $party = Party::query()->forContext($context)->find($header['party_id'] ?? 0);
        if ($party === null) {
            throw new SalesViolation('Pick a customer from this ledger.', 'party');
        }
        if (! $party->isCustomer()) {
            throw new SalesViolation($party->name.' is a supplier, not a customer. Mark them as a customer first.', 'party');
        }
        if ($party->status !== 'active') {
            throw new SalesViolation($party->name.' is archived. Restore the customer before invoicing them.', 'party');
        }

        $profile = $this->profiles->for($context);
        $issue = CarbonImmutable::parse(substr((string) ($header['issue_date'] ?? now()->toDateString()), 0, 10));
        $terms = $party->payment_terms_days ?? $profile->default_terms_days ?? 30;

        $due = ! empty($header['due_date']) ? CarbonImmutable::parse(substr((string) $header['due_date'], 0, 10)) : $issue->addDays((int) $terms);
        if ($due->lt($issue)) {
            throw new SalesViolation('The due date cannot be before the issue date.', 'dates');
        }

        $valid = null;
        if ($kind === 'quote') {
            $valid = ! empty($header['valid_until']) ? CarbonImmutable::parse(substr((string) $header['valid_until'], 0, 10)) : $issue->addDays((int) ($profile->quote_validity_days ?? 30));
            if ($valid->lt($issue)) {
                throw new SalesViolation('A quote cannot expire before it is dated.', 'dates');
            }
        }

        $fxRate = null;
        if (! empty($header['fx_rate']) && is_string($header['fx_rate'])) {
            try {
                $fxRate = FxMath::parseRate($header['fx_rate']);
            } catch (\InvalidArgumentException $e) {
                throw new SalesViolation('The exchange rate is not valid: '.$e->getMessage(), 'fx');
            }
        }

        $settings = LedgerSetting::query()->forContext($context)->first();
        $currency = strtoupper((string) ($header['currency'] ?? $party->currency ?? $settings?->base_currency ?? 'ZAR'));

        return [
            'kind' => $kind, 'party_id' => $party->id, 'issue_date' => $issue->toDateString(),
            'supply_date' => ! empty($header['supply_date']) ? CarbonImmutable::parse(substr((string) $header['supply_date'], 0, 10))->toDateString() : null,
            'due_date' => in_array($kind, ['quote', 'credit_note'], true) ? null : $due->toDateString(),
            'valid_until' => $valid?->toDateString(), 'currency' => $currency,
            'prices_include_tax' => (bool) ($header['prices_include_tax'] ?? $profile->prices_include_tax ?? false),
            'reference' => isset($header['reference']) && trim((string) $header['reference']) !== '' ? mb_substr(trim((string) $header['reference']), 0, 100) : null,
            'reason' => isset($header['reason']) && trim((string) $header['reason']) !== '' ? mb_substr(trim((string) $header['reason']), 0, 255) : null,
            'notes' => isset($header['notes']) && trim((string) $header['notes']) !== '' ? mb_substr(trim((string) $header['notes']), 0, 5000) : null,
            'terms' => isset($header['terms']) && trim((string) $header['terms']) !== '' ? mb_substr(trim((string) $header['terms']), 0, 5000) : null,
            'internal_note' => isset($header['internal_note']) && trim((string) $header['internal_note']) !== '' ? mb_substr(trim((string) $header['internal_note']), 0, 5000) : null,
            'parent_id' => isset($header['parent_id']) ? (int) $header['parent_id'] : null,
            'source_document_id' => isset($header['source_document_id']) ? (int) $header['source_document_id'] : null,
            'fx_rate_e8' => $fxRate, 'client_token' => $header['client_token'] ?? null,
        ];
    }

    /** @param  array<string, mixed>  $header */
    private function parent(LedgerContext $context, array $header): ?SalesDocument
    {
        if (! in_array($header['kind'], ['credit_note', 'debit_note'], true)) {
            return null;
        }

        $parent = isset($header['parent_id']) ? SalesDocument::query()->forContext($context)->find($header['parent_id']) : null;
        if ($parent === null) {
            throw new SalesViolation('A '.str_replace('_', ' ', $header['kind']).' must correct an invoice in this ledger.', 'parent');
        }
        if ((int) $parent->party_id !== (int) $header['party_id']) {
            throw new SalesViolation('A credit or debit note is for the customer of the invoice it corrects.', 'parent');
        }
        $allowed = $header['kind'] === 'credit_note' ? ['tax_invoice', 'debit_note'] : ['tax_invoice'];
        if (! in_array($parent->kind, $allowed, true) || ! $parent->isSealed() || $parent->isVoid()) {
            throw new SalesViolation('It can only correct an issued invoice that has not been voided.', 'parent');
        }

        return $parent;
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function computeInput(array $header, array $lines, ?SalesDocument $parent, ?SalesDocument $existing): array
    {
        return [
            'currency' => $header['currency'], 'issue_date' => $header['issue_date'], 'supply_date' => $header['supply_date'],
            'prices_include_tax' => $header['prices_include_tax'], 'lines' => $lines, 'parent' => $parent, 'kind' => $header['kind'],
            'fx_rate_e8' => $header['fx_rate_e8'],
            'fx_locked' => $existing?->fx_rate_locked ?? false,
            'fx_snapshot' => $existing !== null && $existing->fx_rate_e8 !== null ? ['rate_e8' => $existing->fx_rate_e8, 'as_of' => $existing->fx_rate_as_of?->toDateString(), 'source' => $existing->fx_rate_source] : null,
        ];
    }

    /** @param  array<string, mixed>  $header */
    private function guardKind(array $header, ?SalesDocument $parent, ComputedDocument $computed, ?SalesDocument $existing): void
    {
        if ($header['kind'] === 'credit_note') {
            $this->creditNotes->assertWithinLimits($parent, $computed, $existing?->id);
        }
    }

    /**
     * @param  array<string, mixed>  $header
     * @return array<string, mixed>
     */
    private function columns(array $header, ComputedDocument $c, ?SalesDocument $parent): array
    {
        return [
            'number_series' => null,
            'parent_id' => $parent?->id, 'source_document_id' => $header['source_document_id'],
            'issue_date' => $header['issue_date'], 'supply_date' => $header['supply_date'], 'due_date' => $header['due_date'], 'valid_until' => $header['valid_until'],
            'currency' => $c->currency, 'base_currency' => $c->baseCurrency,
            'fx_rate_e8' => $c->fxRateE8, 'fx_rate_as_of' => $c->fxAsOf, 'fx_rate_source' => $c->fxSource,
            'fx_rate_recorded_at' => $c->fxRateE8 !== null ? now() : null,
            'prices_include_tax' => $c->pricesIncludeTax, 'tax_jurisdiction' => $c->jurisdiction, 'tax_rounding_level' => $c->roundingLevel,
            'tax_rule_versions' => $c->ruleVersions, 'tax_confidence' => $c->confidence, 'vat_registered' => $c->vatRegistered,
            'net_cents' => $c->netCents, 'discount_cents' => $c->discountCents, 'tax_cents' => $c->taxCents, 'total_cents' => $c->totalCents,
            'base_net_cents' => $c->baseNetCents, 'base_tax_cents' => $c->baseTaxCents, 'base_total_cents' => $c->baseTotalCents,
            'reference' => $header['reference'], 'reason' => $header['reason'], 'notes' => $header['notes'], 'terms' => $header['terms'], 'internal_note' => $header['internal_note'],
        ];
    }

    /** Store a fresh computation (totals, rate, tax, lines) on a draft that is being issued. */
    public function persistComputed(SalesDocument $document, ComputedDocument $c): void
    {
        $document->forceFill([
            'currency' => $c->currency, 'base_currency' => $c->baseCurrency, 'fx_rate_e8' => $c->fxRateE8, 'fx_rate_as_of' => $c->fxAsOf, 'fx_rate_source' => $c->fxSource,
            'fx_rate_recorded_at' => $c->fxRateE8 !== null ? now() : null, 'prices_include_tax' => $c->pricesIncludeTax, 'tax_jurisdiction' => $c->jurisdiction,
            'tax_rounding_level' => $c->roundingLevel, 'tax_rule_versions' => $c->ruleVersions, 'tax_confidence' => $c->confidence, 'vat_registered' => $c->vatRegistered,
            'net_cents' => $c->netCents, 'discount_cents' => $c->discountCents, 'tax_cents' => $c->taxCents, 'total_cents' => $c->totalCents,
            'base_net_cents' => $c->baseNetCents, 'base_tax_cents' => $c->baseTaxCents, 'base_total_cents' => $c->baseTotalCents,
        ])->save();
        $this->writeLines($document, $c);
    }

    /** Replace a draft's lines with the computed ones. */
    public function writeLines(SalesDocument $document, ComputedDocument $computed): void
    {
        $document->lines()->delete();

        foreach ($computed->lines as $line) {
            SalesDocumentLine::query()->create($document->ledgerOwner() + ['document_id' => $document->id] + $line->columns());
        }
    }

    /**
     * An unsaved model carrying what the checklist reads, for the live preview.
     *
     * @param  array<string, mixed>  $header
     */
    private function transient(LedgerContext $context, array $header, ComputedDocument $c, ?SalesDocument $parent): SalesDocument
    {
        $document = new SalesDocument($context->ownerAttributes() + $this->columns($header, $c, $parent) + ['kind' => $header['kind'], 'party_id' => $header['party_id']]);
        $document->setRelation('lines', collect($c->lines)->map(fn ($l) => new SalesDocumentLine($l->columns()))->values());
        $document->setRelation('parent', $parent);

        return $document;
    }
}
