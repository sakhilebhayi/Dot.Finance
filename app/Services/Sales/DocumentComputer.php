<?php

namespace App\Services\Sales;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\LedgerSetting;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesDocumentLine;
use App\Models\Shared\Currency;
use App\Services\Accounting\Chart;
use App\Services\Sales\Support\ComputedDocument;
use App\Services\Sales\Support\ComputedLine;
use App\Services\Sales\Support\PostingGroups;
use App\Services\Sales\Support\SalesViolation;
use App\Services\Sales\Support\VatStatus;
use App\Services\Shared\FxService;
use App\Services\Shared\Tax\TaxLineInput;
use App\Services\Shared\Tax\TaxMath;
use App\Services\Shared\TaxCalculator;
use App\Services\Shared\TaxRuleBook;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\NoFxRate;
use App\Support\Shared\Exceptions\NoValidTaxRule;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The only place a document's lines, tax and totals are worked out. The browser sends what a person typed
 * (descriptions, quantities, unit prices, discounts, a tax code); this class returns every figure, in integers.
 * It is used by the editor's live preview, by saving a draft and again when the document is issued, so what is
 * previewed, saved and posted are the same numbers.
 *
 * Tax comes from TaxCalculator (rates and rounding policy are dated rules, and the versions used are returned).
 * A business that is not VAT-registered charges no tax and the lines carry no tax code.
 */
final class DocumentComputer
{
    public function __construct(
        private readonly TaxCalculator $tax,
        private readonly TaxRuleBook $rules,
        private readonly FxService $fx,
        private readonly Chart $chart,
        private readonly VatStatus $vat,
    ) {}

    /**
     * @param  array{currency?: string, issue_date: string, supply_date?: ?string, prices_include_tax?: bool, lines: list<array<string, mixed>>, fx_rate_e8?: ?int, fx_locked?: bool, fx_snapshot?: ?array<string, mixed>, parent?: ?SalesDocument, kind?: string}  $input
     *
     * @throws SalesViolation
     */
    public function compute(array $input, ?LedgerContext $context = null): ComputedDocument
    {
        $context ??= LedgerContext::current() ?? throw new SalesViolation('No ledger is active.');
        $settings = LedgerSetting::query()->forContext($context)->first()
            ?? throw new SalesViolation('Set up accounting for this ledger first: invoices are posted to the books, and the books need a base currency and a chart of accounts.', 'accounting_not_ready');

        $base = $settings->base_currency;
        /** @var ?SalesDocument $parent */
        $parent = $input['parent'] ?? null;
        $currency = strtoupper($parent?->currency ?? $input['currency'] ?? $base);
        $issueDate = CarbonImmutable::parse(substr((string) $input['issue_date'], 0, 10));
        $inclusive = (bool) ($parent?->prices_include_tax ?? $input['prices_include_tax'] ?? false);
        $registered = $parent !== null ? (bool) $parent->vat_registered : $this->vat->isRegistered($context);
        $jurisdiction = $parent?->tax_jurisdiction ?? $this->vat->jurisdiction($context);
        // a credit or debit note takes its tax at the rate of the supply it corrects
        $taxPoint = $parent !== null
            ? CarbonImmutable::parse(($parent->supply_date ?? $parent->issue_date)->toDateString())
            : CarbonImmutable::parse(substr((string) ($input['supply_date'] ?? $input['issue_date']), 0, 10));

        $rows = array_values($input['lines'] ?? []);
        if ($rows === []) {
            throw new SalesViolation('Add at least one line.', 'no_lines');
        }
        if (count($rows) > 200) {
            throw new SalesViolation('A document can have up to 200 lines.', 'too_many_lines');
        }

        [$rateE8, $fxAsOf, $fxSource, $fxManual] = $this->rate($currency, $base, $issueDate, $input, $parent);

        $defaultIncome = $this->chart->systemIds($context)['sales'] ?? null;
        $validIncome = GlAccount::query()->forContext($context)->where('is_active', true)->where('is_postable', true)->pluck('id')->all();

        // 1. what each line is worth before tax
        $prepared = [];
        foreach ($rows as $i => $row) {
            $description = trim((string) ($row['description'] ?? ''));
            if ($description === '') {
                throw new SalesViolation('Line '.($i + 1).' needs a description.', 'line_invalid', ['line' => $i + 1]);
            }
            $unit = (int) ($row['unit_cents'] ?? 0);
            if ($unit < 0) {
                throw new SalesViolation('Line '.($i + 1).' has a negative price. Use a credit note to reduce what a customer owes.', 'line_invalid', ['line' => $i + 1]);
            }
            $quantity = (string) ($row['quantity'] ?? '1');
            try {
                $quantityE4 = SalesDocumentLine::parseQuantity($quantity);
                if ($quantityE4 <= 0) {
                    throw new InvalidArgumentException('A quantity must be greater than zero.');
                }
                $amount = TaxMath::lineAmount($unit, $quantity, (int) ($row['discount_bp'] ?? 0), (int) ($row['discount_cents'] ?? 0));
            } catch (InvalidArgumentException $e) {
                throw new SalesViolation('Line '.($i + 1).': '.$e->getMessage(), 'line_invalid', ['line' => $i + 1]);
            }

            $income = isset($row['income_account_id']) && $row['income_account_id'] !== '' ? (int) $row['income_account_id'] : null;
            if ($income !== null && ! in_array($income, $validIncome, true)) {
                throw new SalesViolation('Line '.($i + 1).' names an account that is not in this ledger\'s chart, or cannot be posted to.', 'line_invalid', ['line' => $i + 1]);
            }

            $prepared[$i] = ['description' => mb_substr($description, 0, 500), 'unit' => isset($row['unit']) && $row['unit'] !== '' ? mb_substr((string) $row['unit'], 0, 20) : null,
                'quantity' => $quantity, 'quantity_e4' => $quantityE4, 'unit_cents' => $unit, 'discount_bp' => (int) ($row['discount_bp'] ?? 0),
                'discount_cents' => (int) ($row['discount_cents'] ?? 0), 'amount' => $amount, 'income' => $income, 'tax_code' => $row['tax_code'] ?? null,
                'parent_line_id' => isset($row['parent_line_id']) ? (int) $row['parent_line_id'] : null];
        }

        // 2. tax
        $lines = [];
        $summary = [];
        $netTotal = $taxTotal = $grossTotal = $discountTotal = 0;
        $roundingLevel = null;
        $ruleVersions = [];
        $confidence = null;

        if ($registered) {
            $inputs = [];
            foreach ($prepared as $i => $p) {
                $code = is_string($p['tax_code']) && $p['tax_code'] !== '' ? $p['tax_code'] : null;
                if ($code === null) {
                    throw new SalesViolation('Line '.($i + 1).' needs a tax code: this business charges VAT.', 'line_invalid', ['line' => $i + 1]);
                }
                $inputs[] = new TaxLineInput($p['unit_cents'], $p['quantity'], $code, $p['discount_bp'], $p['discount_cents'], $taxPoint, $inclusive);
            }

            try {
                $document = $this->tax->forDocument($inputs, $jurisdiction, $issueDate, $inclusive);
            } catch (NoValidTaxRule|InvalidArgumentException $e) {
                throw new SalesViolation('The tax could not be worked out: '.$e->getMessage(), 'tax_rule', ['detail' => $e->getMessage()]);
            }

            $codeIds = [];
            foreach ($document->lines as $i => $lt) {
                $p = $prepared[$i];
                $codeIds[$lt->taxCode] ??= $this->rules->taxCode($jurisdiction, $lt->taxCode, $taxPoint);
                if (! in_array($codeIds[$lt->taxCode]->applies_to, ['sales', 'both'], true)) {
                    throw new SalesViolation("Tax code {$lt->taxCode} is for purchases and cannot be used on a sale.", 'line_invalid', ['line' => $i + 1]);
                }
                $lines[] = new ComputedLine($i + 1, $p['description'], $p['unit'], $p['quantity_e4'], $p['unit_cents'], $p['discount_bp'], $p['discount_cents'],
                    $lt->taxCode, $codeIds[$lt->taxCode]->id, $lt->rateBp, $lt->taxKind, $lt->beforeDiscountCents, $lt->discountCents, $lt->netCents, $lt->taxCents,
                    $lt->grossCents, $p['income'] ?? $defaultIncome, $p['parent_line_id'], $lt->boxOutput);
            }

            $summary = $document->summary;
            $netTotal = $document->netCents;
            $taxTotal = $document->taxCents;
            $grossTotal = $document->grossCents;
            $roundingLevel = $document->roundingLevel;
            $ruleVersions = $document->ruleVersions;
            $confidence = $document->confidence;
            $discountTotal = array_sum(array_map(fn (ComputedLine $l) => $l->lineDiscountCents, $lines));
        } else {
            foreach ($prepared as $i => $p) {
                $a = $p['amount'];
                $lines[] = new ComputedLine($i + 1, $p['description'], $p['unit'], $p['quantity_e4'], $p['unit_cents'], $p['discount_bp'], $p['discount_cents'],
                    null, null, 0, null, $a['before_discount'], $a['discount'], $a['amount'], 0, $a['amount'], $p['income'] ?? $defaultIncome, $p['parent_line_id'], null);
                $netTotal += $a['amount'];
                $grossTotal += $a['amount'];
                $discountTotal += $a['discount'];
            }
            $summary = [['tax_code' => null, 'rate_bp' => 0, 'net_cents' => $netTotal, 'tax_cents' => 0, 'gross_cents' => $grossTotal]];
            $ruleVersions = ['not_vat_registered'];
            $confidence = 'high';
        }

        // 3. the base-currency view, folded the way the journal will be posted
        $groups = PostingGroups::fold(array_map(fn (ComputedLine $l) => [
            'income' => $l->incomeAccountId ?? 'sales', 'tax_code_id' => $l->taxCodeId, 'tax_code' => $l->taxCode, 'net' => $l->netCents, 'tax' => $l->taxCents,
        ], $lines), $rateE8);

        return new ComputedDocument(
            currency: $currency, baseCurrency: $base, fxRateE8: $rateE8, fxAsOf: $fxAsOf, fxSource: $fxSource, fxManual: $fxManual,
            pricesIncludeTax: $inclusive, jurisdiction: $jurisdiction, vatRegistered: $registered, lines: $lines, summary: $summary,
            netCents: $netTotal, discountCents: $discountTotal, taxCents: $taxTotal, totalCents: $grossTotal,
            baseNetCents: $groups['base_net'], baseTaxCents: $groups['base_tax'], baseTotalCents: $groups['base_net'] + $groups['base_tax'],
            roundingLevel: $roundingLevel, ruleVersions: $ruleVersions, confidence: $confidence, groups: ['income' => $groups['income'], 'tax' => $groups['tax']],
        );
    }

    /**
     * The exchange rate to base for a foreign document: the one frozen on the document or typed by a person, else the
     * newest rate on or before the issue date. The rate, its date and its source are stored with the document.
     *
     * @return array{0: ?int, 1: ?string, 2: ?string, 3: bool}
     */
    private function rate(string $currency, string $base, CarbonImmutable $on, array $input, ?SalesDocument $parent): array
    {
        if ($currency === $base) {
            return [null, null, null, false];
        }

        foreach ([$currency, $base] as $code) {
            if (Currency::minorUnits($code) !== 2) {
                throw new SalesViolation("{$code} does not use two decimal places; invoices in such currencies are not supported yet.", 'currency_unsupported');
            }
        }

        // a credit or debit note is booked at the rate of the invoice it corrects, so receivables clear exactly
        if ($parent !== null && $parent->fx_rate_e8 !== null) {
            return [(int) $parent->fx_rate_e8, $parent->fx_rate_as_of?->toDateString(), $parent->fx_rate_source, true];
        }

        if (! empty($input['fx_rate_e8'])) {
            return [(int) $input['fx_rate_e8'], $input['fx_as_of'] ?? $on->toDateString(), $input['fx_source'] ?? 'manual', true];
        }

        if (! empty($input['fx_snapshot']) && ! empty($input['fx_locked'])) {
            $s = $input['fx_snapshot'];

            return [(int) $s['rate_e8'], $s['as_of'] ?? null, $s['source'] ?? null, ($s['source'] ?? '') === 'manual'];
        }

        try {
            $quote = $this->fx->rate($currency, $base, $on);
        } catch (NoFxRate) {
            throw new SalesViolation("There is no {$currency} to {$base} exchange rate on or before {$on->toDateString()}. Enter the rate on the invoice, or record it under exchange rates first.", 'no_fx_rate');
        }

        return [$quote->rateE8, $quote->asOf->toDateString(), $quote->source, false];
    }
}
