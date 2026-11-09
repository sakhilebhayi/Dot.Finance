<?php

namespace App\Support;

/**
 * Plain-language labels and one-sentence explanations for the accounting and tax terms the app shows,
 * in South African English and South African context (rands, SARS, VAT). Every term has a label a
 * non-accountant would recognise and a single sentence that explains it without using another undefined
 * term. Screens ask for a term by key, so a figure can carry its explanation next to it.
 *
 * This is guidance wording, not tax advice.
 */
final class Glossary
{
    /** @var array<string, array{0: string, 1: string}>|null label and explanation by key */
    private static ?array $cache = null;

    /** @return array<string, array{label: string, explanation: string}> */
    public static function all(): array
    {
        return self::$cache ??= array_map(fn (array $t) => ['label' => $t[0], 'explanation' => $t[1]], self::terms());
    }

    public static function has(string $key): bool
    {
        return isset(self::terms()[$key]);
    }

    /** The name to show for a term; an unknown key shows itself, tidied, rather than breaking a page. */
    public static function label(string $key): string
    {
        return self::terms()[$key][0] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /** The one-sentence explanation, or an empty string for an unknown key. */
    public static function explain(string $key): string
    {
        return self::terms()[$key][1] ?? '';
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::terms());
    }

    /** @return array<string, array{0: string, 1: string}> */
    private static function terms(): array
    {
        return [
            // ── how the books work ─────────────────────────────────────
            'double_entry' => ['Double-entry bookkeeping', 'Every transaction is recorded twice, once where the money came from and once where it went, so the books always balance.'],
            'journal_entry' => ['Journal entry', 'A single record that moves value between accounts, with debits and credits that add up to the same amount.'],
            'general_ledger' => ['General ledger', 'The complete list of every journal entry, grouped by account, that all your reports are built from.'],
            'chart_of_accounts' => ['Chart of accounts', 'The list of accounts your business uses to sort money into assets, liabilities, equity, income and expenses.'],
            'trial_balance' => ['Trial balance', 'A list of every account with its balance, used to check that total debits equal total credits.'],
            'debit' => ['Debit', 'The left-hand side of an entry, which increases assets and expenses and decreases liabilities, equity and income.'],
            'credit' => ['Credit', 'The right-hand side of an entry, which increases liabilities, equity and income and decreases assets and expenses.'],
            'accrual_basis' => ['Accrual basis', 'Income and costs are counted when they are earned or incurred, not when the money moves.'],
            'cash_basis' => ['Cash basis', 'Income and costs are counted only when money actually comes in or goes out.'],
            'accounting_period' => ['Accounting period', 'A block of time, usually a month, that you report on and then lock once it is finished.'],
            'period_close' => ['Closing a period', 'Locking a finished period so nothing in it can be changed, which keeps your reports stable.'],
            'financial_year' => ['Financial year', 'The twelve months your business reports on, which ends on the year-end date you chose.'],
            'reversal_entry' => ['Reversing entry', 'A new entry that cancels an earlier one, used to correct a mistake without erasing history.'],
            'audit_trail' => ['Audit trail', 'A permanent record of who changed what and when, which cannot be edited afterwards.'],
            'drill_down' => ['Drill-down', 'Clicking a figure to see the transactions and documents that add up to it.'],
            'opening_balance' => ['Opening balance', 'The amount an account already held on the day you started recording in this system.'],
            'suspense_account' => ['Suspense account', 'A holding account for money you cannot classify yet, which should be cleared to zero once you know what it was.'],
            'rounding_difference' => ['Rounding difference', 'A difference of a cent or two that arises when amounts are rounded, kept in its own account so the books still balance.'],
            'dimension' => ['Department or project tag', 'A label on a transaction that lets you report income and costs by department or project.'],
            'reconciliation' => ['Reconciliation', 'Checking that two sets of records agree, and explaining any difference.'],
            'bank_reconciliation' => ['Bank reconciliation', 'Matching the transactions in your books to your bank statement so you know the books are right.'],
            'statement_balance' => ['Statement balance', 'The closing balance shown on the bank statement.'],
            'cleared_balance' => ['Cleared balance', 'The balance of only those transactions that have been matched to the bank statement.'],
            'unreconciled' => ['Not yet matched', 'A transaction in your books that has not been matched to a line on the bank statement.'],

            // ── what the reports show ──────────────────────────────────
            'balance_sheet' => ['Balance sheet', 'A snapshot on one date of what the business owns, what it owes and what is left for the owners.'],
            'income_statement' => ['Profit and loss', 'A report of the income earned and the costs incurred over a period, ending in profit or loss.'],
            'cash_flow_statement' => ['Cash flow statement', 'A report of the money that actually came in and went out over a period.'],
            'asset' => ['Asset', 'Something the business owns that has value, such as cash, money owed to you, stock or equipment.'],
            'liability' => ['Liability', 'Something the business owes to others, such as supplier bills, loans or tax due.'],
            'equity' => ['Equity', 'What is left for the owners after everything owed has been subtracted from everything owned.'],
            'retained_earnings' => ['Retained earnings', 'Profit from earlier years that has been kept in the business rather than paid out.'],
            'revenue' => ['Income', 'The money earned from selling goods or services, before any costs are taken off.'],
            'expense' => ['Expense', 'A cost of running the business, such as rent, salaries or fuel.'],
            'cost_of_sales' => ['Cost of sales', 'What it directly cost you to make or buy the goods or services you sold.'],
            'gross_profit' => ['Gross profit', 'Income minus the cost of sales, before running costs are taken off.'],
            'net_profit' => ['Net profit', 'What is left after all costs have been taken off income, before income tax.'],
            'working_capital' => ['Working capital', 'The cash and short-term assets you have left after paying what you owe in the near term.'],
            'depreciation' => ['Depreciation', 'Spreading the cost of an asset, such as a vehicle or computer, over the years you expect to use it.'],
            'accumulated_depreciation' => ['Accumulated depreciation', 'The total depreciation charged on an asset so far, which is subtracted from its cost.'],
            'prepayment' => ['Prepayment', 'Money paid in advance for something you will only use in a later period, such as annual insurance.'],
            'accrual' => ['Accrued cost', 'A cost that belongs to this period but has not been invoiced or paid yet.'],

            // ── customers, suppliers and documents ─────────────────────
            'accounts_receivable' => ['Money owed to you', 'What your customers still owe you for invoices you have sent.'],
            'accounts_payable' => ['Money you owe', 'What you still owe your suppliers for bills you have received.'],
            'ageing' => ['Ageing', 'A list of unpaid invoices or bills grouped by how long they have been outstanding.'],
            'customer_statement' => ['Customer statement', 'A summary sent to a customer showing their invoices, payments and the balance they owe.'],
            'quote' => ['Quote', 'An offer of a price for goods or services that the customer has not yet accepted.'],
            'pro_forma_invoice' => ['Pro forma invoice', 'A preview of an invoice sent before delivery or payment, which is not a tax invoice.'],
            'tax_invoice' => ['Tax invoice', 'The invoice a VAT vendor must issue that shows the VAT charged, which the buyer needs to claim the VAT back.'],
            'abridged_tax_invoice' => ['Short tax invoice', 'A simpler tax invoice allowed for sales under R5,000 that leaves out the buyer details.'],
            'credit_note' => ['Credit note', 'A document that reduces what a customer owes you, for example when goods are returned or a price was wrong.'],
            'debit_note' => ['Debit note', 'A document that increases what a customer owes you, for example when you under-charged on an invoice.'],
            'document_number' => ['Document number', 'The running number on an invoice or credit note, which must follow in order with no gaps.'],
            'payment_terms' => ['Payment terms', 'The number of days a customer or supplier has to pay after the invoice date.'],
            'credit_limit' => ['Credit limit', 'The most you are willing to let a customer owe you at any one time.'],
            'payment_allocation' => ['Allocating a payment', 'Applying money you received or paid to the specific invoice or bill it settles.'],
            'unallocated_payment' => ['Unallocated payment', 'Money received or paid that has not yet been applied to a specific invoice or bill.'],
            'bad_debt' => ['Bad debt', 'Money a customer owes you that you have decided you will never collect.'],
            'write_off' => ['Write-off', 'Removing an amount from your books because it will not be paid or is no longer worth anything.'],
            'attachment' => ['Supporting document', 'A receipt, invoice or contract stored with the record it proves.'],
            'extraction_draft' => ['Suggested details from a document', 'Figures read automatically from a document, which are only a suggestion until a person checks them.'],

            // ── currencies ─────────────────────────────────────────────
            'base_currency' => ['Reporting currency', 'The currency your books are kept in, usually the rand, which every other currency is converted into.'],
            'exchange_rate' => ['Exchange rate', 'How many rand, or other reporting currency, one unit of a foreign currency is worth on a given day.'],
            'realised_fx' => ['Realised exchange gain or loss', 'The rand difference between what a foreign amount was worth when booked and what it was worth when it was paid.'],
            'unrealised_fx' => ['Unrealised exchange gain or loss', 'The paper difference from re-valuing a foreign amount that is still outstanding at the reporting date.'],

            // ── VAT ────────────────────────────────────────────────────
            'vat' => ['Value-Added Tax (VAT)', 'A tax added to most goods and services that vendors collect from customers and pass on to SARS.'],
            'vat_vendor' => ['VAT vendor', 'A business registered with SARS to charge, collect and pay over VAT.'],
            'vat_registration' => ['VAT registration', 'Signing up with SARS as a vendor, which is compulsory once your taxable sales pass the threshold.'],
            'vat_threshold' => ['VAT registration threshold', 'The amount of taxable sales in twelve months above which a business must register for VAT.'],
            'output_tax' => ['VAT charged (output tax)', 'The VAT you add to your sales, which you owe to SARS.'],
            'input_tax' => ['VAT paid (input tax)', 'The VAT you pay on business purchases, which you can usually claim back from SARS.'],
            'vat_payable' => ['VAT payable or refundable', 'The VAT charged on sales minus the VAT paid on purchases, which is either owed to SARS or refunded by SARS.'],
            'standard_rated' => ['Standard-rated', 'Supplies that carry VAT at the standard rate, which covers most goods and services.'],
            'zero_rated' => ['Zero-rated', 'Supplies that are taxable at 0 percent, such as basic foods and exports, so no VAT is charged but VAT on costs can still be claimed.'],
            'exempt_supply' => ['Exempt', 'Supplies the law excludes from VAT, such as residential rent, so no VAT is charged and none can be claimed on the related costs.'],
            'out_of_scope' => ['Outside the scope of VAT', 'Money that is not a sale or purchase for VAT purposes, such as salaries, dividends and fines.'],
            'tax_point' => ['Tax point (time of supply)', 'The date that decides which VAT rate and which VAT period apply to a sale.'],
            'tax_period' => ['VAT period', 'The one- or two-month stretch that each VAT return covers, set by SARS.'],
            'vat201' => ['VAT201 return', 'The form on which a vendor declares VAT charged and VAT paid for a period and pays the difference to SARS.'],
            'prn' => ['Payment reference number (PRN)', 'The number SARS gives you for a return, which you must quote when you pay so the payment is matched.'],
            'reverse_charge' => ['VAT on imported services', 'VAT you must account for yourself when you buy services from a supplier outside South Africa.'],
            'capital_goods' => ['Capital goods', 'Assets such as equipment that you keep and use in the business rather than sell.'],
            'input_tax_denied' => ['VAT you cannot claim', 'VAT paid on items the law does not allow you to claim back, such as entertainment and most passenger cars.'],

            // ── income tax ─────────────────────────────────────────────
            'sars' => ['SARS', 'The South African Revenue Service, which collects tax and customs duties.'],
            'efiling' => ['SARS eFiling', 'SARS online service where returns are submitted and payments are made.'],
            'year_of_assessment' => ['Tax year (year of assessment)', 'The twelve months a tax return covers, which is March to February for individuals and the financial year for companies.'],
            'taxable_income' => ['Taxable income', 'Your income less the deductions and exemptions the law allows, which is the amount income tax is worked out on.'],
            'company_income_tax' => ['Company income tax', 'The tax a company pays on its taxable income, at a flat rate.'],
            'small_business_corporation' => ['Small business corporation', 'A small company owned by individuals that pays income tax on a sliding scale with a zero-rated first band.'],
            'turnover_tax' => ['Turnover tax', 'A simple tax for very small businesses that is worked out on total sales instead of profit.'],
            'provisional_tax' => ['Provisional tax', 'Income tax paid in instalments during the year, based on an estimate, instead of in one amount after year-end.'],
            'irp6' => ['IRP6 return', 'The form used to declare your estimate and pay each provisional tax instalment.'],
            'itr14' => ['ITR14 return', 'The annual income tax return that companies file with SARS.'],
            'dividends_tax' => ['Dividends tax', 'A tax withheld when a company pays a dividend to its shareholders.'],
            'assessed_loss' => ['Assessed loss', 'A tax loss that SARS has accepted and that can reduce your taxable income in later years.'],
            'wear_and_tear' => ['Wear-and-tear allowance', 'The tax deduction for the falling value of equipment and vehicles you use in the business.'],
            'interest_rate_sars' => ['SARS interest rate', 'The yearly rate SARS charges on tax paid late or underpaid, which changes from time to time.'],
            'admin_penalty' => ['Administrative penalty', 'A fixed monthly amount SARS charges when a return is not submitted on time.'],

            // ── employees ──────────────────────────────────────────────
            'paye' => ['PAYE', 'Pay-As-You-Earn, the income tax an employer deducts from an employee\'s pay and pays over to SARS.'],
            'uif' => ['UIF', 'The Unemployment Insurance Fund, to which both the employee and the employer contribute 1 percent of pay up to a ceiling.'],
            'sdl' => ['SDL', 'The Skills Development Levy, which an employer pays at 1 percent of its payroll to fund training.'],
            'eti' => ['Employment Tax Incentive', 'A reduction in the PAYE an employer pays for qualifying young employees on lower pay.'],
            'emp201' => ['EMP201 return', 'The monthly return on which an employer declares and pays PAYE, UIF and SDL by the seventh of the following month.'],
            'emp501' => ['EMP501 reconciliation', 'The twice-yearly check that the pay and tax in your employee certificates match what you paid to SARS.'],
            'coida' => ['COIDA', 'The Compensation for Occupational Injuries and Diseases Act, under which employers pay a yearly assessment to cover workplace injuries.'],

            // ── records and trust ──────────────────────────────────────
            'record_retention' => ['Keeping records', 'The law requires you to keep your tax and accounting records for five years, and company records for seven.'],
            'popia' => ['POPIA', 'The Protection of Personal Information Act, which limits how long and why you may keep personal details such as customer and employee data.'],
            'label_calculated' => ['Calculated', 'A figure the system worked out from your recorded data using the rules shown beside it.'],
            'label_estimate' => ['Estimate', 'A figure that looks ahead or relies on assumptions, so it may differ from what actually happens.'],
            'label_user_provided' => ['Entered by you', 'A figure that a person typed in and the system has not checked against anything else.'],
            'label_ai_suggestion' => ['AI suggestion', 'A suggestion produced by an AI model that nobody has confirmed yet and that you should check before relying on.'],
            'rule_confidence' => ['How sure we are', 'A rating of high, medium or low for how well a tax rule or figure is confirmed by an official source.'],
            'rule_version' => ['Rule version', 'The dated version of a tax rule that was in force when a figure was calculated, so it can be explained later.'],
            'tax_practitioner' => ['Registered tax practitioner', 'A professional registered with a recognised body who can give you tax advice, which this software does not.'],
        ];
    }
}
