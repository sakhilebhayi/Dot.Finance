<?php

namespace Tests\Feature\Acceptance;

use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Category;
use App\Models\Purchases\Bill;
use App\Models\Transaction;
use App\Services\Purchases\CategorySuggester;
use Illuminate\Testing\TestResponse;

/**
 * Owner criterion 4: expenses are recorded and categorised. Cash expenses go through the transaction form, supplier costs through
 * the bill form; both end in the right expense account, categorised, in the right period, and in the expense report.
 */
class Acceptance04_ExpensesTest extends AcceptanceCase
{
    private Category $fuel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntegration();
        $this->asOwner()->post(route('categories.store'), ['name' => 'Fuel', 'type' => 'expense'])->assertSessionHasNoErrors();
        $this->asOwner()->post(route('categories.store'), ['name' => 'Insurance', 'type' => 'expense'])->assertSessionHasNoErrors();
        $this->fuel = Category::query()->forContext($this->ledger)->where('name', 'Fuel')->firstOrFail();
    }

    private function expense(array $over = []): TestResponse
    {
        return $this->asOwner()->post(route('transactions.store'), $over + [
            'account_id' => $this->bank->id, 'category_id' => $this->fuel->id, 'description' => 'Diesel', 'amount' => '850.00', 'type' => 'expense', 'date' => '2026-11-12',
        ]);
    }

    public function test_a_cash_expense_posts_a_debit_to_its_category_account_and_a_credit_to_the_bank(): void
    {
        $this->expense()->assertSessionHasNoErrors();
        $tx = Transaction::query()->forContext($this->ledger)->where('description', 'Diesel')->firstOrFail();
        $this->assertSame(['expense', 85_000], [$tx->type, (int) $tx->amount_cents]);

        $entry = JournalEntry::query()->forContext($this->ledger)->where('source_type', 'transaction')->where('source_id', $tx->id)->firstOrFail();
        $lines = JournalLine::query()->where('journal_entry_id', $entry->id)->get();
        $this->assertSame(85_000, (int) $lines->firstWhere('gl_account_id', $this->fuel->fresh()->gl_account_id)->debit_cents, 'cost is a debit on the category account');
        $this->assertSame(85_000, (int) $lines->firstWhere('gl_account_id', $this->bank->fresh()->gl_account_id)->credit_cents, 'money out is a credit to the bank');
        $this->assertBooksVerify();
    }

    public function test_an_uncategorised_expense_is_parked_visibly_and_categorising_it_moves_it_in_the_books(): void
    {
        $this->expense(['category_id' => '', 'description' => 'Unknown card payment'])->assertSessionHasNoErrors();
        $tx = Transaction::query()->forContext($this->ledger)->where('description', 'Unknown card payment')->firstOrFail();
        $this->assertSame(85_000, $this->glDebits($this->systemId('uncategorised_expense')), 'parked in uncategorised expense, not lost');
        $this->asOwner()->get(route('transactions.index', ['uncategorised' => 1]))->assertOk()->assertSee('Unknown card payment');

        $this->asOwner()->put(route('transactions.update', $tx), [
            'account_id' => $this->bank->id, 'category_id' => $this->fuel->id, 'description' => 'Unknown card payment', 'amount' => '850.00', 'type' => 'expense', 'date' => '2026-11-12', 'version' => $tx->version,
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $this->glDebits($this->systemId('uncategorised_expense')) - $this->glCredits($this->systemId('uncategorised_expense')), 'the parked amount was reversed out');
        $this->assertSame(85_000, $this->glDebits($this->fuel->fresh()->gl_account_id) - $this->glCredits($this->fuel->fresh()->gl_account_id));
        $this->assertBooksVerify();
    }

    public function test_the_expense_report_and_profit_and_loss_group_by_category_and_agree(): void
    {
        $insurance = Category::query()->forContext($this->ledger)->where('name', 'Insurance')->firstOrFail();
        $this->expense()->assertSessionHasNoErrors();
        $this->expense(['description' => 'Diesel again', 'amount' => '150.00', 'date' => '2026-11-20'])->assertSessionHasNoErrors();
        $this->expense(['category_id' => $insurance->id, 'description' => 'Cover', 'amount' => '400.00'])->assertSessionHasNoErrors();

        $pnl = $this->report('profit-loss', ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertSame(140_000, $pnl->cell('Total expenses', 'total'));
        $this->assertReportChecksPass($pnl);

        $page = $this->asOwner()->get(route('reports.expenses', ['from' => '2026-11-01', 'to' => '2026-11-30']))->assertOk()->getContent();
        $this->assertStringContainsString('Fuel', $page);
        $this->assertStringContainsString('1,000.00', $page, 'fuel is 850 + 150');
        $this->assertStringContainsString('400.00', $page);
    }

    public function test_a_supplier_bill_is_categorised_by_line_and_the_wrong_kind_of_account_is_refused(): void
    {
        $lines = [
            ['description' => 'Printer paper', 'gl_account_id' => $this->gl('6190')->id, 'quantity' => '10', 'unit_price' => '100.00', 'tax_code' => 'ZA_VAT_STD'],
            ['description' => 'Tea and coffee', 'gl_account_id' => $this->gl('6170')->id, 'quantity' => '1', 'unit_price' => '300.00', 'tax_code' => 'ZA_VAT_STD'],
        ];
        $this->asOwner()->post(route('purchases.bills.store'), ['party_id' => $this->paperCo->id, 'bill_date' => '2026-11-14', 'supplier_reference' => 'PC-1', 'document_type' => 'tax_invoice', 'lines' => $lines, 'client_token' => 'a04-1', 'submit_now' => '1'])
            ->assertSessionHasNoErrors();
        $bill = Bill::query()->forContext($this->ledger)->where('supplier_reference', 'PC-1')->firstOrFail();
        $this->assertSame(['open', 149_500], [$bill->status, (int) $bill->total_cents], '1,300 net + 15% VAT, worked out by the server');
        $this->assertSame(100_000, $this->glDebits($this->gl('6190')->id));
        $this->assertSame(30_000, $this->glDebits($this->gl('6170')->id));
        $this->assertSame(19_500, $this->glDebits($this->gl('vat_input')->id), 'input VAT is its own account, not part of the cost');

        // a line pointed at a bank account is not an expense
        $this->from(route('purchases.bills.create'))->asOwner()->post(route('purchases.bills.store'), ['party_id' => $this->paperCo->id, 'bill_date' => '2026-11-14', 'supplier_reference' => 'PC-2', 'client_token' => 'a04-2',
            'lines' => [['description' => 'x', 'unit_price' => '10.00', 'gl_account_id' => $this->gl('1010')->id, 'tax_code' => 'ZA_VAT_STD']]])->assertSessionHasErrors('purchases');
        $this->assertNull(Bill::query()->forContext($this->ledger)->where('supplier_reference', 'PC-2')->first());
        $this->assertBooksVerify();
    }

    public function test_the_next_bill_for_the_same_supplier_is_offered_a_suggestion_but_nothing_is_applied_silently(): void
    {
        $this->asOwner()->post(route('purchases.bills.store'), ['party_id' => $this->paperCo->id, 'bill_date' => '2026-11-14', 'supplier_reference' => 'PC-5', 'document_type' => 'tax_invoice', 'client_token' => 'a04-5', 'submit_now' => '1',
            'lines' => [['description' => 'Printer paper', 'gl_account_id' => $this->gl('6190')->id, 'quantity' => '1', 'unit_price' => '100.00', 'tax_code' => 'ZA_VAT_STD']]])->assertSessionHasNoErrors();

        $suggestion = app(CategorySuggester::class)->forSupplier($this->ledger, $this->paperCo->id, 'Printer paper');
        $this->assertSame($this->gl('6190')->id, $suggestion['gl_account_id']);
        $this->assertSame('Suggestion', $suggestion['basis'], 'labelled as a suggestion');
    }

    public function test_a_bookkeeper_records_but_a_viewer_cannot_and_nobody_reaches_another_companys_category(): void
    {
        $viewer = $this->memberAs('viewer');
        $bookkeeper = $this->memberAs('bookkeeper');
        $this->asMember($viewer)->post(route('transactions.store'), ['account_id' => $this->bank->id, 'description' => 'x', 'amount' => '1.00', 'type' => 'expense', 'date' => '2026-11-10'])->assertForbidden();
        $this->asMember($bookkeeper)->post(route('transactions.store'), ['account_id' => $this->bank->id, 'category_id' => $this->fuel->id, 'description' => 'Bookkeeper entry', 'amount' => '5.00', 'type' => 'expense', 'date' => '2026-11-10'])->assertSessionHasNoErrors();
        $this->assertSame(1, Transaction::query()->forContext($this->ledger)->where('description', 'Bookkeeper entry')->count());

        $theirs = Category::factory()->forCompany($this->company($this->user(), 'Elsewhere'))->create(['type' => 'expense', 'name' => 'Their fuel']);
        $this->expense(['category_id' => $theirs->id, 'description' => 'Sneaky'])->assertSessionHasErrors('category_id');
        $this->assertSame(0, Transaction::query()->forContext($this->ledger)->where('description', 'Sneaky')->count());
    }

    private function glDebits(int $glId): int
    {
        return (int) JournalLine::query()->forContext($this->ledger)->where('gl_account_id', $glId)->sum('debit_cents');
    }

    private function glCredits(int $glId): int
    {
        return (int) JournalLine::query()->forContext($this->ledger)->where('gl_account_id', $glId)->sum('credit_cents');
    }
}
