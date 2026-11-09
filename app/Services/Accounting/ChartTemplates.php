<?php

namespace App\Services\Accounting;

use InvalidArgumentException;

/**
 * The starting charts of accounts. Both contain every system key the posting engine looks up
 * (docs/R44_ARCHITECTURE.md section 4); a template never omits one. The personal chart marks the
 * business-only ones inactive so they are present but out of the way.
 *
 * Row: [code, name, class, subtype, system_key, options] where options may hold
 * 'active' => bool, 'postable' => bool, 'normal' => 'debit'|'credit' (override, for contra accounts),
 * 'parent' => code. The account codes follow a conventional 4-digit scheme (1xxx assets, 2xxx
 * liabilities, 3xxx equity, 4xxx income, 5xxx cost of sales, 6xxx and 7xxx expenses).
 */
final class ChartTemplates
{
    public const COMPANY = 'za_company';

    public const PERSONAL = 'personal';

    /** Every system key, in the order the architecture lists them. */
    public const SYSTEM_KEYS = [
        'bank', 'cash', 'ar', 'ap', 'vat_output', 'vat_input', 'vat_control', 'income_tax_payable', 'paye_payable',
        'uif_payable', 'sdl_payable', 'salaries_expense', 'employer_uif_expense', 'employer_sdl_expense', 'sales',
        'other_income', 'cost_of_sales', 'opening_balance_equity', 'retained_earnings', 'current_year_earnings',
        'fx_realised', 'fx_unrealised', 'rounding', 'suspense', 'uncategorised_income', 'uncategorised_expense',
        'bad_debts', 'bank_charges', 'depreciation', 'accumulated_depreciation', 'loans_payable', 'deferred_tax', 'prepayments',
    ];

    /** Where per-account and per-category GL accounts are created: [first code, last code]. */
    public const RANGES = [
        'bank' => [1010, 1099],
        'investment' => [1400, 1499],
        'credit' => [2150, 2199],
        'income' => [4100, 4499],
        'expense' => [6500, 6899],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return [self::COMPANY, self::PERSONAL];
    }

    public static function label(string $template): string
    {
        return match ($template) {
            self::COMPANY => 'South African small company',
            self::PERSONAL => 'Personal',
            default => throw new InvalidArgumentException("Unknown chart template: {$template}"),
        };
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: ?string, 4: ?string, 5: array<string, mixed>}> */
    public static function rows(string $template): array
    {
        return match ($template) {
            self::COMPANY => self::company(),
            self::PERSONAL => self::personal(),
            default => throw new InvalidArgumentException("Unknown chart template: {$template}"),
        };
    }

    private static function company(): array
    {
        return [
            // Assets
            ['1000', 'Cash on hand', 'asset', 'cash', 'cash', []],
            ['1010', 'Bank: main account', 'asset', 'bank', 'bank', []],
            ['1100', 'Accounts receivable', 'asset', 'receivable', 'ar', []],
            ['1200', 'VAT input (claimable)', 'asset', 'tax_receivable', 'vat_input', []],
            ['1300', 'Prepayments', 'asset', 'prepayment', 'prepayments', []],
            ['1350', 'Inventory', 'asset', 'inventory', null, []],
            ['1500', 'Equipment and vehicles (cost)', 'asset', 'fixed_asset', null, []],
            ['1510', 'Accumulated depreciation', 'asset', 'accumulated_depreciation', 'accumulated_depreciation', ['normal' => 'credit']],
            ['1990', 'Suspense', 'asset', 'suspense', 'suspense', []],
            // Liabilities
            ['2000', 'Accounts payable', 'liability', 'payable', 'ap', []],
            ['2100', 'VAT output (payable)', 'liability', 'tax_payable', 'vat_output', []],
            ['2110', 'VAT control (net payable to SARS)', 'liability', 'tax_payable', 'vat_control', []],
            ['2200', 'PAYE payable', 'liability', 'tax_payable', 'paye_payable', []],
            ['2210', 'UIF payable', 'liability', 'tax_payable', 'uif_payable', []],
            ['2220', 'SDL payable', 'liability', 'tax_payable', 'sdl_payable', []],
            ['2300', 'Income tax payable', 'liability', 'tax_payable', 'income_tax_payable', []],
            ['2400', 'Loans payable', 'liability', 'loan', 'loans_payable', []],
            ['2500', 'Deferred tax', 'liability', 'deferred_tax', 'deferred_tax', []],
            // Equity
            ['3000', 'Share capital', 'equity', 'capital', null, []],
            ['3100', 'Retained earnings', 'equity', 'retained_earnings', 'retained_earnings', []],
            ['3200', 'Current year earnings', 'equity', 'current_year_earnings', 'current_year_earnings', ['postable' => false]],
            ['3900', 'Opening balance equity', 'equity', 'opening_balance_equity', 'opening_balance_equity', []],
            // Income
            ['4000', 'Sales', 'income', 'sales', 'sales', []],
            ['4800', 'Other income', 'income', 'other_income', 'other_income', []],
            ['4900', 'Uncategorised income', 'income', 'uncategorised', 'uncategorised_income', []],
            // Cost of sales
            ['5000', 'Cost of sales', 'expense', 'cost_of_sales', 'cost_of_sales', []],
            // Expenses
            ['6000', 'Salaries and wages', 'expense', 'payroll', 'salaries_expense', []],
            ['6010', 'Employer UIF contribution', 'expense', 'payroll', 'employer_uif_expense', []],
            ['6020', 'Employer SDL contribution', 'expense', 'payroll', 'employer_sdl_expense', []],
            ['6100', 'Rent', 'expense', null, null, []],
            ['6110', 'Water, electricity and utilities', 'expense', null, null, []],
            ['6120', 'Telephone and internet', 'expense', null, null, []],
            ['6130', 'Insurance', 'expense', null, null, []],
            ['6140', 'Accounting and legal fees', 'expense', null, null, []],
            ['6150', 'Advertising and marketing', 'expense', null, null, []],
            ['6160', 'Repairs and maintenance', 'expense', null, null, []],
            ['6170', 'Motor vehicle and fuel', 'expense', null, null, []],
            ['6180', 'Travel and accommodation', 'expense', null, null, []],
            ['6190', 'Stationery and office costs', 'expense', null, null, []],
            ['6195', 'Software and subscriptions', 'expense', null, null, []],
            ['6200', 'Bank charges', 'expense', 'bank_charges', 'bank_charges', []],
            ['6210', 'Interest paid', 'expense', 'interest', null, []],
            ['6300', 'Depreciation', 'expense', 'depreciation', 'depreciation', []],
            ['6400', 'Bad debts', 'expense', 'bad_debts', 'bad_debts', []],
            ['6900', 'Uncategorised expense', 'expense', 'uncategorised', 'uncategorised_expense', []],
            ['7900', 'Realised foreign exchange (gain) or loss', 'expense', 'fx_gain_loss', 'fx_realised', []],
            ['7910', 'Unrealised foreign exchange (gain) or loss', 'expense', 'fx_gain_loss', 'fx_unrealised', []],
            ['7990', 'Rounding differences', 'expense', 'rounding', 'rounding', []],
        ];
    }

    private static function personal(): array
    {
        $off = ['active' => false];

        return [
            ['1000', 'Cash', 'asset', 'cash', 'cash', []],
            ['1010', 'Current account', 'asset', 'bank', 'bank', []],
            ['1100', 'Money owed to me', 'asset', 'receivable', 'ar', $off],
            ['1200', 'VAT input', 'asset', 'tax_receivable', 'vat_input', $off],
            ['1300', 'Prepayments', 'asset', 'prepayment', 'prepayments', $off],
            ['1510', 'Accumulated depreciation', 'asset', 'accumulated_depreciation', 'accumulated_depreciation', ['normal' => 'credit'] + $off],
            ['1990', 'Suspense', 'asset', 'suspense', 'suspense', []],
            ['2000', 'Money I owe', 'liability', 'payable', 'ap', $off],
            ['2100', 'VAT output', 'liability', 'tax_payable', 'vat_output', $off],
            ['2110', 'VAT control', 'liability', 'tax_payable', 'vat_control', $off],
            ['2200', 'PAYE payable', 'liability', 'tax_payable', 'paye_payable', $off],
            ['2210', 'UIF payable', 'liability', 'tax_payable', 'uif_payable', $off],
            ['2220', 'SDL payable', 'liability', 'tax_payable', 'sdl_payable', $off],
            ['2300', 'Income tax payable', 'liability', 'tax_payable', 'income_tax_payable', []],
            ['2400', 'Loans', 'liability', 'loan', 'loans_payable', []],
            ['2500', 'Deferred tax', 'liability', 'deferred_tax', 'deferred_tax', $off],
            ['3100', 'Accumulated surplus', 'equity', 'retained_earnings', 'retained_earnings', []],
            ['3200', 'Current year surplus', 'equity', 'current_year_earnings', 'current_year_earnings', ['postable' => false]],
            ['3900', 'Opening balance equity', 'equity', 'opening_balance_equity', 'opening_balance_equity', []],
            ['4000', 'Income', 'income', 'sales', 'sales', []],
            ['4800', 'Other income', 'income', 'other_income', 'other_income', []],
            ['4900', 'Uncategorised income', 'income', 'uncategorised', 'uncategorised_income', []],
            ['5000', 'Cost of sales', 'expense', 'cost_of_sales', 'cost_of_sales', $off],
            ['6000', 'Salaries and wages', 'expense', 'payroll', 'salaries_expense', $off],
            ['6010', 'Employer UIF contribution', 'expense', 'payroll', 'employer_uif_expense', $off],
            ['6020', 'Employer SDL contribution', 'expense', 'payroll', 'employer_sdl_expense', $off],
            ['6200', 'Bank charges', 'expense', 'bank_charges', 'bank_charges', []],
            ['6300', 'Depreciation', 'expense', 'depreciation', 'depreciation', $off],
            ['6400', 'Bad debts', 'expense', 'bad_debts', 'bad_debts', $off],
            ['6900', 'Uncategorised expense', 'expense', 'uncategorised', 'uncategorised_expense', []],
            ['7900', 'Realised foreign exchange (gain) or loss', 'expense', 'fx_gain_loss', 'fx_realised', []],
            ['7910', 'Unrealised foreign exchange (gain) or loss', 'expense', 'fx_gain_loss', 'fx_unrealised', []],
            ['7990', 'Rounding differences', 'expense', 'rounding', 'rounding', []],
        ];
    }
}
