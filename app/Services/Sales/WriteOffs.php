<?php

namespace App\Services\Sales;

use App\Enums\Capability;
use App\Exceptions\Controls\ControlsViolation;
use App\Models\Controls\ApprovalRequest;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesWriteOff;
use App\Models\User;
use App\Services\Accounting\Exceptions\AccountingException;
use App\Services\Accounting\Exceptions\PeriodClosed;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Controls\ApprovalService;
use App\Services\Sales\Support\SalesViolation;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only way an invoice becomes "paid" without money: an authorised, reasoned write-off of what the customer
 * will not pay. Posts Dr bad debts / Cr receivables. It needs the capability to approve invoices; when an approval
 * policy covers the amount it waits for that approval, and a second person decides.
 *
 * Whether the VAT on a written-off debt can be reclaimed (VAT Act section 22) depends on conditions this system
 * cannot see; it is not claimed here. The whole amount goes to bad debts and the accountant decides on relief.
 */
final class WriteOffs
{
    public function __construct(
        private readonly ApplicationEngine $engine,
        private readonly JournalPoster $poster,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @return array{write_off: SalesWriteOff, applied: bool}
     *
     * @throws SalesViolation
     */
    public function request(SalesDocument $document, int $amountCents, string $reason, User $by): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new SalesViolation('A write-off needs a reason.', 'reason');
        }
        $context = $document->ledgerContext();

        return LedgerContext::run($context, function () use ($document, $amountCents, $reason, $by, $context) {
            try {
                return DB::transaction(function () use ($document, $amountCents, $reason, $by, $context) {
                    $locked = SalesDocument::query()->forContext($context)->whereKey($document->id)->lockForUpdate()->firstOrFail();
                    if (! $locked->isReceivable() || ! $locked->isSealed() || $locked->isVoid()) {
                        throw new SalesViolation('Only an issued invoice or debit note can be written off.', 'invalid');
                    }

                    $pool = $this->engine->invoicePool($locked);
                    $base = $this->engine->share($pool, $amountCents);

                    $writeOff = SalesWriteOff::query()->create($context->ownerAttributes() + [
                        'document_id' => $locked->id, 'amount_cents' => $amountCents, 'base_cents' => $base, 'reason' => mb_substr($reason, 0, 255), 'status' => 'pending', 'requested_by' => $by->id,
                    ]);
                    $writeOff->setRelation('document', $locked);

                    if (ApprovalService::requiresApproval('invoice', $base, $locked->base_currency, $context)) {
                        $this->approvals->request($by, $writeOff);
                        SalesTrail::event($locked, 'write_off_requested', $by->id, ['amount_cents' => $amountCents]);

                        return ['write_off' => $writeOff, 'applied' => false];
                    }

                    if (! (LedgerAccess::for($by, $context)?->can(Capability::ApproveInvoice) ?? false)) {
                        throw new SalesViolation('Writing off a debt needs someone who can approve invoices. Ask them, or set an approval rule so it can be requested.', 'missing_capability');
                    }

                    $this->apply($writeOff, $locked, $by);

                    return ['write_off' => $writeOff->refresh(), 'applied' => true];
                });
            } catch (PeriodClosed $e) {
                throw new SalesViolation($e->getMessage().' Write it off on a date in an open period.', 'period_closed');
            } catch (AccountingException $e) {
                throw new SalesViolation('The books refused the posting: '.$e->getMessage(), 'posting');
            }
        });
    }

    /** Called by the approvals engine inside the decision's transaction. */
    public function approvalDecided(SalesWriteOff $writeOff, ApprovalRequest $request): void
    {
        $context = $writeOff->ledgerContext();

        LedgerContext::run($context, function () use ($writeOff, $request, $context) {
            $locked = SalesWriteOff::query()->forContext($context)->whereKey($writeOff->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                return;
            }

            if ($request->status !== ApprovalRequest::APPROVED) {
                $locked->forceFill(['status' => $request->status === ApprovalRequest::REJECTED ? 'rejected' : 'cancelled', 'decided_by' => $request->decided_by])->save();

                return;
            }

            $document = SalesDocument::query()->forContext($context)->whereKey($locked->document_id)->lockForUpdate()->firstOrFail();
            $actor = User::find($request->decided_by);

            try {
                $this->apply($locked, $document, $actor);
            } catch (SalesViolation|PeriodClosed|AccountingException $e) {
                throw new ControlsViolation('invalid', 'It cannot be written off yet: '.$e->getMessage());
            }
        });
    }

    private function apply(SalesWriteOff $writeOff, SalesDocument $document, ?User $by): void
    {
        $pool = $this->engine->invoicePool($document);
        if ($writeOff->amount_cents > $pool['foreign']) {
            throw new SalesViolation('The invoice owes less than that now.', 'over_allocation');
        }
        $base = $this->engine->share($pool, (int) $writeOff->amount_cents);
        $foreign = $document->isForeign();

        $entry = $this->poster->post(new PostingRequest(
            CarbonImmutable::today(), 'adjustment', 'write-off-'.$writeOff->id, 1, 'Write-off of '.$document->reference().': '.mb_substr($writeOff->reason, 0, 200),
            [
                new PostingLine('bad_debts', $base, 0, $foreign ? $document->currency : null, $foreign ? (int) $writeOff->amount_cents : null, $foreign ? (int) $document->fx_rate_e8 : null, description: 'Bad debt: '.$document->reference()),
                new PostingLine('ar', 0, $base, $foreign ? $document->currency : null, $foreign ? (int) $writeOff->amount_cents : null, $foreign ? (int) $document->fx_rate_e8 : null, partyId: (int) $document->party_id, description: 'Written off: '.$document->reference()),
            ],
            $by?->id,
        ));

        $document->forceFill([
            'written_off_cents' => (int) $document->written_off_cents + (int) $writeOff->amount_cents,
            'written_off_base_cents' => (int) $document->written_off_base_cents + $base,
        ])->save();

        $writeOff->forceFill(['status' => 'applied', 'base_cents' => $base, 'gl_entry_id' => $entry->id, 'applied_at' => now(), 'decided_by' => $by?->id])->save();
        $this->engine->refreshStatus($document);

        SalesTrail::event($document, 'written_off', $by?->id, ['amount_cents' => (int) $writeOff->amount_cents]);
        SalesTrail::audit($document, 'written_off', 'sales_document', $document->auditLabel(), ['written_off_cents' => ['after' => (int) $document->written_off_cents]], $document->currency, $by?->id);
    }
}
