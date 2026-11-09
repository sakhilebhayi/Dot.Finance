<?php

namespace App\Services\Tax;

/**
 * "Worth reviewing" prompts attached to expense categories, from R44_TAX_RESEARCH.md section 6. They are NOT advice and decide
 * nothing: each says what the rule is, where it comes from, and asks a person to check. Matching is on the account or
 * category NAME (case-insensitive keywords), which is a heuristic: a miss or a false hit is possible, and that is why the
 * result is a prompt for review, labelled Suggestion.
 *
 * weight: likely  = the research says this category is often not deductible, worth adding back unless you have a reason
 *         check   = depends on facts only a person knows
 */
final class ReviewPrompts
{
    /** @return list<array{key: string, label: string, weight: string, keywords: list<string>, income_tax: string, vat: ?string, refs: string, suggested_kind: string}> */
    public static function all(): array
    {
        return [
            ['key' => 'entertainment', 'label' => 'Entertainment and staff functions', 'weight' => 'likely', 'keywords' => ['entertain', 'hospitality', 'staff function', 'staff party', 'year-end function', 'canteen', 'client lunch', 'golf'],
                'income_tax' => 'Entertainment is often not deductible (section 23(b)), with exceptions such as entertainment provided to the public for a charge or amounts taxed to the employee as a benefit. Review.',
                'vat' => 'Input VAT on entertainment is usually denied (section 17(2)(a)); exceptions include overnight business subsistence and seminar meals in the ticket price.',
                'refs' => 'Income Tax Act s23(b); VAT Act s17(2)(a). R44_TAX_RESEARCH.md section 6', 'suggested_kind' => 'add_back'],
            ['key' => 'fines', 'label' => 'Fines, penalties and unlawful payments', 'weight' => 'likely', 'keywords' => ['fine', 'penalt', 'traffic', 'bribe', 'kickback'],
                'income_tax' => 'Amounts arising from an unlawful act are not deductible (section 23(o)); SARS penalties and interest are not deductible (section 23(d)).',
                'vat' => 'No VAT on fines (not a supply).', 'refs' => 'Income Tax Act s23(o), s23(d); SARS Interpretation Note 54. R44_TAX_RESEARCH.md section 6', 'suggested_kind' => 'add_back'],
            ['key' => 'sars_interest', 'label' => 'SARS interest and penalties', 'weight' => 'likely', 'keywords' => ['sars interest', 'interest on tax', 'provisional tax interest', 'sars penalty', 'late payment'],
                'income_tax' => 'SARS interest and penalties, and interest on a provisional tax underpayment, are not deductible.', 'vat' => null,
                'refs' => 'Income Tax Act s23(d); SARS provisional tax guide. R44_TAX_RESEARCH.md section 6', 'suggested_kind' => 'add_back'],
            ['key' => 'private', 'label' => 'Private or domestic expenses, home office', 'weight' => 'likely', 'keywords' => ['private', 'personal', 'drawings', 'domestic', 'home office'],
                'income_tax' => 'Private expenses are not deductible (section 23(a)). A home-office deduction needs a specifically equipped room regularly and exclusively used for the trade.',
                'vat' => 'Claim only the business portion.', 'refs' => 'Income Tax Act s23(a), s11(a), s23(b). R44_TAX_RESEARCH.md section 6', 'suggested_kind' => 'add_back'],
            ['key' => 'depreciation', 'label' => 'Accounting depreciation', 'weight' => 'likely', 'keywords' => ['depreciation', 'amortisation', 'amortization'],
                'income_tax' => 'Accounting depreciation is added back; the tax allowance (wear and tear, section 11(e) and others) is claimed instead. Enter that allowance as an additional deduction.',
                'vat' => null, 'refs' => 'Income Tax Act s11(e); SARS Interpretation Note 47. R44_TAX_RESEARCH.md sections 3.3 and 6', 'suggested_kind' => 'add_back'],
            ['key' => 'clubs', 'label' => 'Club memberships', 'weight' => 'likely', 'keywords' => ['club', 'membership fee', 'country club'],
                'income_tax' => 'Often private. Review.', 'vat' => 'Input VAT on club subscriptions is denied.', 'refs' => 'VAT Act s17(2). R44_TAX_RESEARCH.md section 6', 'suggested_kind' => 'add_back'],
            ['key' => 'donations', 'label' => 'Donations', 'weight' => 'check', 'keywords' => ['donation', 'charity', 'sponsorship'],
                'income_tax' => 'Deductible only for approved public benefit organisations (section 18A) with a receipt, up to 10 percent of taxable income.',
                'vat' => 'Not VAT supplies.', 'refs' => 'Income Tax Act s18A. R44_TAX_RESEARCH.md section 6', 'suggested_kind' => 'add_back'],
            ['key' => 'gifts', 'label' => 'Gifts to clients', 'weight' => 'check', 'keywords' => ['gift', 'client gift'],
                'income_tax' => 'Needs a link to income (section 11(a)). Food and drink is entertainment.', 'vat' => 'Gifts are deemed supplies if input tax was claimed.',
                'refs' => 'Income Tax Act s11(a). R44_TAX_RESEARCH.md section 6', 'suggested_kind' => 'add_back'],
            ['key' => 'motor', 'label' => 'Motor vehicles', 'weight' => 'check', 'keywords' => ['motor', 'vehicle', 'fuel', 'car hire', 'car lease', 'vehicle lease'],
                'income_tax' => 'Wear and tear (section 11(e)) or a lease deduction subject to value caps and a logbook; the company-car fringe benefit applies to the employee.',
                'vat' => 'Input VAT on buying a passenger motor car is denied; running costs are claimable on a business-use basis.', 'refs' => 'Income Tax Act s11(e); VAT Act s17(2). R44_TAX_RESEARCH.md section 6', 'suggested_kind' => 'add_back'],
            ['key' => 'travel', 'label' => 'Travel and subsistence', 'weight' => 'check', 'keywords' => ['travel', 'subsistence', 'accommodation'],
                'income_tax' => 'Reimbursements within the SARS rates are not taxed to the employee; the employer deduction needs proof.',
                'vat' => 'Subsistence is claimable only for the overnight-business exception, not as an allowance.', 'refs' => 'Budget 2026 Tax Guide. R44_TAX_RESEARCH.md section 6', 'suggested_kind' => 'add_back'],
            ['key' => 'capital', 'label' => 'Capital equipment expensed', 'weight' => 'check', 'keywords' => ['equipment', 'furniture', 'computer equipment', 'machinery'],
                'income_tax' => 'Capital items are not deducted at once: wear and tear (section 11(e)), section 12B or 12E apply. Enter the allowance as an additional deduction and add back the expense.',
                'vat' => 'Capital goods input tax is claimed in field 14 when the invoice is held.', 'refs' => 'Income Tax Act s11(e), s12B, s12E. R44_TAX_RESEARCH.md section 6', 'suggested_kind' => 'add_back'],
            ['key' => 'interest', 'label' => 'Interest paid', 'weight' => 'check', 'keywords' => ['interest paid', 'loan interest', 'finance charge'],
                'income_tax' => 'Generally deductible if the loan is used in the business; sections 24J, 23M, 23N and 11G can limit it (related-party and acquisition debt).',
                'vat' => 'Financial services are exempt: no input VAT.', 'refs' => 'Income Tax Act s24J, s23M, s23N, s11G. R44_TAX_RESEARCH.md section 6', 'suggested_kind' => 'add_back'],
            ['key' => 'medical', 'label' => 'Medical aid and group risk', 'weight' => 'check', 'keywords' => ['medical aid', 'medical scheme', 'group risk'],
                'income_tax' => 'An employer contribution is a taxable fringe benefit for the employee and an employment cost for the employer.', 'vat' => 'Input VAT on medical scheme cover is denied.',
                'refs' => 'VAT Act s17(2). R44_TAX_RESEARCH.md section 6', 'suggested_kind' => 'add_back'],
            ['key' => 'bad_debts', 'label' => 'Bad and doubtful debts', 'weight' => 'check', 'keywords' => ['bad debt', 'doubtful debt', 'impairment'],
                'income_tax' => 'Specific bad debts (section 11(i)) and a limited doubtful-debt allowance (section 11(j)). A general provision is not deductible.',
                'vat' => 'Output and input adjustments via fields 12 and 17.', 'refs' => 'Income Tax Act s11(i), s11(j). R44_TAX_RESEARCH.md section 6', 'suggested_kind' => 'add_back'],
            ['key' => 'preincorporation', 'label' => 'Pre-incorporation expenses', 'weight' => 'check', 'keywords' => ['pre-incorporation', 'preincorporation', 'pre incorporation'],
                'income_tax' => 'Specific rules apply (section 11A).', 'vat' => 'VAT 404 section 8.7.', 'refs' => 'Income Tax Act s11A. R44_TAX_RESEARCH.md section 6', 'suggested_kind' => 'add_back'],
        ];
    }

    /** @return list<array<string, mixed>> the prompts whose keywords appear in the name */
    public static function match(string $name): array
    {
        $needle = mb_strtolower($name);
        $hits = [];
        foreach (self::all() as $prompt) {
            foreach ($prompt['keywords'] as $keyword) {
                if (str_contains($needle, $keyword)) {
                    $hits[] = $prompt;
                    break;
                }
            }
        }

        return $hits;
    }
}
