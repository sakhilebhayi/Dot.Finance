<?php

namespace App\Services\Purchases;

use App\Services\Shared\Tax\LineTax;
use App\Services\Shared\TaxRuleBook;
use Carbon\CarbonImmutable;

/**
 * Whether the VAT on a purchase can be claimed back as input tax (docs/R44_TAX_RESEARCH.md section 2.8). The rules, in the
 * order they are applied; the first that stops the claim names the reason:
 *
 *   1. no tax charged                       nothing to claim
 *   2. the code is a denied category        entertainment, motor-car purchase: never claimable (VAT Act s17(2))
 *   3. the ledger is not (known to be) a VAT vendor    nothing is claimable; the person must say whether the business is registered
 *   4. the supplier shows no VAT number     a non-vendor cannot charge VAT, so a claim on it is not safe
 *   5. no valid document is held            a full tax invoice (or customs bill of entry); an abridged invoice only at or below the
 *                                           full-invoice amount; no document only under the no-invoice amount
 *   6. outside the 5-year claim window      approximated from the invoice date, see below
 *
 * A blocked line is not an error: the whole gross goes to the expense account and the reason is kept on the line and listed on
 * the bill ("VAT review"), so the person can fix the document and the Tax module can show it. The thresholds and the window
 * come from the tax rule book (rows, with source and confidence), never from constants here.
 *
 * Window approximation: the research says 5 years after the end of the tax period in which the invoice should have been issued.
 * Tax periods are not known to Purchases, so the end of the invoice's calendar month is used as the earliest the period can end
 * and two further months are added (the longest category period); a claim after that is blocked. The label says it is approximate.
 */
final class InputVatRules
{
    public function __construct(private readonly TaxRuleBook $book) {}

    /**
     * @param  array{vat_registered: ?bool, document_type: ?string, supplier_vat_number: ?string, document_date: CarbonImmutable, posting_date: CarbonImmutable, document_gross_base_cents: int, code_policy: ?string, code_kind: string}  $facts
     */
    public function decide(LineTax $line, array $facts, string $jurisdiction): VatDecision
    {
        if ($line->taxCents <= 0) {
            return new VatDecision(0, 'no_tax', null);
        }

        $policy = $facts['code_policy'];

        if ($policy === 'denied_entertainment') {
            return new VatDecision(0, 'denied_entertainment', 'Entertainment: the VAT cannot be claimed back, so it is part of the cost.');
        }
        if ($policy === 'denied_motor_car') {
            return new VatDecision(0, 'denied_motor_car', 'Buying a passenger motor car: the VAT cannot be claimed back, so it is part of the cost.');
        }
        if ($policy === 'apportion') {
            return new VatDecision(0, 'apportion', 'Only part of this VAT may be claimable. It is kept in the cost until it is apportioned by hand.');
        }

        if ($facts['vat_registered'] !== true) {
            return new VatDecision(0, 'not_registered', $facts['vat_registered'] === null
                ? 'You have not said whether this business is registered for VAT, so no VAT is claimed. Set it under Purchases > Settings.'
                : 'This business is not registered for VAT, so no VAT can be claimed.');
        }

        $type = $facts['document_type'] ?? 'unknown';
        $gross = $facts['document_gross_base_cents'];
        $on = $facts['document_date'];

        if ($type !== 'none' && $type !== 'unknown' && ($facts['supplier_vat_number'] === null || trim($facts['supplier_vat_number']) === '')) {
            return new VatDecision(0, 'no_supplier_vat', 'The supplier has no VAT number on this document, so the VAT is not claimed.');
        }

        $fullThreshold = $this->book->find($this->jurisdictionOf($line), 'vat.invoice.full_invoice_threshold_cents', $on)?->cents() ?? 500_000;
        $noInvoiceBelow = $this->book->find($this->jurisdictionOf($line), 'vat.invoice.no_invoice_threshold_cents', $on)?->cents() ?? 5_000;

        $valid = match ($type) {
            'tax_invoice', 'customs', 'credit_note' => true,
            'abridged_invoice' => $gross <= $fullThreshold && $facts['code_kind'] !== 'zero',
            'none' => $gross < $noInvoiceBelow,
            default => false,
        };

        if (! $valid) {
            return new VatDecision(0, 'no_valid_invoice', match ($type) {
                'abridged_invoice' => 'An abridged invoice is only valid up to the full-invoice amount. A full tax invoice is needed, so the VAT is not claimed yet.',
                'none' => 'There is no tax invoice and the amount is above the level where one is not needed, so the VAT is not claimed.',
                default => 'No valid tax invoice is recorded for this purchase, so the VAT is not claimed. Get a full tax invoice and mark the document type.',
            });
        }

        $latest = $on->endOfMonth()->addMonths(2)->addYears($this->book->find($this->jurisdictionOf($line), 'vat.input_claim_window_years', $on)?->integer() ?? 5);
        if ($facts['posting_date']->greaterThan($latest)) {
            return new VatDecision(0, 'outside_window', 'This invoice is more than five years old, so the claim window has probably closed (approximate: confirm the tax period). The VAT is not claimed.');
        }

        return new VatDecision($line->taxCents, null, null);
    }

    private function jurisdictionOf(LineTax $line): string
    {
        return $line->jurisdiction;
    }
}
