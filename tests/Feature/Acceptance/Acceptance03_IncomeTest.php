<?php

namespace Tests\Feature\Acceptance;

use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Testing\TestResponse;

/**
 * Owner criterion 3: income is recorded correctly. A person records money in through the transaction form (cash ledger) and the
 * books follow: the right amount, the right side, the right account, the right period, one balanced entry, reflected in reports.
 */
class Acceptance03_IncomeTest extends AcceptanceCase
{
    private Category $consulting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntegration();
        $this->asOwner()->post(route('categories.store'), ['name' => 'Consulting fees', 'type' => 'income', 'color' => '#336699'])->assertSessionHasNoErrors();
        $this->consulting = Category::query()->forContext($this->ledger)->where('name', 'Consulting fees')->firstOrFail();
    }

    private function income(array $over = []): TestResponse
    {
        return $this->asOwner()->post(route('transactions.store'), $over + [
            'account_id' => $this->bank->id, 'category_id' => $this->consulting->id, 'description' => 'Workshop fee', 'amount' => '1500.00', 'type' => 'income', 'date' => '2026-11-10',
        ]);
    }

    public function test_income_recorded_through_the_form_posts_one_balanced_entry_to_bank_and_income(): void
    {
        $this->income()->assertSessionHasNoErrors()->assertRedirect(route('transactions.index'));

        $tx = Transaction::query()->forContext($this->ledger)->where('description', 'Workshop fee')->firstOrFail();
        $this->assertSame(150_000, (int) $tx->amount_cents);
        $this->assertSame('income', $tx->type);
        $this->assertSame('ZAR', $tx->currency);

        $entry = JournalEntry::query()->forContext($this->ledger)->where('source_type', 'transaction')->where('source_id', $tx->id)->firstOrFail();
        $this->assertSame('2026-11-10', $entry->entry_date->toDateString(), 'posted on the transaction date');
        $lines = JournalLine::query()->forContext($this->ledger)->where('journal_entry_id', $entry->id)->get();
        $this->assertCount(2, $lines);
        $this->assertSame(150_000, (int) $lines->sum('debit_cents'));
        $this->assertSame(150_000, (int) $lines->sum('credit_cents'));
        $this->assertSame(150_000, (int) $lines->firstWhere('gl_account_id', $this->bank->fresh()->gl_account_id)->debit_cents, 'money in is a debit to the bank account');
        $this->assertSame(150_000, (int) $lines->firstWhere('gl_account_id', $this->consulting->fresh()->gl_account_id)->credit_cents, 'income is a credit to the category\'s income account');

        // the bank account's balance is the opening balance plus the income
        $this->asOwner()->get(route('accounts.index'))->assertOk()->assertSee('101,500.00');
        $this->assertBooksVerify();
    }

    public function test_income_shows_in_the_profit_and_loss_and_the_trial_balance_for_its_period_only(): void
    {
        $this->income()->assertSessionHasNoErrors();
        $this->income(['description' => 'October fee', 'date' => '2026-10-20', 'amount' => '700.00'])->assertSessionHasNoErrors();

        $november = $this->report('profit-loss', ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertSame(150_000, $november->cell('Total income', 'total'));
        $this->assertSame(150_000, $november->cell('Net profit', 'total'));
        $this->assertReportChecksPass($november);

        $october = $this->report('profit-loss', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertSame(70_000, $october->cell('Total income', 'total'));

        [$d, $c] = $this->journalTotals();
        $this->assertSame($d, $c, 'debits equal credits');
        $this->asOwner()->get(route('reports.profit-loss', ['from' => '2026-11-01', 'to' => '2026-11-30']))->assertOk()->assertSee('Consulting fees');
    }

    public function test_the_form_refuses_wrong_income(): void
    {
        $this->income(['amount' => '0'])->assertSessionHasErrors('amount');
        $this->income(['amount' => '-5.00'])->assertSessionHasErrors('amount');
        $this->income(['amount' => '12.345'])->assertSessionHasErrors('amount');
        $this->income(['amount' => '1e3'])->assertSessionHasErrors('amount');
        $this->income(['amount' => 'abc'])->assertSessionHasErrors('amount');
        $this->income(['date' => 'not a date'])->assertSessionHasErrors('date');
        $this->income(['type' => 'transfer'])->assertSessionHasErrors('type');

        $expenseCategory = Category::factory()->forCompany($this->company)->create(['name' => 'Fuel', 'type' => 'expense']);
        $this->income(['category_id' => $expenseCategory->id])->assertSessionHasErrors('category_id');
        $this->assertSame(0, Transaction::query()->forContext($this->ledger)->where('description', 'Workshop fee')->count(), 'nothing was recorded by a refused form');
    }

    public function test_income_without_a_category_lands_in_the_uncategorised_income_account_and_is_flagged(): void
    {
        $this->income(['category_id' => '', 'description' => 'Mystery deposit'])->assertSessionHasNoErrors();
        $tx = Transaction::query()->forContext($this->ledger)->where('description', 'Mystery deposit')->firstOrFail();
        $entry = JournalEntry::query()->forContext($this->ledger)->where('source_type', 'transaction')->where('source_id', $tx->id)->firstOrFail();
        $this->assertSame(150_000, (int) JournalLine::query()->where('journal_entry_id', $entry->id)->where('gl_account_id', $this->systemId('uncategorised_income'))->sum('credit_cents'));
        $this->asOwner()->get(route('transactions.index', ['uncategorised' => 1]))->assertOk()->assertSee('Mystery deposit');
    }

    public function test_correcting_income_reverses_the_old_posting_and_leaves_the_audit_trail(): void
    {
        $this->income()->assertSessionHasNoErrors();
        $tx = Transaction::query()->forContext($this->ledger)->where('description', 'Workshop fee')->firstOrFail();

        $this->asOwner()->put(route('transactions.update', $tx), [
            'account_id' => $this->bank->id, 'category_id' => $this->consulting->id, 'description' => 'Workshop fee', 'amount' => '1800.00', 'type' => 'income', 'date' => '2026-11-10', 'version' => $tx->version,
        ])->assertSessionHasNoErrors();
        $this->assertSame(180_000, (int) $tx->fresh()->amount_cents);

        $income = $this->report('profit-loss', ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertSame(180_000, $income->cell('Total income', 'total'), 'only the corrected amount counts');
        $this->assertBooksVerify();

        $this->asOwner()->delete(route('transactions.destroy', $tx))->assertSessionHasNoErrors();
        $after = $this->report('profit-loss', ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertSame(0, (int) ($after->cell('Total income', 'total') ?? 0), 'a deleted receipt leaves no income behind');
        $this->assertBooksVerify();
        $this->assertGreaterThanOrEqual(2, $this->auditRows(null, 'transaction')->count(), 'create, update and delete are audited');
    }

    public function test_income_cannot_be_recorded_into_a_closed_period_or_by_a_viewer(): void
    {
        $viewer = $this->memberAs('viewer');
        $this->asMember($viewer)->post(route('transactions.store'), ['account_id' => $this->bank->id, 'description' => 'x', 'amount' => '1.00', 'type' => 'income', 'date' => '2026-11-10'])->assertForbidden();

        $this->asOwner()->post(route('accounting.periods.close-through'), ['through' => '2026-10']);
        $this->income(['date' => '2026-10-15'])->assertSessionHasErrors('date');
        $this->assertSame(0, Transaction::query()->forContext($this->ledger)->where('description', 'Workshop fee')->count());
        $this->income(['date' => '2026-11-15'])->assertSessionHasNoErrors();
    }
}
