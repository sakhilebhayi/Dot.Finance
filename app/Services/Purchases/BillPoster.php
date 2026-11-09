<?php

namespace App\Services\Purchases;

use App\Models\Purchases\Bill;
use App\Models\Purchases\BillLine;
use App\Models\Shared\Currency;
use App\Models\User;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Shared\Numbering;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posts a bill (or a supplier credit note) to the books, exactly once.
 *
 *   bill          Dr expense (or asset / prepayment) per line, gross of any VAT that cannot be claimed
 *                 Dr VAT input                      the claimable part only
 *                 Cr Accounts payable               the whole bill, tagged with the supplier
 *   credit note   the same with every side swapped
 *
 * A foreign-currency bill is posted in the ledger's base currency at the rate stored on the bill; every line also carries the
 * original currency, amount and rate. The payable is the sum of the debits, so the entry always balances to the cent however
 * the conversions round. The tax and its claimable part are recomputed here from the stored lines, so a VAT setting changed
 * since the draft was saved is honoured, but a changed TOTAL is refused (the person approved an amount).
 */
final class BillPoster
{
    public function __construct(
        private readonly JournalPoster $poster,
        private readonly PurchaseLedger $ledger,
        private readonly BillCalculator $calculator,
        private readonly Numbering $numbering,
        private readonly BillStatus $status,
    ) {}

    public function post(Bill $bill, ?User $by = null): Bill
    {
        $context = $bill->ledgerContext();

        return LedgerContext::run($context, fn () => DB::transaction(function () use ($bill, $context, $by) {
            $locked = Bill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isPosted()) {
                return $locked; // idempotent: posting twice posts once
            }
            if (! in_array($locked->status, ['draft', 'approved', 'awaiting_approval'], true)) {
                throw new PurchasesException('This '.strtolower($locked->kindLabel()).' is '.str_replace('_', ' ', $locked->status).' and cannot be posted.', 'bad_state');
            }

            $settings = $this->ledger->requireAccounting($context);
            $lines = $locked->lines()->get();

            if ($lines->isEmpty() || $locked->total_cents <= 0) {
                throw new PurchasesException('There is nothing to post: add at least one line with an amount.', 'empty');
            }

            $rate = $this->ledger->rateFor($context, $locked->currency, CarbonImmutable::parse($locked->bill_date->toDateString()), $locked->fx_rate_e8);
            $locked->fx_rate_e8 = $rate;

            $this->refreshTax($locked, $lines, $context);

            if ($locked->isCreditNote() && $locked->credit_for_bill_id !== null) {
                $this->assertCreditFits($locked);
            }

            $request = $this->request($locked, $lines, $settings->base_currency, $rate, $by);

            $issueDate = CarbonImmutable::now();
            $type = $locked->isCreditNote() ? 'supplier_credit_note' : 'bill';
            if ($this->numbering->peek($type, $issueDate, $context) === null) {
                $this->numbering->configure($type, $locked->isCreditNote() ? 'SCN' : 'BILL', 5, true, $context);
            }
            $number = $this->numbering->next($type, $issueDate, $context);

            $request->memo = ($locked->isCreditNote() ? 'Supplier credit note ' : 'Supplier bill ').$number.' from '.mb_substr((string) $locked->party?->name, 0, 120);
            $entry = $this->poster->post($request);

            $locked->forceFill([
                'number' => $number,
                'status' => $locked->isCreditNote() && $locked->credit_for_bill_id !== null ? 'paid' : 'open',
                'base_total_cents' => $request->totalCredits() === 0 ? 0 : ($locked->isCreditNote() ? $request->totalDebits() : $request->totalCredits()),
                'posted_entry_id' => $entry->id,
                'posted_at' => now(),
                'posted_by' => $by?->id,
                'post_error' => null,
                'version' => $locked->version + 1,
            ])->save();

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
     * The balanced entry for a bill, in base currency. Public so the posting can be inspected and tested without writing it.
     *
     * @param  Collection<int, BillLine>  $lines
     */
    public function request(Bill $bill, $lines, string $baseCurrency, ?int $rateE8, ?User $by = null): PostingRequest
    {
        $context = $bill->ledgerContext();
        $foreign = strtoupper($bill->currency) !== strtoupper($baseCurrency);
        $credit = $bill->isCreditNote();
        $partyId = $bill->party_id;

        $out = [];
        $vat = []; // tax_code_id => [claim in document currency, description]

        $side = fn (int $cents) => $credit ? ['debitCents' => 0, 'creditCents' => $cents] : ['debitCents' => $cents, 'creditCents' => 0];
        $contra = fn (int $cents) => $credit ? ['debitCents' => $cents, 'creditCents' => 0] : ['debitCents' => 0, 'creditCents' => $cents];

        $totalBase = 0;

        foreach ($lines as $line) {
            $cost = $line->net_cents + ($line->tax_cents - $line->claimable_tax_cents);
            if ($cost > 0) {
                $base = $this->ledger->toBase($context, $cost, $bill->currency, $rateE8);
                if ($base > 0) {
                    $out[] = new PostingLine($line->gl_account_id, ...$side($base), txnCurrency: $foreign ? $bill->currency : null, txnAmountCents: $foreign ? $cost : null, fxRateE8: $foreign ? $rateE8 : null, partyId: $partyId, dimension: $line->dimension, taxCodeId: $line->tax_code_id, description: mb_substr($line->description, 0, 255));
                    $totalBase += $base;
                }
            }

            if ($line->claimable_tax_cents > 0) {
                $vat[$line->tax_code_id ?? 0] = ($vat[$line->tax_code_id ?? 0] ?? 0) + $line->claimable_tax_cents;
            }
        }

        foreach ($vat as $taxCodeId => $claim) {
            $base = $this->ledger->toBase($context, $claim, $bill->currency, $rateE8);
            if ($base > 0) {
                $out[] = new PostingLine('vat_input', ...$side($base), txnCurrency: $foreign ? $bill->currency : null, txnAmountCents: $foreign ? $claim : null, fxRateE8: $foreign ? $rateE8 : null, partyId: $partyId, taxCodeId: $taxCodeId ?: null, description: 'Input VAT');
                $totalBase += $base;
            }
        }

        $out[] = new PostingLine('ap', ...$contra($totalBase), txnCurrency: $foreign ? $bill->currency : null, txnAmountCents: $foreign ? $bill->total_cents : null, fxRateE8: $foreign ? $rateE8 : null, partyId: $partyId, description: $bill->supplier_reference ? 'Supplier invoice '.$bill->supplier_reference : null);

        return new PostingRequest(CarbonImmutable::parse($bill->bill_date->toDateString()), 'bill', $bill->id, 1, 'Supplier bill', $out, $by?->id);
    }

    /** Recompute tax and the claimable part from the stored lines; refuse a changed total. */
    private function refreshTax(Bill $bill, $lines, LedgerContext $context): void
    {
        $ratio = null;
        if ($bill->isCreditNote() && $bill->credit_for_bill_id !== null) {
            $original = Bill::query()->find($bill->credit_for_bill_id);
            $ratio = $original !== null && $original->tax_cents > 0 ? [$original->claimable_tax_cents, $original->tax_cents] : [0, 1];
        }

        $computed = $this->calculator->compute($context, $lines->map(fn (BillLine $l) => [
            'description' => $l->description, 'gl_account_id' => $l->gl_account_id, 'quantity' => $l->quantity(), 'unit_price_cents' => $l->unit_price_cents,
            'discount_bp' => $l->discount_bp, 'tax_code' => $l->tax_code, 'dimension' => $l->dimension,
        ])->all(), [
            'date' => CarbonImmutable::parse($bill->bill_date->toDateString()), 'currency' => $bill->currency, 'fx_rate_e8' => $bill->fx_rate_e8,
            'tax_inclusive' => $bill->tax_inclusive, 'document_type' => $bill->document_type, 'supplier_vat_number' => $bill->supplier_vat_number,
            'claim_ratio' => $ratio,
        ]);

        if ($computed->totalCents !== $bill->total_cents) {
            throw new PurchasesException('The tax rules now give a different total ('.number_format($computed->totalCents / 100, 2).') from the amount that was approved ('.number_format($bill->total_cents / 100, 2).'). Open the bill, save it again and ask for approval again.', 'total_changed');
        }

        foreach ($lines as $i => $line) {
            $row = $computed->lines[$i];
            $line->forceFill(['claimable_tax_cents' => $row['claimable_tax_cents'], 'claim_reason' => $row['claim_reason']])->save();
        }

        $bill->forceFill(['claimable_tax_cents' => $computed->claimableTaxCents, 'vat_review' => $computed->vatReview, 'rule_versions' => $computed->ruleVersions, 'tax_confidence' => $computed->confidence])->save();
    }

    private function assertCreditFits(Bill $credit): void
    {
        $original = Bill::query()->whereKey($credit->credit_for_bill_id)->lockForUpdate()->first();

        if ($original === null || $original->isCreditNote() || ! $original->isPosted() || $original->party_id !== $credit->party_id || strtoupper($original->currency) !== strtoupper($credit->currency)) {
            throw new PurchasesException('A credit note can only credit a posted bill from the same supplier in the same currency.', 'bad_credit_target');
        }

        $open = app(BillStatus::class)->openCents($original);
        if ($credit->total_cents > $open) {
            throw new PurchasesException('This credit note ('.number_format($credit->total_cents / 100, 2).') is more than is still owed on '.$original->displayNumber().' ('.number_format($open / 100, 2).'). Credit what is owed, and keep the rest as a supplier credit.', 'credit_exceeds');
        }
    }
}
