<?php

namespace App\Services\Purchases;

use App\Models\Purchases\BillLine;
use App\Models\Purchases\PurchaseSetting;
use App\Services\Shared\Tax\LineTax;
use App\Services\Shared\Tax\TaxLineInput;
use App\Services\Shared\TaxCalculator;
use App\Services\Shared\TaxRuleBook;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use App\Support\Shared\Exceptions\NoValidTaxRule;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Works out a purchase document on the server: line amounts and tax through TaxCalculator (rules as data, integer cents), then
 * what part of the tax is claimable through InputVatRules. The browser's totals are never read; only quantities, prices, codes
 * and accounts are.
 */
final class BillCalculator
{
    public function __construct(
        private readonly TaxCalculator $calculator,
        private readonly TaxRuleBook $book,
        private readonly InputVatRules $vat,
        private readonly PurchaseLedger $ledger,
    ) {}

    /**
     * @param  list<array{description?: ?string, gl_account_id: int|string, quantity?: string|int|null, unit_price_cents: int, discount_bp?: ?int, tax_code: string, dimension?: ?string}>  $lines
     * @param  array{date: CarbonImmutable, currency: string, fx_rate_e8?: ?int, tax_inclusive: bool, document_type: ?string, supplier_vat_number?: ?string, posting_date?: ?CarbonImmutable, claim_ratio?: ?array{0: int, 1: int}}  $doc
     *
     * @throws PurchasesException
     */
    public function compute(LedgerContext $context, array $lines, array $doc): Computation
    {
        if ($lines === []) {
            throw new PurchasesException('Add at least one line.', 'no_lines');
        }

        $jurisdiction = (string) config('purchases.jurisdiction');
        $date = $doc['date'];
        $inputs = [];
        $codes = [];
        $normal = [];

        foreach (array_values($lines) as $i => $line) {
            $n = $i + 1;
            $label = "Line {$n}";

            $account = (int) ($line['gl_account_id'] ?? 0);
            if (! $this->ledger->isChargeable($context, $account)) {
                throw new PurchasesException("{$label}: choose an expense account (or a prepayment, stock or equipment account) from this ledger's chart.", 'bad_account');
            }

            $unit = $line['unit_price_cents'] ?? null;
            if (! is_int($unit) || $unit < 0) {
                throw new PurchasesException("{$label}: the price must be an amount of zero or more.", 'bad_price');
            }

            try {
                $quantityE4 = BillLine::parseQuantity((string) ($line['quantity'] ?? '1'));
            } catch (InvalidArgumentException $e) {
                throw new PurchasesException("{$label}: ".$e->getMessage(), 'bad_quantity');
            }

            $discount = (int) ($line['discount_bp'] ?? 0);
            if ($discount < 0 || $discount > 10_000) {
                throw new PurchasesException("{$label}: a discount is between 0% and 100%.", 'bad_discount');
            }

            $dimension = isset($line['dimension']) && trim((string) $line['dimension']) !== '' ? trim((string) $line['dimension']) : null;
            if ($dimension !== null && preg_match('/^(project|department):[A-Za-z0-9_-]{1,40}$/', $dimension) !== 1) {
                throw new PurchasesException("{$label}: a project or department looks like project:12 or department:sales.", 'bad_dimension');
            }

            $description = trim((string) ($line['description'] ?? ''));
            if ($description === '') {
                throw new PurchasesException("{$label}: say what was bought.", 'no_description');
            }

            try {
                $code = $this->book->taxCode($jurisdiction, (string) $line['tax_code'], $date);
            } catch (NoValidTaxRule) {
                throw new PurchasesException("{$label}: the tax code \"{$line['tax_code']}\" is not valid on {$date->toDateString()}.", 'bad_tax_code');
            }
            if (! $code->is_active || ! in_array($code->applies_to, ['purchases', 'both'], true)) {
                throw new PurchasesException("{$label}: the tax code \"{$code->code}\" is not for purchases.", 'bad_tax_code');
            }

            $codes[$i] = $code;
            $normal[$i] = ['description' => mb_substr($description, 0, 255), 'gl_account_id' => $account, 'quantity_e4' => $quantityE4, 'unit_price_cents' => $unit, 'discount_bp' => $discount, 'dimension' => $dimension];
            $inputs[$i] = new TaxLineInput(unitCents: $unit, quantity: BillLine::formatQuantity($quantityE4), taxCode: $code->code, discountBp: $discount, inclusive: $doc['tax_inclusive'], reference: (string) $n);
        }

        try {
            $tax = $this->calculator->forDocument($inputs, $jurisdiction, $date, $doc['tax_inclusive']);
        } catch (NoValidTaxRule $e) {
            throw new PurchasesException('The tax rules do not cover '.$date->toDateString().': '.$e->getMessage(), 'no_tax_rule');
        } catch (InvalidArgumentException|\OverflowException) {
            // a price times a quantity beyond the supported range is refused in words, never a server error (QA security D-1411)
            throw new PurchasesException('An amount on this document is larger than the largest this system works with ('.Money::format(Money::MAX_CENTS).').', 'amount_too_large');
        }

        if (abs($tax->grossCents) > Money::MAX_CENTS || abs($tax->netCents) > Money::MAX_CENTS) {
            throw new PurchasesException('The document total is larger than the largest amount this system works with ('.Money::format(Money::MAX_CENTS).').', 'amount_too_large');
        }

        $setting = PurchaseSetting::forLedger($context);
        try {
            $grossBase = $this->ledger->toBase($context, $tax->grossCents, $doc['currency'], $doc['fx_rate_e8'] ?? null);
        } catch (\OverflowException) {
            throw new PurchasesException('That amount is too large to convert at this exchange rate.', 'amount_too_large');
        }
        $posting = $doc['posting_date'] ?? now()->toImmutable();

        $out = [];
        $review = [];
        $claimable = 0;

        /** @var LineTax $lt */
        foreach ($tax->lines as $i => $lt) {
            $decision = $this->vat->decide($lt, [
                'vat_registered' => $setting->isVatRegistered(),
                'document_type' => $doc['document_type'] ?? null,
                'supplier_vat_number' => $doc['supplier_vat_number'] ?? null,
                'document_date' => $date,
                'posting_date' => $posting,
                'document_gross_base_cents' => $grossBase,
                'code_policy' => $codes[$i]->deductible_policy,
                'code_kind' => $codes[$i]->kind,
            ], $jurisdiction);

            $claim = $decision->claimableCents;

            $out[] = $normal[$i] + [
                'line_no' => $i + 1,
                'tax_code' => $codes[$i]->code,
                'tax_code_id' => $codes[$i]->id,
                'tax_rate_bp' => $lt->rateBp,
                'net_cents' => $lt->netCents,
                'tax_cents' => $lt->taxCents,
                'gross_cents' => $lt->grossCents,
                'claimable_tax_cents' => $claim,
                'claim_reason' => $decision->isBlocked() ? $decision->reason : null,
            ];

            if ($decision->isBlocked()) {
                $review[] = ['line_no' => $i + 1, 'reason' => (string) $decision->reason, 'message' => (string) $decision->message, 'tax_cents' => $lt->taxCents];
            }
            $claimable += $claim;
        }

        // A credit note that credits a bill takes back VAT in the same proportion the bill claimed it (a note must not reverse
        // input tax that was never claimed). The ratio is the bill's claimable/total tax, applied line by line.
        if (($doc['claim_ratio'] ?? null) !== null) {
            [$num, $den] = $doc['claim_ratio'];
            $claimable = 0;
            foreach ($out as &$line) {
                $line['claimable_tax_cents'] = $den > 0 ? Money::mulDivRound($line['tax_cents'], $num, $den) : 0;
                $line['claim_reason'] = $line['claimable_tax_cents'] < $line['tax_cents'] ? 'follows_bill' : null;
                $claimable += $line['claimable_tax_cents'];
            }
            unset($line);
            $review = [];
        }

        return new Computation($out, $tax->netCents, $tax->taxCents, $tax->grossCents, $claimable, $review, $tax->ruleVersions, $tax->roundingLevel, $tax->confidence);
    }
}
