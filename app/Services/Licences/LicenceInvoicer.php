<?php

namespace App\Services\Licences;

use App\Models\Accounting\LedgerSetting;
use App\Models\Licences\LicenceBatch;
use App\Models\Licences\LicenceCreditNote;
use App\Models\Licences\LicencePayment;
use App\Models\Sales\SalesDocument;
use App\Models\Shared\Party;
use App\Models\Team;
use App\Models\User;
use App\Services\Sales\DocumentDrafts;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\DocumentLifecycle;
use App\Services\Sales\ReceiptService;
use App\Services\Sales\SalesProfiles;
use App\Services\Sales\Support\SalesViolation;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns a verified licence payment into a South African tax invoice in the selling entity's books, using the existing Sales services
 * (drafts, issuer, receipts, credit notes) and nothing parallel. VAT is whatever that ledger's business profile says: a registered
 * seller gets VAT at the standard code and the amount Paystack charged is treated as VAT-inclusive (so the invoice total equals the
 * money taken and no extra is demanded from the buyer); a seller that is not registered gets an invoice with no VAT.
 * A refund becomes a credit note against that invoice. Everything is in the seller's ledger and ZAR minor units.
 */
final class LicenceInvoicer
{
    public function __construct(
        private readonly DocumentDrafts $drafts,
        private readonly DocumentIssuer $issuer,
        private readonly DocumentLifecycle $lifecycle,
        private readonly ReceiptService $receipts,
        private readonly SalesProfiles $profiles,
    ) {}

    public function sellerConfigured(): bool
    {
        return filled(config('licences.seller.team_uuid'));
    }

    /** Idempotent: a payment that already has its invoice returns it. Failures are recorded on the payment, then thrown away (retry: licences:invoices). */
    public function invoice(LicencePayment $payment): string
    {
        $payment = LicencePayment::query()->findOrFail($payment->id);

        if ($payment->sales_document_id !== null) {
            return 'issued';
        }

        if ($payment->licence_batch_id === null || $payment->status === 'unmatched') {
            return $this->mark($payment, 'skipped', 'The payment is not tied to a batch.');
        }

        if (! $this->sellerConfigured()) {
            return $this->mark($payment, 'skipped', 'LICENCE_SELLER_TEAM_UUID is not set.');
        }

        try {
            [$context, $user] = $this->seller();
            $batch = LicenceBatch::query()->findOrFail($payment->licence_batch_id);

            $documentId = LedgerContext::run($context, fn () => DB::transaction(function () use ($context, $user, $payment, $batch) {
                $registered = (bool) $this->profiles->for($context)->vat_registered;
                $party = $this->party($context, $batch);
                $label = ucfirst($batch->interval).' licences x'.$batch->quantity.', '.$payment->period_start?->toDateString().' to '.$payment->period_end?->toDateString();

                $draft = $this->drafts->create($context, $user, [
                    'kind' => 'tax_invoice', 'party_id' => $party->id, 'currency' => $payment->currency, 'issue_date' => $payment->paid_at->toDateString(),
                    'supply_date' => $payment->paid_at->toDateString(), 'due_date' => $payment->paid_at->toDateString(), 'prices_include_tax' => $registered,
                    'reference' => $payment->paystack_reference, 'notes' => 'Paid by card through Paystack.', 'client_token' => 'lic-pay-'.$payment->id,
                ], [[
                    'description' => 'InfoDot user licences: '.$label, 'quantity' => '1', 'unit_cents' => $payment->amount_minor, 'tax_code' => $registered ? 'ZA_VAT_STD' : null,
                ]]);

                $document = $this->issuer->issue($draft, $user)['document'];

                if (filled(config('licences.seller.cash_account_id'))) {
                    $this->receipts->receive($context, $user, [
                        'party_id' => $party->id, 'cash_account_id' => (int) config('licences.seller.cash_account_id'), 'amount_cents' => $payment->amount_minor,
                        'paid_on' => $payment->paid_at->toDateString(), 'method' => 'card', 'reference' => $payment->paystack_reference,
                        'idempotency_key' => 'lic-pay-'.$payment->id, 'allocations' => [['document_id' => $document->id, 'amount_cents' => $payment->amount_minor]],
                    ]);
                }

                return $document->id;
            }));

            $payment->forceFill(['sales_document_id' => $documentId, 'invoice_status' => 'issued', 'invoice_error' => null])->save();

            return 'issued';
        } catch (SalesViolation $e) {
            return $this->mark($payment, 'failed', $e->getMessage());
        } catch (Throwable $e) {
            $this->mark($payment, 'failed', class_basename($e).': '.$e->getMessage());

            throw $e; // an unexpected fault: let the queue retry it
        }
    }

    /** A credit note for a refund of $amountMinor, against the payment's invoice. Idempotent per (payment, refund id). */
    public function creditNote(LicencePayment $payment, int $amountMinor, string $refundId): string
    {
        $note = LicenceCreditNote::query()->firstOrCreate(['licence_payment_id' => $payment->id, 'refund_id' => $refundId], ['amount_minor' => $amountMinor]);

        if ($note->sales_document_id !== null) {
            return 'issued';
        }

        $payment = LicencePayment::query()->findOrFail($payment->id);

        if ($payment->sales_document_id === null) {
            $note->forceFill(['status' => 'pending', 'error' => 'The invoice has not been issued yet.'])->save();

            return 'pending';
        }

        try {
            [$context, $user] = $this->seller();

            $id = LedgerContext::run($context, fn () => DB::transaction(function () use ($context, $user, $payment, $amountMinor, $refundId) {
                $invoice = SalesDocument::query()->forContext($context)->findOrFail($payment->sales_document_id);
                $reason = 'Licence refund (Paystack '.$refundId.')';

                if ($amountMinor >= $payment->amount_minor) {
                    $draft = $this->lifecycle->draftCreditNote($invoice, $user, $reason);
                } else {
                    $first = $invoice->lines()->orderBy('id')->firstOrFail();
                    $draft = $this->drafts->create($context, $user, [
                        'kind' => 'credit_note', 'party_id' => $invoice->party_id, 'parent_id' => $invoice->id, 'currency' => $invoice->currency, 'issue_date' => now()->toDateString(),
                        'prices_include_tax' => $invoice->prices_include_tax, 'reason' => $reason, 'reference' => $invoice->reference, 'client_token' => 'lic-cn-'.$payment->id.'-'.$refundId,
                    ], [['description' => 'Partial refund of '.$invoice->number, 'quantity' => '1', 'unit_cents' => $amountMinor, 'parent_line_id' => $first->id]]);
                }

                return $this->issuer->issue($draft, $user)['document']->id;
            }));

            $note->forceFill(['sales_document_id' => $id, 'status' => 'issued', 'error' => null])->save();

            return 'issued';
        } catch (SalesViolation $e) {
            $note->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 255)])->save();

            return 'failed';
        }
    }

    /** @return array{0: LedgerContext, 1: User} */
    private function seller(): array
    {
        $team = Team::query()->where('infodot_team_uuid', strtolower((string) config('licences.seller.team_uuid')))->first();

        if ($team === null || ! $team->isCompany()) {
            throw new SalesViolation('The selling entity is not a company ledger in Finance.', 'seller');
        }

        $context = LedgerContext::company($team);

        if (LedgerSetting::query()->forContext($context)->first() === null) {
            throw new SalesViolation('Accounting is not set up for the selling entity.', 'accounting_not_ready');
        }

        $email = (string) config('licences.seller.user_email');
        $user = $email !== '' ? User::query()->where('email', $email)->first() : $team->owner;

        return [$context, $user ?? throw new SalesViolation('No user to issue invoices as. Set LICENCE_SELLER_USER_EMAIL.', 'seller')];
    }

    /** One customer record per InfoDot company, in the seller's ledger, found by external_ref. */
    private function party(LedgerContext $context, LicenceBatch $batch): Party
    {
        $ref = 'infodot-team:'.$batch->team_uuid;
        $party = Party::query()->forContext($context)->where('external_ref', $ref)->first();

        if ($party !== null) {
            return $party;
        }

        return Party::create($context->ownerAttributes() + [
            'type' => 'customer', 'name' => $batch->customer_name, 'email' => $batch->customer_email, 'external_ref' => $ref, 'status' => 'active', 'currency' => 'ZAR',
        ]);
    }

    private function mark(LicencePayment $payment, string $status, string $error): string
    {
        $payment->forceFill(['invoice_status' => $status, 'invoice_error' => mb_substr($error, 0, 255)])->save();

        return $status;
    }
}
