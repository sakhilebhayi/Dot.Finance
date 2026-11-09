<?php

namespace Database\Seeders;

use App\Models\Shared\TaxCode;
use App\Models\Shared\TaxRate;
use App\Models\Shared\TaxRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The South African (jurisdiction ZA) tax rules, rates and codes, as data.
 *
 * EVERY figure here comes from docs/R44_TAX_RESEARCH.md (compiled 2026-11-09 from SARS and Treasury
 * documents) and from nothing else; the section named in each row's source_ref is where to check it.
 * Nothing is invented: where the research could not confirm a start date the row starts at 2024-03-01
 * and says "earlier history not modelled"; items the research marks uncertain (assessed-loss limit,
 * dividends-tax payment date, administrative penalty bands, VAT payments-basis cap, EMP501 penalty,
 * VAT thresholds' legal status) are stored with confidence low or medium exactly as the research
 * labels them, and a tax rule that has no source is not stored at all. Pre-2018 VAT history, the 2024-25
 * individual brackets (not listed in full), and other jurisdictions are NOT seeded.
 *
 * Idempotent and append-only: a row that already exists (same rule, qualifier and valid_from) is left
 * untouched, never updated. A changed figure is a NEW row: add it here with a later valid_from and close
 * the old one by hand (rule rows only ever move their valid_to earlier).
 *
 *   php artisan db:seed --class=TaxRulesSeeder --force
 *
 * This is a guidance table, not tax advice: the app labels every figure with its confidence and source.
 */
class TaxRulesSeeder extends Seeder
{
    private const ACCESSED = '2026-11-09';

    /** Earliest date the app needs when the research gives a certain current value but not its start date. */
    private const EARLIEST = '2024-03-01';

    private const J = 'ZA';

    // sources, from the research's own source list (section 10)
    private const URL_VAT_RATES = 'https://www.sars.gov.za/tax-rates/value-added-tax/';

    private const URL_BUDGET_FAQ = 'https://www.sars.gov.za/about/sars-tax-and-customs-system/budget/budget-2026-frequently-asked-questions/';

    private const URL_VAT_CONNECT_21 = 'https://www.sars.gov.za/businesses-and-employers/my-business-and-tax/vat-connect-issue-21-september-2026/';

    private const URL_VAT_PERIODS = 'https://www.sars.gov.za/types-of-tax/value-added-tax/tax-periods-for-vat-vendors/';

    private const URL_VAT_OBLIGATIONS = 'https://www.sars.gov.za/types-of-tax/value-added-tax/obligations-of-a-vat-vendor/';

    private const URL_VAT201 = 'https://www.sars.gov.za/gen-elec-04-g01-guide-for-completing-the-value-added-tax-vat201-declaration-external-guide';

    private const URL_VAT404 = 'https://www.sars.gov.za/wp-content/uploads/Ops/Guides/Legal-Pub-Guide-VAT404-VAT-404-Guide-for-Vendors.pdf';

    private const URL_TAX_GUIDE = 'https://archive.treasury.gov.za/documents/national%20budget/2026/sars/Budget%202026%20Tax%20guide.pdf';

    private const URL_COMPANIES = 'https://www.sars.gov.za/tax-rates/income-tax/companies-trusts-and-small-business-corporations-sbc/';

    private const URL_INDIVIDUALS = 'https://www.sars.gov.za/tax-rates/income-tax/rates-of-tax-for-individuals/';

    private const URL_PT_GUIDE = 'https://www.sars.gov.za/wp-content/uploads/Ops/Guides/GEN-PT-01-G01-Guide-for-Provisional-Tax-External-Guide.pdf';

    private const URL_CALENDAR = 'https://www.sars.gov.za/individuals/i-need-help-with-my-tax/calendar/';

    private const URL_ADMIN_PENALTY = 'https://www.sars.gov.za/individuals/what-if-i-do-not-agree/admin-penalty/';

    private const URL_UIF = 'https://www.sars.gov.za/types-of-tax/unemployment-insurance-fund/';

    private const URL_EMP501 = 'https://www.sars.gov.za/latest-news/employer-interim-declarations-emp501-21-september-to-october-2026/';

    private const URL_ETI = 'https://www.sars.gov.za/legal-pub-guide-eti01-guide-to-the-employment-tax-incentive/';

    private const URL_PAYE = 'https://www.sars.gov.za/guide-for-employers-in-respect-of-employees-tax-2027/';

    private const URL_COIDA = 'https://acts.co.za/news/blog/2026/04/coida-2026-roe-submission-dates';

    private const URL_TAA_S29 = 'https://www.acts.co.za/tax/29__duty_to_keep_records.php';

    private const URL_POLICY = 'internal:docs/R44_DECISIONS.md#D-200';

    private int $created = 0;

    private int $skipped = 0;

    public function run(): void
    {
        DB::transaction(function () {
            $this->vatRules();
            $this->vatRatesAndCodes();
            $this->companyAndSmallBusinessTax();
            $this->individualTax();
            $this->otherTaxes();
            $this->provisionalTax();
            $this->employmentTaxes();
            $this->interestAndRecords();
        });

        $this->command?->info("TaxRulesSeeder: {$this->created} created, {$this->skipped} already present.");
    }

    // ── VAT ─────────────────────────────────────────────────────────────

    private function vatRules(): void
    {
        $vat = 'VAT';

        $this->rule($vat, 'vat.standard_rate_bp', 'basis_points', 1500, '2018-04-01', null, self::URL_VAT_RATES, 'high',
            ref: 'R44_TAX_RESEARCH.md 1, 2.1; SARS VAT rates page; Budget 2026 Tax Guide',
            note: 'Standard rate 15 percent since 1 April 2018. The 15.5 percent (1 May 2025) and 16 percent (1 April 2026) increases were reversed; Budget 2026 did not raise it. Never hard-code the rate; resolve it by tax point (time of supply). History before 2018-04-01 is not modelled.');
        $this->rule($vat, 'vat.zero_rate_bp', 'basis_points', 0, '2018-04-01', null, self::URL_VAT201, 'high', ref: 'R44_TAX_RESEARCH.md 1; VAT201 guide');

        // Registration thresholds (D-20: confidence medium; shown as "from SARS guidance, confirm").
        $legal = 'Figures announced in the Budget Speech of 25 February 2026 and applied by SARS from 1 April 2026; VAT Connect 21 says the Rates and Monetary Amounts and Amendment of Revenue Laws Act 3 of 2026 was promulgated on 1 April 2026 but still calls the voluntary threshold "proposed". Confirm the final amounts in the Act.';
        $this->rule($vat, 'vat.registration.compulsory_threshold_cents', 'money_cents', 230_000_000, '2026-04-01', null, self::URL_BUDGET_FAQ, 'medium', unit: 'ZAR',
            ref: 'R44_TAX_RESEARCH.md 2.2; Budget 2026 FAQs; Budget 2026 Tax Guide; VAT Connect 21', note: 'Taxable supplies of more than R2,300,000 in any 12 months. '.$legal);
        $this->rule($vat, 'vat.registration.compulsory_threshold_cents', 'money_cents', 100_000_000, self::EARLIEST, '2026-03-31', self::URL_BUDGET_FAQ, 'medium', unit: 'ZAR',
            ref: 'R44_TAX_RESEARCH.md 2.2', note: 'Previous threshold, more than R1,000,000 in 12 months. Earlier history not modelled.');
        $this->rule($vat, 'vat.registration.voluntary_threshold_cents', 'money_cents', 12_000_000, '2026-04-01', null, self::URL_BUDGET_FAQ, 'medium', unit: 'ZAR', status: 'announced',
            ref: 'R44_TAX_RESEARCH.md 2.2; Budget 2026 FAQs; VAT Connect 21', note: 'Taxable supplies of more than R120,000 (up to the compulsory threshold). Legal status: announced and applied by SARS; VAT Connect 21 still describes it as proposed. '.$legal);
        $this->rule($vat, 'vat.registration.voluntary_threshold_cents', 'money_cents', 5_000_000, self::EARLIEST, '2026-03-31', self::URL_BUDGET_FAQ, 'medium', unit: 'ZAR',
            ref: 'R44_TAX_RESEARCH.md 2.2', note: 'Previous voluntary threshold, R50,000. Earlier history not modelled.');
        $this->rule($vat, 'vat.registration.window_days', 'integer', 21, self::EARLIEST, null, self::URL_VAT404, 'medium', unit: 'days',
            ref: 'R44_TAX_RESEARCH.md 2.2 (VAT Act s23, stated at a general level in VAT 404)', note: 'Register within 21 days of exceeding the threshold.');

        // D-18: the payments-basis cap is uncertain, seeded low.
        $this->rule($vat, 'vat.payments_basis.turnover_cap_cents', 'money_cents', 250_000_000, self::EARLIEST, null, self::URL_VAT404, 'low', unit: 'ZAR',
            ref: 'R44_TAX_RESEARCH.md 2.2, 9 (item 5); VAT 404 Issue 15 s4.3', note: 'Payments basis available to vendors whose taxable supplies have not exceeded R2.5 million. NOT verified against the 2026 changes: the Budget raised other thresholds to R2.3m. Verify before relying on it.');

        $this->rule($vat, 'vat.late_payment_penalty_bp', 'basis_points', 1000, self::EARLIEST, null, self::URL_VAT404, 'high', ref: 'R44_TAX_RESEARCH.md 1; VAT 404 ch.11 (VAT Act s39)', note: '10 percent of unpaid VAT plus interest.');
        $this->rule($vat, 'vat.accommodation.long_stay_pct_bp', 'basis_points', 6000, self::EARLIEST, null, self::URL_VAT201, 'high', ref: 'R44_TAX_RESEARCH.md 2.5 (field 6)', note: '60 percent of the charge for commercial accommodation exceeding 28 days.');
        $this->rule($vat, 'vat.invoice.full_invoice_threshold_cents', 'money_cents', 500_000, self::EARLIEST, null, self::URL_VAT404, 'high', unit: 'ZAR',
            ref: 'R44_TAX_RESEARCH.md 2.7; VAT 404 s13.1; VAT Act s20(4)', note: 'Consideration over R5,000 needs a full tax invoice. VAT 404 says "more than R5,000" in one place and "R5,000 or more" in another: issue a full invoice at exactly R5,000. An abridged invoice is never allowed for a zero-rated supply.');
        $this->rule($vat, 'vat.invoice.no_invoice_threshold_cents', 'money_cents', 5_000, self::EARLIEST, null, self::URL_VAT404, 'high', unit: 'ZAR', ref: 'R44_TAX_RESEARCH.md 2.7', note: 'Consideration under R50: no tax invoice needed.');
        $this->rule($vat, 'vat.invoice.issue_within_days', 'integer', 21, self::EARLIEST, null, self::URL_VAT404, 'high', unit: 'days', ref: 'R44_TAX_RESEARCH.md 2.7', note: 'A tax invoice is issued within 21 days of the time of supply.');
        $this->rule($vat, 'vat.input_claim_window_years', 'integer', 5, self::EARLIEST, null, self::URL_VAT404, 'high', unit: 'years', ref: 'R44_TAX_RESEARCH.md 2.8; VAT 404 s8.3.6', note: 'Input tax must be claimed within 5 years after the end of the tax period in which the invoice should have been issued or the import occurred.');
        $this->rule($vat, 'vat.category_c.turnover_trigger_cents', 'money_cents', 3_000_000_000, self::EARLIEST, null, self::URL_VAT_PERIODS, 'high', unit: 'ZAR', ref: 'R44_TAX_RESEARCH.md 2.3', note: 'Category C (monthly) when taxable supplies exceed or are likely to exceed R30m in 12 months.');

        // Due dates (D-19: the VAT due date is a configurable rule, default eFiling).
        $this->rule($vat, 'vat.due_date.manual', 'json', ['anchor' => 'period_end', 'offset_months' => 1, 'day' => 25, 'convention' => 'previous_business_day', 'channel' => 'manual'], self::EARLIEST, null, self::URL_VAT404, 'high',
            ref: 'R44_TAX_RESEARCH.md 2.4; VAT 404', note: 'Manual / EFT / bank: return and payment by the 25th of the month after the tax period ends, or the preceding business day.');
        $this->rule($vat, 'vat.due_date.efiling', 'json', ['anchor' => 'period_end', 'offset_months' => 1, 'day' => 'last_business_day', 'convention' => 'previous_business_day', 'channel' => 'efiling', 'payment_outside_efiling_day' => 25], self::EARLIEST, null, self::URL_VAT_OBLIGATIONS, 'high',
            ref: 'R44_TAX_RESEARCH.md 2.4; VAT 404; SARS obligations page', note: 'eFiling: return and payment by the last business day of the month after the tax period ends. Payment made outside eFiling is due on the 25th. Saturday and Sunday are not business days; public holidays need a separate holiday table.');
        $this->rule($vat, 'vat.due_date.default_channel', 'text', 'efiling', self::EARLIEST, null, self::URL_POLICY, 'high', status: 'policy',
            ref: 'R44_ARCHITECTURE.md D-19', note: 'Which of the two VAT due-date rules a ledger uses unless it says otherwise (D-19).');

        // Tax periods (categories A-F)
        foreach ([
            'A' => [['months' => 2, 'period_end_months' => [1, 3, 5, 7, 9, 11]], 'high', 'The default period; allocated by SARS, not chosen.'],
            'B' => [['months' => 2, 'period_end_months' => [2, 4, 6, 8, 10, 12]], 'high', 'The default period; allocated by SARS, not chosen.'],
            'C' => [['months' => 1, 'period_end_months' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]], 'high', 'Monthly: taxable supplies exceed or are likely to exceed R30m in 12 months, or by application.'],
            'D' => [['months' => 6, 'period_end_months' => [2, 8]], 'high', 'Six-monthly: farmers with taxable supplies under R1.5m and registered micro businesses.'],
            'E' => [['months' => 12, 'period_end_months' => [], 'ends' => 'last day of the vendor\'s year of assessment'], 'high', 'Yearly: companies and trusts that let fixed property, hire movable goods or manage connected companies (VAT 404 ch.3).'],
            'F' => [['months' => 4, 'period_end_months' => [6, 10, 2]], 'medium', 'Four-monthly, ending June, October and February. Supported but rare. From the VAT Act s27 via a secondary source; the SARS periods page omits it.'],
        ] as $category => [$value, $confidence, $note]) {
            $this->rule($vat, 'vat.period_category', 'json', $value, self::EARLIEST, null, self::URL_VAT_PERIODS, $confidence, qualifier: $category, ref: 'R44_TAX_RESEARCH.md 2.3; VAT Act s27', note: $note);
        }

        // Rounding level: a Dot.Finance default, NOT a SARS rule.
        $this->rule($vat, 'vat.rounding.level', 'text', 'line', '2018-04-01', null, self::URL_POLICY, 'low', status: 'policy',
            ref: 'R44_DECISIONS.md D-200', note: 'Line or document. This is a Dot.Finance default, not a SARS figure: R44_TAX_RESEARCH.md found no SARS rounding rule for invoice lines (it only notes that VAT201 fields are entered in rands, rounding rule unconfirmed). Tax is rounded per line and the document total is the sum of the lines. Change it by adding a new version, never by editing this one.');

        // VAT201 return layout (field numbers, as read from the SARS guide).
        $this->rule($vat, 'vat201.fields', 'json', $this->vat201Fields(), '2025-05-12', null, self::URL_VAT201, 'high', qualifier: 'rev 11',
            ref: 'R44_TAX_RESEARCH.md 2.5; SARS GEN-ELEC-04-G01 rev 11 (effective 12 May 2025)',
            note: 'Field numbers and formulas for fields 1 to 20 of the VAT201 (diesel refund fields 21 to 38 and the payment section are not modelled). Supplies fields are VAT-inclusive except fields 5 and 7. t = rule vat.standard_rate_bp / 10000; tax fraction = t / (1 + t). Fields 4, 4A and 11 are editable on the return.');
    }

    /** @return list<array<string, mixed>> */
    private function vat201Fields(): array
    {
        $f = fn (string $code, string $label, string $kind, ?string $formula = null, bool $editable = false) => ['code' => $code, 'label' => $label, 'kind' => $kind, 'formula' => $formula, 'editable' => $editable];

        return [
            $f('1', 'Standard-rated supplies, excluding capital goods and services and accommodation (VAT inclusive)', 'input'),
            $f('1A', 'Standard-rated supplies of capital goods and services (VAT inclusive)', 'input'),
            $f('2', 'Zero-rated supplies, excluding exports', 'input'),
            $f('2A', 'Zero-rated exported goods', 'input'),
            $f('3', 'Exempt supplies and non-supplies', 'input'),
            $f('4', 'Output tax on field 1', 'calc', 'f1 * tax_fraction', true),
            $f('4A', 'Output tax on field 1A', 'calc', 'f1A * tax_fraction', true),
            $f('5', 'Commercial accommodation exceeding 28 days (VAT exclusive)', 'input'),
            $f('6', '60 percent of field 5', 'calc', 'f5 * vat.accommodation.long_stay_pct'),
            $f('7', 'Accommodation of 28 days or less (VAT exclusive)', 'input'),
            $f('8', 'Sum of fields 6 and 7', 'calc', 'f6 + f7'),
            $f('9', 'Output tax on accommodation', 'calc', 'f8 * t'),
            $f('10', 'Change in use and export of second-hand goods (VAT inclusive)', 'input'),
            $f('11', 'Output tax on field 10', 'calc', 'f10 * tax_fraction', true),
            $f('12', 'Other and imported services: debit notes issued, credit notes received, recovered bad debts, barter, imported services for non-taxable use, going-concern acquisitions, accounting-basis changes', 'input'),
            $f('13', 'Total output tax', 'calc', 'f4 + f4A + f9 + f11 + f12'),
            $f('14', 'Capital goods and services supplied to you (VAT amount)', 'input'),
            $f('14A', 'Capital goods imported (VAT amount)', 'input'),
            $f('15', 'Other goods and services supplied to you (not capital)', 'input'),
            $f('15A', 'Other goods imported (not capital)', 'input'),
            $f('16', 'Change in use', 'input'),
            $f('17', 'Bad debts (VAT portion, invoice basis only)', 'input'),
            $f('18', 'Other: credit notes issued, debit notes received, accounting-basis changes', 'input'),
            $f('19', 'Total input tax', 'calc', 'f14 + f14A + f15 + f15A + f16 + f17 + f18'),
            $f('20', 'VAT payable (positive) or refundable (negative)', 'calc', 'f13 - f19'),
        ];
    }

    private function vatRatesAndCodes(): void
    {
        $this->rate('ZA_VAT_STD', 1500, '2018-04-01', null, self::URL_VAT_RATES, 'high', 'R44_TAX_RESEARCH.md 2.1', 'The VAT standard rate, 15 percent. Same value as rule vat.standard_rate_bp.');

        // [code, name, description, kind, applies_to, rate_ref, box_output, box_input, deductible_policy, sort]
        $codes = [
            ['ZA_VAT_STD', 'Standard-rated', 'Most goods and services. VAT is charged at the standard rate.', 'standard', 'both', 'ZA_VAT_STD', '1', '15', 'allowed', 10],
            ['ZA_VAT_CAPITAL', 'Standard-rated, capital goods and services', 'Assets kept in the business (equipment, vehicles that qualify, land and buildings). Reported in its own box on the VAT201.', 'standard', 'both', 'ZA_VAT_STD', '1A', '14', 'allowed', 20],
            ['ZA_VAT_IMPORT', 'Imported goods, not capital', 'VAT paid to Customs on imported goods. Claimed from the bill of entry and proof of payment.', 'standard', 'purchases', 'ZA_VAT_STD', null, '15A', 'allowed', 30],
            ['ZA_VAT_IMPORT_CAPITAL', 'Imported capital goods', 'VAT paid to Customs on imported capital goods.', 'standard', 'purchases', 'ZA_VAT_STD', null, '14A', 'allowed', 40],
            ['ZA_VAT_ZERO', 'Zero-rated, excluding exports', 'Taxable at 0 percent (for example basic foods and fuel levy goods). A full tax invoice is always needed.', 'zero', 'both', null, '2', null, 'not_applicable', 50],
            ['ZA_VAT_ZERO_EXPORT', 'Zero-rated, exported goods', 'Goods exported from South Africa, with the documents the law requires.', 'zero', 'sales', null, '2A', null, 'not_applicable', 60],
            ['ZA_VAT_EXEMPT', 'Exempt', 'Supplies the law exempts (financial services, residential rent, education). No VAT is charged and none can be claimed.', 'exempt', 'both', null, '3', null, 'not_applicable', 70],
            ['ZA_VAT_OUT_OF_SCOPE', 'Outside the scope of VAT', 'Not a supply for VAT (for example salaries, dividends, fines). Reported with the exempt supplies and non-supplies.', 'out_of_scope', 'both', null, '3', null, 'not_applicable', 80],
            ['ZA_VAT_DENIED_ENTERTAINMENT', 'Standard-rated, entertainment (input tax not claimable)', 'Staff and client entertainment. VAT is charged but cannot be claimed back, except in the cases the VAT Act lists.', 'standard', 'purchases', 'ZA_VAT_STD', null, null, 'denied_entertainment', 90],
            ['ZA_VAT_DENIED_MOTOR_CAR', 'Standard-rated, motor car (input tax not claimable)', 'Buying a passenger motor car. VAT is charged but cannot be claimed back, except for motor dealers and car rental businesses.', 'standard', 'purchases', 'ZA_VAT_STD', null, null, 'denied_motor_car', 100],
        ];

        foreach ($codes as [$code, $name, $description, $kind, $appliesTo, $rateRef, $boxOutput, $boxInput, $policy, $sort]) {
            $this->code($code, $name, $description, $kind, $appliesTo, $rateRef, $boxOutput, $boxInput, $policy, $sort);
        }
    }

    // ── company, small business, turnover tax ───────────────────────────

    private function companyAndSmallBusinessTax(): void
    {
        $clock = 'Clock: the company\'s year-of-assessment END date. ';

        $this->rule('CIT', 'cit.rate_bp', 'basis_points', 2700, '2023-04-01', '2027-03-31', self::URL_COMPANIES, 'high',
            ref: 'R44_TAX_RESEARCH.md 1, 3.1; SARS companies and SBC rates page (updated 28 Oct 2026)',
            note: $clock.'27 percent for years of assessment ending 1 April 2023 to 31 March 2027, unchanged in Budget 2026. Not valid beyond 2027-03-31 until a later version is added. SEZ companies may qualify for 15 percent (not modelled).');
        $this->rule('CIT', 'cit.rate_bp', 'basis_points', 2800, '2022-04-01', '2023-03-30', self::URL_COMPANIES, 'high',
            ref: 'R44_TAX_RESEARCH.md 1', note: $clock.'28 percent for years of assessment ending 1 April 2022 to 30 March 2023. The research lists 30 March for this range and 1 April for the 27 percent range, which leaves 31 March 2023 uncovered; the engine refuses that one date rather than guess.');

        $sbc = [
            ['above_cents' => 0, 'up_to_cents' => 9_900_000, 'base_cents' => 0, 'rate_bp' => 0],
            ['above_cents' => 9_900_000, 'up_to_cents' => 36_500_000, 'base_cents' => 0, 'rate_bp' => 700],
            ['above_cents' => 36_500_000, 'up_to_cents' => 55_000_000, 'base_cents' => 1_862_000, 'rate_bp' => 2100],
            ['above_cents' => 55_000_000, 'up_to_cents' => null, 'base_cents' => 5_747_000, 'rate_bp' => 2700],
        ];
        $this->rule('CIT', 'sbc.bands', 'json', $sbc, '2026-04-01', '2027-03-31', self::URL_COMPANIES, 'high',
            ref: 'R44_TAX_RESEARCH.md 1, 3.1; Budget 2026 Tax Guide', note: $clock.'Small business corporation: 0 percent to R99,000; 7 percent of the excess to R365,000; R18,620 plus 21 percent to R550,000; R57,470 plus 27 percent above. Years of assessment ending 1 April 2026 to 31 March 2027. Bands are above/up to amounts in cents with a base and a rate on the part above.');
        $this->rule('CIT', 'sbc.gross_income_limit_cents', 'money_cents', 2_000_000_000, '2026-04-01', '2027-03-31', self::URL_COMPANIES, 'high', unit: 'ZAR',
            ref: 'R44_TAX_RESEARCH.md 3.1; Budget 2026 FAQs', note: $clock.'Gross income no more than R20m; natural-person shareholders only; not a personal service company or a holding company.');

        // Uncertain: assessed loss limit (D-18)
        $this->rule('CIT', 'cit.assessed_loss.setoff', 'json', ['fixed_cents' => 100_000_000, 'balance_pct_bp' => 8000], self::EARLIEST, null, self::URL_COMPANIES, 'low',
            ref: 'R44_TAX_RESEARCH.md 3.3, 9 (item 6)', note: 'Set-off of an assessed loss limited to R1m plus 80 percent of the balance of taxable income. From the researcher\'s own knowledge of the post-2022 rule and NOT re-confirmed on a SARS page. Confirm before relying on it.');

        // Turnover tax
        $tot = [
            ['above_cents' => 0, 'up_to_cents' => 60_000_000, 'base_cents' => 0, 'rate_bp' => 0],
            ['above_cents' => 60_000_000, 'up_to_cents' => 95_000_000, 'base_cents' => 0, 'rate_bp' => 100],
            ['above_cents' => 95_000_000, 'up_to_cents' => 140_000_000, 'base_cents' => 350_000, 'rate_bp' => 200],
            ['above_cents' => 140_000_000, 'up_to_cents' => null, 'base_cents' => 1_250_000, 'rate_bp' => 300],
        ];
        $totNote = 'Turnover tax on qualifying turnover: 0 percent to R600,000; 1 percent of the excess to R950,000; R3,500 plus 2 percent to R1,400,000; R12,500 plus 3 percent above. First update to the table since 2009.';
        $this->rule('TURNOVER_TAX', 'tot.bands', 'json', $tot, '2026-03-01', '2027-02-28', self::URL_TAX_GUIDE, 'high', qualifier: 'individual', ref: 'R44_TAX_RESEARCH.md 1, 3.1; Budget 2026 Tax Guide',
            note: 'Clock: the individual tax year, 1 March 2026 to 28 February 2027. '.$totNote);
        $this->rule('TURNOVER_TAX', 'tot.bands', 'json', $tot, '2026-04-01', '2027-03-31', self::URL_TAX_GUIDE, 'high', qualifier: 'company', ref: 'R44_TAX_RESEARCH.md 1, 3.1; Budget 2026 Tax Guide',
            note: $clock.'Companies: years of assessment ending 1 April 2026 to 31 March 2027. '.$totNote);
        $this->rule('TURNOVER_TAX', 'tot.qualifying_limit_cents', 'money_cents', 230_000_000, '2026-04-01', null, self::URL_TAX_GUIDE, 'medium', unit: 'ZAR',
            ref: 'R44_TAX_RESEARCH.md 1, 3.1; Budget 2026 Tax Guide; Budget 2026 FAQs', note: 'Qualifying turnover up to R2.3m (limit increased from R1m on 1 April 2026). Rates are high confidence; the wording of the effective date of the limit is medium. Excludes professional services above 20 percent of receipts, personal service providers and labour brokers.');
        $this->rule('TURNOVER_TAX', 'tot.qualifying_limit_cents', 'money_cents', 100_000_000, self::EARLIEST, '2026-03-31', self::URL_TAX_GUIDE, 'medium', unit: 'ZAR',
            ref: 'R44_TAX_RESEARCH.md 3.1', note: 'Previous qualifying turnover limit, R1m. Earlier history not modelled.');
    }

    // ── individuals ─────────────────────────────────────────────────────

    private function individualTax(): void
    {
        $clock = 'Clock: the individual tax year (1 March to the last day of February); the 2027 year of assessment runs 1 March 2026 to 28 February 2027. ';

        $pit = [
            ['above_cents' => 0, 'up_to_cents' => 24_510_000, 'base_cents' => 0, 'rate_bp' => 1800],
            ['above_cents' => 24_510_000, 'up_to_cents' => 38_310_000, 'base_cents' => 4_411_800, 'rate_bp' => 2600],
            ['above_cents' => 38_310_000, 'up_to_cents' => 53_020_000, 'base_cents' => 7_999_800, 'rate_bp' => 3100],
            ['above_cents' => 53_020_000, 'up_to_cents' => 69_580_000, 'base_cents' => 12_559_900, 'rate_bp' => 3600],
            ['above_cents' => 69_580_000, 'up_to_cents' => 88_700_000, 'base_cents' => 18_521_500, 'rate_bp' => 3900],
            ['above_cents' => 88_700_000, 'up_to_cents' => 187_860_000, 'base_cents' => 25_978_300, 'rate_bp' => 4100],
            ['above_cents' => 187_860_000, 'up_to_cents' => null, 'base_cents' => 66_633_900, 'rate_bp' => 4500],
        ];
        $this->rule('PIT', 'pit.bands', 'json', $pit, '2026-03-01', '2027-02-28', self::URL_INDIVIDUALS, 'high', ref: 'R44_TAX_RESEARCH.md 3.1; SARS rates of tax for individuals (updated 28 Oct 2026)',
            note: $clock.'18 percent to R245,100; R44,118 plus 26 percent to R383,100; R79,998 plus 31 percent to R530,200; R125,599 plus 36 percent to R695,800; R185,215 plus 39 percent to R887,000; R259,783 plus 41 percent to R1,878,600; R666,339 plus 45 percent above. First inflation adjustment (3.4 percent) since 2023/24. The 2025 and 2026 tables (the 2024 table) are not listed in full in the research and are not seeded.');

        $this->rule('PIT', 'pit.rebate.primary_cents', 'money_cents', 1_782_000, '2026-03-01', '2027-02-28', self::URL_INDIVIDUALS, 'high', unit: 'ZAR', ref: 'R44_TAX_RESEARCH.md 1', note: $clock.'Primary rebate R17,820.');
        $this->rule('PIT', 'pit.rebate.secondary_cents', 'money_cents', 976_500, '2026-03-01', '2027-02-28', self::URL_INDIVIDUALS, 'high', unit: 'ZAR', ref: 'R44_TAX_RESEARCH.md 1', note: $clock.'Secondary rebate (age 65 and over) R9,765.');
        $this->rule('PIT', 'pit.rebate.tertiary_cents', 'money_cents', 324_900, '2026-03-01', '2027-02-28', self::URL_INDIVIDUALS, 'high', unit: 'ZAR', ref: 'R44_TAX_RESEARCH.md 1', note: $clock.'Tertiary rebate (age 75 and over) R3,249.');
        $this->rule('PIT', 'pit.threshold.under65_cents', 'money_cents', 9_900_000, '2026-03-01', '2027-02-28', self::URL_INDIVIDUALS, 'high', unit: 'ZAR', ref: 'R44_TAX_RESEARCH.md 1', note: $clock.'Tax threshold under 65: R99,000.');
        $this->rule('PIT', 'pit.threshold.65_to_74_cents', 'money_cents', 15_325_000, '2026-03-01', '2027-02-28', self::URL_INDIVIDUALS, 'high', unit: 'ZAR', ref: 'R44_TAX_RESEARCH.md 1', note: $clock.'Tax threshold 65 to 74: R153,250.');
        $this->rule('PIT', 'pit.threshold.75_plus_cents', 'money_cents', 17_130_000, '2026-03-01', '2027-02-28', self::URL_INDIVIDUALS, 'high', unit: 'ZAR', ref: 'R44_TAX_RESEARCH.md 1', note: $clock.'Tax threshold 75 and over: R171,300.');
        $this->rule('PIT', 'pit.interest_exemption.under65_cents', 'money_cents', 2_380_000, '2026-03-01', '2027-02-28', self::URL_TAX_GUIDE, 'high', unit: 'ZAR', ref: 'R44_TAX_RESEARCH.md 3.1; Budget 2026 Tax Guide', note: $clock.'Interest exemption R23,800 (under 65).');
        $this->rule('PIT', 'pit.interest_exemption.65_plus_cents', 'money_cents', 3_450_000, '2026-03-01', '2027-02-28', self::URL_TAX_GUIDE, 'high', unit: 'ZAR', ref: 'R44_TAX_RESEARCH.md 3.1; Budget 2026 Tax Guide', note: $clock.'Interest exemption R34,500 (65 and over).');
        $this->rule('PIT', 'pit.tax_free_savings.annual_limit_cents', 'money_cents', 4_600_000, '2026-03-01', '2027-02-28', self::URL_TAX_GUIDE, 'high', unit: 'ZAR', ref: 'R44_TAX_RESEARCH.md 3.1; Budget 2026 Tax Guide', note: $clock.'Tax-free savings, R46,000 a year.');
        $this->rule('PAYE', 'paye.medical_credit.main_member_cents', 'money_cents', 37_600, '2026-03-01', '2027-02-28', self::URL_PAYE, 'high', unit: 'ZAR per month', ref: 'R44_TAX_RESEARCH.md 1, 4.1; Budget 2026 Tax Guide', note: $clock.'Medical scheme fees tax credit, R376 a month for the main member.');
        $this->rule('PAYE', 'paye.medical_credit.first_dependant_cents', 'money_cents', 37_600, '2026-03-01', '2027-02-28', self::URL_PAYE, 'high', unit: 'ZAR per month', ref: 'R44_TAX_RESEARCH.md 1, 4.1', note: $clock.'R376 a month for the first dependant (the first two persons together).');
        $this->rule('PAYE', 'paye.medical_credit.additional_dependant_cents', 'money_cents', 25_400, '2026-03-01', '2027-02-28', self::URL_PAYE, 'high', unit: 'ZAR per month', ref: 'R44_TAX_RESEARCH.md 1, 4.1', note: $clock.'R254 a month for each additional dependant.');
    }

    // ── dividends, withholding, other ───────────────────────────────────

    private function otherTaxes(): void
    {
        $this->rule('DIVIDENDS', 'dividends_tax.rate_bp', 'basis_points', 2000, self::EARLIEST, null, self::URL_TAX_GUIDE, 'high', ref: 'R44_TAX_RESEARCH.md 1, 3.3; Budget 2026 Tax Guide', note: '20 percent final tax, withheld by the paying company or regulated intermediary. Earlier history not modelled.');
        // D-18: dividends tax payment date uncertain -> low
        $this->rule('DIVIDENDS', 'dividends_tax.due_date', 'json', ['anchor' => 'payment_month', 'offset_months' => 1, 'day' => 'last_day_of_month'], self::EARLIEST, null, self::URL_TAX_GUIDE, 'low',
            ref: 'R44_TAX_RESEARCH.md 3.3, 5 (s64K), 9 (item 6)', note: 'Payable by the end of the month following the month of payment. From the researcher\'s knowledge of s64K, not confirmed on a SARS page (the calendar row is marked medium). Verify.');

        $this->rule('WITHHOLDING', 'wht.royalties_bp', 'basis_points', 1500, self::EARLIEST, null, self::URL_TAX_GUIDE, 'high', ref: 'R44_TAX_RESEARCH.md 1, 5', note: 'Withholding on royalties to non-residents, 15 percent. Treaty relief may reduce it.');
        $this->rule('WITHHOLDING', 'wht.interest_non_resident_bp', 'basis_points', 1500, self::EARLIEST, null, self::URL_TAX_GUIDE, 'high', ref: 'R44_TAX_RESEARCH.md 1, 5', note: 'Withholding on interest paid to non-residents, 15 percent, with exemptions (banks, government, listed debt).');
        $this->rule('WITHHOLDING', 'wht.foreign_entertainers_bp', 'basis_points', 1500, self::EARLIEST, null, self::URL_TAX_GUIDE, 'high', ref: 'R44_TAX_RESEARCH.md 1, 5', note: 'Withholding on foreign entertainers and sportspersons, 15 percent.');
        foreach (['individual' => 750, 'company' => 1000, 'trust' => 1500] as $seller => $bp) {
            $this->rule('WITHHOLDING', 'wht.immovable_property_bp', 'basis_points', $bp, self::EARLIEST, null, self::URL_TAX_GUIDE, 'high', qualifier: $seller, ref: 'R44_TAX_RESEARCH.md 1, 5', note: "Withholding on non-resident sellers of immovable property, {$seller} seller.");
        }

        $this->rule('ADMIN_PENALTY', 'tax.admin_penalty.bands', 'json', [
            ['prior_taxable_income_up_to_cents' => 25_000_000, 'monthly_cents' => 25_000],
            ['prior_taxable_income_up_to_cents' => 50_000_000, 'monthly_cents' => 50_000],
            ['prior_taxable_income_up_to_cents' => 100_000_000, 'monthly_cents' => 100_000],
            ['prior_taxable_income_up_to_cents' => 500_000_000, 'monthly_cents' => 200_000],
            ['prior_taxable_income_up_to_cents' => 1_000_000_000, 'monthly_cents' => 300_000],
            ['prior_taxable_income_up_to_cents' => 5_000_000_000, 'monthly_cents' => 400_000],
            ['prior_taxable_income_up_to_cents' => null, 'monthly_cents' => 1_600_000],
        ], self::EARLIEST, null, self::URL_ADMIN_PENALTY, 'low', ref: 'R44_TAX_RESEARCH.md 3.2, 9 (item 6)',
            note: 'Fixed monthly administrative penalty for a late return, by the previous year\'s taxable income or assessed loss, recurring monthly for up to 35 months. The SARS page confirms the R250 to R16,000 range, the monthly recurrence and the 35 months; the band table itself is from secondary sources (low to medium) and is stored as low. Confirm before relying on it.');
        $this->rule('ADMIN_PENALTY', 'tax.admin_penalty.max_months', 'integer', 35, self::EARLIEST, null, self::URL_ADMIN_PENALTY, 'high', unit: 'months', ref: 'R44_TAX_RESEARCH.md 3.2');
    }

    // ── provisional tax ─────────────────────────────────────────────────

    private function provisionalTax(): void
    {
        $guide = 'SARS Guide for Provisional Tax GEN-PT-01-G01 rev 28 (effective 16 Oct 2026)';

        $this->rule('PROVISIONAL', 'pt.due_dates', 'json', [
            'first' => ['anchor' => 'ya_start', 'offset_months' => 6, 'note' => 'within 6 months of the start of the year of assessment'],
            'second' => ['anchor' => 'ya_end', 'offset_months' => 0, 'note' => 'by the last day of the year of assessment'],
            'third' => ['anchor' => 'ya_end', 'offset_months' => 6, 'offset_months_if_year_end_february' => 7, 'optional' => true, 'note' => 'voluntary top-up payment: 7 months after year end for 28/29 February year ends (30 September), otherwise 6 months'],
            'business_day_convention' => 'applies_direction_not_stated',
        ], self::EARLIEST, null, self::URL_PT_GUIDE, 'high', ref: 'R44_TAX_RESEARCH.md 1, 3.2; '.$guide,
            note: 'Worked examples in the guide: February 2026 year end, 31 Aug 2025 / 28 Feb 2026 / 30 Sep 2026; 31 May 2026 year end, 30 Nov 2025 / 31 May 2026 / 30 Nov 2026. The guide says a business-day convention applies but the research does not record its direction, so none is assumed here.');
        $this->rule('PROVISIONAL', 'pt.penalty_underestimate_bp', 'basis_points', 2000, self::EARLIEST, null, self::URL_PT_GUIDE, 'high', ref: 'R44_TAX_RESEARCH.md 1, 3.2; '.$guide, note: 'Underestimation penalty, 20 percent of the shortfall (paragraph 20).');
        $this->rule('PROVISIONAL', 'pt.penalty_late_payment_bp', 'basis_points', 1000, self::EARLIEST, null, self::URL_PT_GUIDE, 'high', ref: 'R44_TAX_RESEARCH.md 1, 3.2; '.$guide, note: '10 percent late-payment penalty on the first and second periods. None on the third.');
        $this->rule('PROVISIONAL', 'pt.estimate_floor_above_threshold_bp', 'basis_points', 8000, self::EARLIEST, null, self::URL_PT_GUIDE, 'high', ref: 'R44_TAX_RESEARCH.md 1, 3.2; '.$guide, note: 'Taxable income above R1m: the penalty trigger is an estimate below 80 percent of actual taxable income. This is the PENALTY trigger, not a rule about the payment itself.');
        $this->rule('PROVISIONAL', 'pt.estimate_floor_up_to_threshold_bp', 'basis_points', 9000, self::EARLIEST, null, self::URL_PT_GUIDE, 'high', ref: 'R44_TAX_RESEARCH.md 1, 3.2; '.$guide, note: 'Taxable income up to R1m: penalty if the estimate is under 90 percent of actual and under the basic amount.');
        $this->rule('PROVISIONAL', 'pt.estimate_threshold_cents', 'money_cents', 100_000_000, self::EARLIEST, null, self::URL_PT_GUIDE, 'high', unit: 'ZAR', ref: 'R44_TAX_RESEARCH.md 3.2; '.$guide, note: 'R1m taxable income, the dividing line between the 80 and the 90 percent rule.');
        $this->rule('PROVISIONAL', 'pt.basic_amount_uplift_bp', 'basis_points', 800, self::EARLIEST, null, self::URL_PT_GUIDE, 'high', ref: 'R44_TAX_RESEARCH.md 1, 3.2; '.$guide, note: 'The basic amount is increased by 8 percent a year when the estimate is made more than 18 months after the end of the last assessed year.');
        $this->rule('PROVISIONAL', 'pt.deemed_nil_estimate_months', 'integer', 4, self::EARLIEST, null, self::URL_PT_GUIDE, 'high', unit: 'months', ref: 'R44_TAX_RESEARCH.md 3.2; '.$guide, note: 'No final estimate within 4 months of year end: the taxpayer is deemed to have estimated nil.');
        $this->rule('INCOME_TAX', 'itr14.due_months_after_year_end', 'integer', 12, self::EARLIEST, null, self::URL_CALENDAR, 'high', unit: 'months', ref: 'R44_TAX_RESEARCH.md 1, 5', note: 'Companies file the ITR14 within 12 months of the financial year end.');
    }

    // ── employment taxes ────────────────────────────────────────────────

    private function employmentTaxes(): void
    {
        $this->rule('EMP201', 'emp201.due_date', 'json', ['anchor' => 'month_end', 'offset_months' => 1, 'day' => 7, 'convention' => 'previous_business_day'], self::EARLIEST, null, self::URL_CALENDAR, 'high',
            ref: 'R44_TAX_RESEARCH.md 1, 4.1, 5', note: 'PAYE, SDL, UIF and ETI are paid and declared with the monthly EMP201 by the 7th of the following month; the last business day before it if the 7th is a weekend or public holiday.');
        $this->rule('EMP201', 'emp201.late_penalty_bp', 'basis_points', 1000, self::EARLIEST, null, self::URL_CALENDAR, 'high', ref: 'R44_TAX_RESEARCH.md 4.1', note: 'Late payment: 10 percent penalty plus interest.');

        $this->rule('UIF', 'uif.rate_employee_bp', 'basis_points', 100, self::EARLIEST, null, self::URL_UIF, 'high', ref: 'R44_TAX_RESEARCH.md 1, 4.2', note: 'Employee contribution, 1 percent of remuneration up to the ceiling.');
        $this->rule('UIF', 'uif.rate_employer_bp', 'basis_points', 100, self::EARLIEST, null, self::URL_UIF, 'high', ref: 'R44_TAX_RESEARCH.md 1, 4.2', note: 'Employer contribution, 1 percent of remuneration up to the ceiling.');
        $this->rule('UIF', 'uif.ceiling_monthly_cents', 'money_cents', 1_771_200, '2021-06-01', null, self::URL_UIF, 'high', unit: 'ZAR per month', ref: 'R44_TAX_RESEARCH.md 1, 4.2, 9 (item 4)', note: 'Remuneration ceiling R17,712 a month (R212,544 a year) since 1 June 2021. Some calculator sites claim a 2026 increase; no official evidence was found. On the watch list.');
        $this->rule('UIF', 'uif.max_per_party_monthly_cents', 'money_cents', 17_712, '2021-06-01', null, self::URL_UIF, 'high', unit: 'ZAR per month', ref: 'R44_TAX_RESEARCH.md 1, 4.2', note: 'Maximum contribution R177.12 per party a month (R354.24 combined).');
        $this->rule('SDL', 'sdl.rate_bp', 'basis_points', 100, self::EARLIEST, null, self::URL_TAX_GUIDE, 'high', ref: 'R44_TAX_RESEARCH.md 1, 4.2', note: '1 percent of total remuneration.');
        $this->rule('SDL', 'sdl.payroll_exemption_threshold_cents', 'money_cents', 50_000_000, self::EARLIEST, null, self::URL_TAX_GUIDE, 'high', unit: 'ZAR per year', ref: 'R44_TAX_RESEARCH.md 1, 4.2', note: 'Employers with an annual payroll under R500,000 are exempt.');

        $eti = 'Employment Tax Incentive: ages 18 to 29; ends 28 February 2029. Enacted retrospectively by Act 3 of 2026 (ETI Guide Issue 5, 27 Jul 2026). ';
        foreach ([
            'eti.max_first_12_months_cents' => [150_000, 'Up to R1,500 a month for the first 12 months of an employee earning R2,500 to R5,499.'],
            'eti.max_second_12_months_cents' => [75_000, 'Up to R750 a month for the second 12 months.'],
            'eti.remuneration_floor_cents' => [250_000, 'Minimum remuneration R2,500 a month (a minimum-wage test apportioned over 160 hours).'],
            'eti.remuneration_cap_cents' => [750_000, 'Remuneration must be under R7,500 a month.'],
            'eti.full_rate_band_top_cents' => [549_900, 'The full monthly amount applies to remuneration R2,500 to R5,499; the incentive tapers from R5,500 to R7,499. The taper formula is not in the research and is not modelled.'],
        ] as $key => [$value, $note]) {
            $this->rule('ETI', $key, 'money_cents', $value, '2025-04-01', '2029-02-28', self::URL_ETI, 'high', unit: 'ZAR per month', ref: 'R44_TAX_RESEARCH.md 1, 4.2; SARS ETI Guide Issue 5', note: $eti.$note);
        }
        $this->rule('ETI', 'eti.age_range', 'json', ['min' => 18, 'max' => 29], '2025-04-01', '2029-02-28', self::URL_ETI, 'high', ref: 'R44_TAX_RESEARCH.md 1, 4.2', note: $eti.'Eligible ages.');
        $this->rule('ETI', 'eti.max_months', 'integer', 24, '2025-04-01', '2029-02-28', self::URL_ETI, 'high', unit: 'months', ref: 'R44_TAX_RESEARCH.md 1, 4.2', note: $eti.'Maximum 24 months per employee.');
        $this->rule('EMPLOYMENT', 'employment.minimum_wage_hourly_cents', 'money_cents', 3_023, '2026-03-02', null, self::URL_BUDGET_FAQ, 'medium', unit: 'ZAR per hour', ref: 'R44_TAX_RESEARCH.md 1, 4.2; Budget 2026 FAQs', note: 'National minimum wage R30.23 an hour from 2 March 2026 (previously R28.79).');

        $this->rule('EMP501', 'emp501.interim_2026', 'json', ['period_start' => '2026-03-01', 'period_end' => '2026-08-31', 'window_start' => '2026-09-21', 'window_end' => '2026-10-31'], '2026-03-01', '2026-10-31', self::URL_EMP501, 'high',
            ref: 'R44_TAX_RESEARCH.md 1, 4.1', note: 'Interim reconciliation for 1 March to 31 August 2026, submission window 21 September to 31 October 2026.');
        $this->rule('EMP501', 'emp501.annual_window', 'json', ['period' => '1 March to end February', 'window_start_md' => '04-01', 'window_end_md' => '05-31'], self::EARLIEST, null, self::URL_CALENDAR, 'medium',
            ref: 'R44_TAX_RESEARCH.md 1, 4.1, 9 (item 3)', note: 'Annual reconciliation, submission 1 April to 31 May. The 2027 window dates are not yet published; this is inferred from the standing calendar. Confirm when SARS publishes the 2027 notice.');
        $this->rule('EMP501', 'emp501.late_penalty', 'json', ['base_pct_bp' => 100, 'per_month_bp' => 100, 'cap_bp' => 1000], self::EARLIEST, null, self::URL_EMP501, 'low',
            ref: 'R44_TAX_RESEARCH.md 4.1', note: 'Late EMP501: 1 percent of annual PAYE plus 1 percent per month outstanding, capped at 10 percent. The researcher noted the quoted SARS sentence looked incomplete. Verify.');

        $this->rule('COIDA', 'coida.max_earnings_cents', 'money_cents', 66_800_000, '2026-03-01', '2027-02-28', self::URL_COIDA, 'medium', unit: 'ZAR per employee', ref: 'R44_TAX_RESEARCH.md 1, 4.3 (GN 3910, GG 54577)', note: 'Maximum earnings per employee R668,000 (secondary sources). COIDA is not a SARS tax; a reminder and an estimate only.');
        $this->rule('COIDA', 'coida.min_assessment_cents', 'money_cents', 162_100, '2026-03-01', '2027-02-28', self::URL_COIDA, 'medium', unit: 'ZAR', ref: 'R44_TAX_RESEARCH.md 4.3', note: 'Minimum assessment R1,621.');
        $this->rule('COIDA', 'coida.min_assessment_domestic_cents', 'money_cents', 56_000, '2026-03-01', '2027-02-28', self::URL_COIDA, 'medium', unit: 'ZAR', ref: 'R44_TAX_RESEARCH.md 4.3', note: 'Minimum assessment for domestic employers R560.');
    }

    // ── interest and records ────────────────────────────────────────────

    private function interestAndRecords(): void
    {
        $this->rule('INTEREST', 'sars.interest_rate_bp', 'basis_points', 1025, '2025-12-01', null, self::URL_TAX_GUIDE, 'high',
            ref: 'R44_TAX_RESEARCH.md 1, 3.2, 9 (item 7); Budget 2026 Tax Guide; Provisional Tax guide rev 28', note: '10.25 percent a year on late or underpaid tax, late VAT and customs, from 1 December 2025 (reconfirmed from 1 March 2026). The prescribed rate is gazetted and moves with the repo rate: it is a dated rule on the watch list. Earlier rates are not in the research and are not seeded.');
        $this->rule('INTEREST', 'sars.interest_rate_refund_bp', 'basis_points', 625, '2026-03-01', null, self::URL_TAX_GUIDE, 'high', ref: 'R44_TAX_RESEARCH.md 1; Budget 2026 Tax Guide', note: '6.25 percent a year on overpaid provisional tax (refund).');
        $this->rule('RECORDS', 'records.retention_years.tax', 'integer', 5, self::EARLIEST, null, self::URL_TAA_S29, 'high', unit: 'years', ref: 'R44_TAX_RESEARCH.md 1, 5 (Tax Administration Act s29; VAT Act)', note: 'Tax and VAT records are kept for 5 years.');
        $this->rule('RECORDS', 'records.retention_years.company', 'integer', 7, self::EARLIEST, null, self::URL_TAA_S29, 'medium', unit: 'years', ref: 'R44_TAX_RESEARCH.md 5 (Companies Act s24, secondary sources)', note: 'Company records are kept for 7 years under the Companies Act; secondary sources.');
    }

    // ── row helpers ─────────────────────────────────────────────────────

    private function rule(string $type, string $key, string $valueType, mixed $value, string $from, ?string $to, string $url, string $confidence, string $qualifier = '', ?string $unit = null, string $status = 'enacted', ?string $ref = null, ?string $note = null): void
    {
        $exists = TaxRule::globalOnly()
            ->where('jurisdiction', self::J)->where('rule_key', $key)->where('qualifier', $qualifier)->where('valid_from', $from)->exists();

        if ($exists) {
            $this->skipped++;

            return;
        }

        TaxRule::query()->create([
            'jurisdiction' => self::J, 'tax_type' => $type, 'rule_key' => $key, 'qualifier' => $qualifier, 'value_type' => $valueType,
            'value' => $value, 'unit' => $unit, 'valid_from' => $from, 'valid_to' => $to, 'legal_status' => $status,
            'source_url' => $url, 'source_ref' => $ref, 'accessed_at' => self::ACCESSED, 'confidence' => $confidence, 'note' => $note,
        ]);
        $this->created++;
    }

    private function rate(string $key, int $bp, string $from, ?string $to, string $url, string $confidence, ?string $ref, ?string $note): void
    {
        $exists = TaxRate::globalOnly()
            ->where('jurisdiction', self::J)->where('rate_key', $key)->where('valid_from', $from)->exists();

        if ($exists) {
            $this->skipped++;

            return;
        }

        TaxRate::query()->create([
            'jurisdiction' => self::J, 'rate_key' => $key, 'rate_bp' => $bp, 'valid_from' => $from, 'valid_to' => $to,
            'legal_status' => 'enacted', 'source_url' => $url, 'source_ref' => $ref, 'accessed_at' => self::ACCESSED,
            'confidence' => $confidence, 'note' => $note,
        ]);
        $this->created++;
    }

    private function code(string $code, string $name, string $description, string $kind, string $appliesTo, ?string $rateRef, ?string $boxOutput, ?string $boxInput, string $policy, int $sort): void
    {
        $exists = TaxCode::globalOnly()
            ->where('jurisdiction', self::J)->where('code', $code)->where('valid_from', '2018-04-01')->exists();

        if ($exists) {
            $this->skipped++;

            return;
        }

        TaxCode::query()->create([
            'jurisdiction' => self::J, 'code' => $code, 'name' => $name, 'description' => $description, 'kind' => $kind,
            'applies_to' => $appliesTo, 'rate_ref' => $rateRef, 'box_output' => $boxOutput, 'box_input' => $boxInput,
            'deductible_policy' => $policy, 'valid_from' => '2018-04-01', 'is_active' => true, 'sort_order' => $sort,
        ]);
        $this->created++;
    }
}
