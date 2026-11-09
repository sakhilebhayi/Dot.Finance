<?php

namespace App\Services\Sales;

use App\Exceptions\Controls\ControlsViolation;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\LedgerSetting;
use App\Models\Controls\ApprovalRequest;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesDocumentLine;
use App\Models\Shared\Party;
use App\Models\User;
use App\Services\Accounting\Chart;
use App\Services\Accounting\Exceptions\AccountingException;
use App\Services\Accounting\Exceptions\PeriodClosed;
use App\Services\Accounting\JournalPoster;
use App\Services\Controls\ApprovalService;
use App\Services\Sales\Support\SalesViolation;
use App\Services\Shared\Numbering;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Issuing: the moment a draft becomes a document. In one database transaction, under a lock on the draft, it
 *
 *   1. works every figure out again on the server (rate, tax, totals), so what is issued is what was previewed;
 *   2. refuses an invoice that is not a valid tax invoice when the business is VAT-registered (the checklist);
 *   3. refuses a credit note that credits more than was charged;
 *   4. asks for approval when an approval policy covers the amount, and stops there until it is given;
 *   5. takes the next gap-free number (a rollback hands the number back);
 *   6. posts Dr receivables / Cr income / Cr output VAT (credit notes the mirror image);
 *   7. freezes the document: from now on it can only be voided or credited.
 *
 * Issuing twice returns the same document and the same number. A credit note issued against an open invoice
 * is applied to it straight away, up to what the invoice still owes.
 */
final class DocumentIssuer
{
    public function __construct(
        private readonly DocumentComputer $computer,
        private readonly DocumentDrafts $drafts,
        private readonly SalesProfiles $profiles,
        private readonly SalesPosting $posting,
        private readonly Numbering $numbering,
        private readonly JournalPoster $poster,
        private readonly TaxInvoiceChecklist $checklist,
        private readonly CreditNotes $creditNotes,
        private readonly ApprovalService $approvals,
        private readonly ApplicationEngine $engine,
    ) {}

    /**
     * @param  array{acknowledge_credit_limit?: bool}  $options
     * @return array{document: SalesDocument, awaiting_approval: bool, replayed: bool, warnings: list<string>}
     *
     * @throws SalesViolation
     */
    public function issue(SalesDocument $document, User $by, array $options = []): array
    {
        $context = $document->ledgerContext();

        return LedgerContext::run($context, function () use ($document, $by, $options, $context) {
            try {
                return DB::transaction(fn () => $this->issueLocked($context, $document->id, $by, $options, viaApproval: false));
            } catch (PeriodClosed $e) {
                throw new SalesViolation($e->getMessage().' Issue the document with a date in an open period.', 'period_closed');
            } catch (AccountingException $e) {
                throw new SalesViolation('The books refused the posting: '.$e->getMessage(), 'posting');
            }
        });
    }

    /** Called by the approvals engine inside the decision's own transaction (App\Contracts\Approvable). */
    public function approvalDecided(SalesDocument $document, ApprovalRequest $request): void
    {
        $context = $document->ledgerContext();

        LedgerContext::run($context, function () use ($document, $request, $context) {
            $locked = SalesDocument::query()->forContext($context)->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($request->status !== ApprovalRequest::APPROVED) {
                if ($locked->status === 'awaiting_approval') {
                    $locked->forceFill(['status' => 'draft', 'approval_note' => mb_substr('Approval '.$request->status.($request->reason ? ': '.$request->reason : ''), 0, 255)])->save();
                }

                return;
            }

            if ($locked->isSealed()) {
                return;
            }

            $requester = User::find($request->requested_by) ?? throw new ControlsViolation('invalid', 'The person who asked for approval no longer exists, so the invoice cannot be issued on their behalf.');
            $locked->forceFill(['fx_rate_locked' => true])->save();

            try {
                $this->issueLocked($context, $locked->id, $requester, [], viaApproval: true);
            } catch (SalesViolation|PeriodClosed|AccountingException $e) {
                throw new ControlsViolation('invalid', 'It cannot be issued yet: '.$e->getMessage());
            }
        });
    }

    /** @return array{document: SalesDocument, awaiting_approval: bool, replayed: bool, warnings: list<string>} */
    private function issueLocked(LedgerContext $context, int $id, User $by, array $options, bool $viaApproval): array
    {
        $document = SalesDocument::query()->forContext($context)->whereKey($id)->lockForUpdate()->firstOrFail();

        if ($document->isSealed()) {
            return ['document' => $document, 'awaiting_approval' => false, 'replayed' => true, 'warnings' => []];
        }

        $warnings = [];
        $party = Party::query()->forContext($context)->withTrashed()->findOrFail($document->party_id);
        if (! $party->isCustomer() || $party->status !== 'active') {
            throw new SalesViolation('The customer is archived or no longer a customer. Restore them before issuing.', 'party');
        }

        $posts = $document->postsToBooks();
        $settings = LedgerSetting::query()->forContext($context)->first();
        if ($posts && ($settings === null || ! $settings->isEnabled())) {
            throw new SalesViolation('Accounting is not switched on for this ledger, so an invoice cannot be posted to the books. Finish the accounting set-up first.', 'accounting_not_ready');
        }

        if ($posts) {
            $this->assertChartReady($context, ['ar', 'sales'] + (($document->tax_cents ?? 0) > 0 || $document->vat_registered ? [2 => 'vat_output'] : []));
        }

        $issueDate = CarbonImmutable::parse($document->issue_date->toDateString());
        if ($posts && $issueDate->isFuture() && ! $issueDate->isToday()) {
            throw new SalesViolation('A tax invoice is dated when it is issued: the date cannot be in the future.', 'dates');
        }

        // 1. every figure again, on the server
        $parent = $document->parent_id !== null ? SalesDocument::query()->forContext($context)->whereKey($document->parent_id)->lockForUpdate()->first() : null;
        if ($document->parent_id !== null && ($parent === null || ! $parent->isSealed() || $parent->isVoid())) {
            throw new SalesViolation('The invoice this corrects is no longer valid (it was voided or removed).', 'parent');
        }

        $computed = $this->computer->compute($this->inputOf($document, $parent), $context);
        if ($posts && $computed->totalCents <= 0) {
            throw new SalesViolation('The total is zero, so there is nothing to post. Add a value to at least one line.', 'zero_total');
        }

        // 3. credit notes: never more than was charged
        if ($document->kind === 'credit_note') {
            $this->creditNotes->assertWithinLimits($parent, $computed, $document->id);
        }
        if (in_array($document->kind, ['credit_note', 'debit_note'], true) && trim((string) $document->reason) === '') {
            throw new SalesViolation('Say why the '.str_replace('_', ' ', $document->kind).' is being issued.', 'reason');
        }

        $this->drafts->persistComputed($document, $computed);
        $document->refresh();

        // 2. the tax invoice checklist
        $profile = $this->profiles->for($context);
        $check = $this->checklist->evaluate($document->setRelation('parent', $parent), $profile, $party);
        if ($check['applies'] && ! $check['valid']) {
            $failing = collect($check['items'])->filter(fn ($i) => $i['blocking'] && ! $i['ok'])->map(fn ($i) => $i['label'].': '.$i['detail'])->implode(' ');
            throw new SalesViolation('This is not a valid tax invoice yet. '.$failing, 'checklist', ['items' => $check['items']]);
        }
        if (! $check['applies'] && $posts) {
            foreach (['customer_name'] as $key) {
                $item = collect($check['items'])->firstWhere('key', $key);
                if ($item && ! $item['ok']) {
                    throw new SalesViolation($item['detail'], 'checklist');
                }
            }
        }

        // credit limit: a warning that has to be acknowledged
        if (in_array($document->kind, ['tax_invoice', 'debit_note'], true) && $party->credit_limit_cents) {
            $exposure = app(ReceivablesLedger::class)->customerBalanceBase($context, $party->id);
            if ($exposure + $computed->baseTotalCents > (int) $party->credit_limit_cents) {
                $message = $party->name.' would owe '.Money::format($exposure + $computed->baseTotalCents, $computed->baseCurrency).' with this invoice, over their credit limit of '.Money::format((int) $party->credit_limit_cents, $computed->baseCurrency).'.';
                if (empty($options['acknowledge_credit_limit']) && ! $viaApproval) {
                    throw new SalesViolation($message.' Tick "I know, issue it anyway" to go ahead.', 'credit_limit', ['message' => $message]);
                }
                $warnings[] = $message;
            }
        }

        // 4. approval
        if (in_array($document->kind, ['tax_invoice', 'debit_note'], true) && ! $viaApproval && ApprovalService::requiresApproval('invoice', (int) $document->base_total_cents, $document->base_currency, $context)) {
            if (! $this->approvals->isApproved($document)) {
                $this->approvals->request($by, $document);
                $document->forceFill(['status' => 'awaiting_approval', 'fx_rate_locked' => true])->save();

                return ['document' => $document->refresh(), 'awaiting_approval' => true, 'replayed' => false, 'warnings' => $warnings];
            }
        }
        if ($viaApproval && ! $this->approvals->isApproved($document)) {
            throw new SalesViolation('The amount changed since it was approved (the exchange rate or the tax rules moved). Cancel the request and ask again.', 'stale_amount');
        }

        // 5 to 7. number, post, freeze
        $series = SalesDocument::seriesFor($document->kind);
        $this->profiles->ensureSeries($context);
        $number = $this->numbering->next($series, $issueDate, $context);

        $document->forceFill([
            'number' => $number, 'number_series' => $series,
            'customer_snapshot' => $this->checklist->customerArray($party), 'supplier_snapshot' => $this->checklist->supplierArray($profile),
            'issued_at' => now(), 'issued_by' => $by->id,
        ]);

        $entryId = null;
        if ($posts) {
            $entryId = $this->poster->post($this->posting->request($document, $by->id))->id;
        }

        $document->forceFill(['status' => 'issued', 'sealed_at' => now(), 'gl_entry_id' => $entryId, 'version' => (int) $document->version + 1])->save();

        SalesTrail::event($document, 'issued', $by->id, ['number' => $number, 'total_cents' => (int) $document->total_cents, 'currency' => $document->currency]);
        SalesTrail::audit($document, 'issued', 'sales_document', $document->auditLabel(), [
            'total_cents' => ['after' => (int) $document->total_cents], 'base_total_cents' => ['after' => (int) $document->base_total_cents],
        ], $document->currency, $by->id);

        // a credit note settles the invoice it corrects, as far as that invoice still owes
        if ($document->kind === 'credit_note' && $parent->isReceivable()) {
            $owed = $this->engine->openBalance($parent);
            if ($owed > 0) {
                $this->engine->applyCredit($document, $parent, min($owed, (int) $document->total_cents), $by);
            }
        }

        return ['document' => $document->refresh(), 'awaiting_approval' => false, 'replayed' => false, 'warnings' => $warnings];
    }

    /**
     * The system accounts a posting needs must exist and be switched on. A personal chart switches receivables off,
     * so without this the person would meet a bare "account is inactive" from the posting layer.
     *
     * @param  list<string>  $keys
     *
     * @throws SalesViolation
     */
    public function assertChartReady(LedgerContext $context, array $keys): void
    {
        $ids = app(Chart::class)->systemIds($context);
        $off = [];
        foreach (array_unique($keys) as $key) {
            $account = isset($ids[$key]) ? GlAccount::query()->forContext($context)->find($ids[$key]) : null;
            if ($account === null || ! $account->is_active || ! $account->is_postable) {
                $off[] = $account ? $account->code.' '.$account->name : $key;
            }
        }

        if ($off !== []) {
            throw new SalesViolation('Invoicing needs these accounts switched on in the chart of accounts: '.implode(', ', $off).'. Switch them on under Chart of accounts (a personal chart has receivables off by default).', 'chart_not_ready');
        }
    }

    /**
     * The inputs of a stored draft, as DocumentComputer takes them.
     *
     * @return array<string, mixed>
     */
    private function inputOf(SalesDocument $document, ?SalesDocument $parent): array
    {
        return [
            'currency' => $document->currency, 'issue_date' => $document->issue_date->toDateString(), 'supply_date' => $document->supply_date?->toDateString(),
            'prices_include_tax' => (bool) $document->prices_include_tax, 'kind' => $document->kind, 'parent' => $parent,
            'fx_rate_e8' => $document->fx_rate_source === 'manual' ? $document->fx_rate_e8 : null,
            'fx_as_of' => $document->fx_rate_as_of?->toDateString(), 'fx_source' => $document->fx_rate_source,
            'fx_locked' => (bool) $document->fx_rate_locked,
            'fx_snapshot' => $document->fx_rate_e8 !== null ? ['rate_e8' => $document->fx_rate_e8, 'as_of' => $document->fx_rate_as_of?->toDateString(), 'source' => $document->fx_rate_source] : null,
            'lines' => $document->lines()->get()->map(fn (SalesDocumentLine $l) => [
                'description' => $l->description, 'unit' => $l->unit, 'quantity' => $l->quantity(), 'unit_cents' => (int) $l->unit_cents,
                'discount_bp' => (int) $l->discount_bp, 'discount_cents' => (int) $l->discount_cents, 'tax_code' => $l->tax_code,
                'income_account_id' => $l->income_account_id, 'parent_line_id' => $l->parent_line_id,
            ])->all(),
        ];
    }
}
