<?php

namespace Tests\Feature\Acceptance;

use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalLine;
use App\Services\Accounting\Chart;
use App\Services\Accounting\GlSync;
use App\Services\CashFlowReport;
use App\Services\Reporting\DrillDown;

/**
 * Owner criterion 15: cash-flow reporting reflects actual transactions. The cash-flow statement is the real movement through the bank
 * accounts (every movement of the month is in it, drillable to its journal line), changes by exactly what a new transaction moves,
 * ignores a transfer between the business's own accounts, and the older monthly cash-flow report agrees with the cash ledger.
 */
class Acceptance15_CashFlowTest extends AcceptanceCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runMonth();
    }

    private function closing(string $to = '2026-11-30', string $from = '2026-11-01'): int
    {
        return (int) $this->report('cash-flow-statement', ['from' => $from, 'to' => $to, 'view' => 'direct'])->cell('Closing cash and bank balances', 'total');
    }

    public function test_every_movement_through_the_bank_is_in_the_statement_and_its_drill_down_lists_exactly_those_movements(): void
    {
        $r = $this->report('cash-flow-statement', ['from' => '2026-11-01', 'to' => '2026-11-30', 'view' => 'direct']);
        $this->assertSame(1_929_000, $r->cell('Net change in cash', 'total'), 'by hand: + 5,000.00 - 4,140.00 - 1,150.00 + 20,700.00 - 1,035.00 - 120.00 + 35.00');

        $drill = app(DrillDown::class)->lines($this->ledger, ['class' => 'cash', 'from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertSame(7, $drill['count'], 'seven movements on the statement, seven cash journal lines');
        $this->assertSame(1_929_000, $drill['total_cents']);
        $this->assertSame(1_929_000, JournalLine::query()->forContext($this->ledger)->where('cash_account_id', $this->bank->id)->whereBetween('entry_date', ['2026-11-01', '2026-11-30'])
            ->selectRaw('SUM(debit_cents) - SUM(credit_cents) as n')->value('n'));
    }

    public function test_the_statement_for_two_halves_of_the_month_adds_up_to_the_whole_month(): void
    {
        $first = $this->report('cash-flow-statement', ['from' => '2026-11-01', 'to' => '2026-11-15', 'view' => 'direct']);
        $second = $this->report('cash-flow-statement', ['from' => '2026-11-16', 'to' => '2026-11-30', 'view' => 'direct']);
        $this->assertSame(500_000 - 414_000 - 115_000, $first->cell('Net change in cash', 'total'));
        $this->assertSame(1_929_000, $first->cell('Net change in cash', 'total') + $second->cell('Net change in cash', 'total'));
        $this->assertSame($first->cell('Closing cash and bank balances', 'total'), $second->cell('Opening cash and bank balances', 'total'), 'one half closes where the next opens');
    }

    public function test_a_new_receipt_and_a_new_payment_change_the_statement_by_exactly_their_amounts_at_once(): void
    {
        $before = $this->closing();
        $this->asOwner()->post(route('transactions.store'), ['account_id' => $this->bank->id, 'description' => 'Cash sale', 'amount' => '300.00', 'type' => 'income', 'date' => '2026-11-27'])->assertSessionHasNoErrors();
        $this->assertSame($before + 30_000, $this->closing());
        $this->asOwner()->post(route('transactions.store'), ['account_id' => $this->bank->id, 'description' => 'Parking', 'amount' => '45.50', 'type' => 'expense', 'date' => '2026-11-27'])->assertSessionHasNoErrors();
        $this->assertSame($before + 30_000 - 4_550, $this->closing());
        $this->assertSame($this->closing(), Account::query()->forContext($this->ledger)->withBalance()->findOrFail($this->bank->id)->balanceCents(), 'and the account page says the same');
    }

    public function test_a_transfer_between_the_businesss_own_accounts_moves_no_cash_in_or_out(): void
    {
        $savings = Account::factory()->forCompany($this->company)->create(['name' => 'Savings', 'type' => 'savings', 'currency' => 'ZAR', 'opening_balance_cents' => 0, 'opening_balance_date' => '2026-10-01']);
        $this->enableAccountingMapping($savings);
        $netBefore = $this->report('cash-flow-statement', ['from' => '2026-11-01', 'to' => '2026-11-30', 'view' => 'direct'])->cell('Net change in cash', 'total');

        $this->asOwner()->post(route('transfers.store'), ['from_account_id' => $this->bank->id, 'to_account_id' => $savings->id, 'amount' => '10000.00', 'date' => '2026-11-28'])->assertSessionHasNoErrors();

        $after = $this->report('cash-flow-statement', ['from' => '2026-11-01', 'to' => '2026-11-30', 'view' => 'direct']);
        $this->assertSame($netBefore, $after->cell('Net change in cash', 'total'), 'money moved between own accounts is not a cash flow of the business');
        $this->assertSame(11_929_000 - 1_000_000, $after->cell('Closing balance: 1011 Current account', 'total'));
        $this->assertSame(1_000_000, $after->cell('Closing balance: '.$this->glLabel($savings), 'total'));
        $this->assertReportChecksPass($after);
        $this->assertBooksVerify();
    }

    public function test_the_monthly_cash_flow_report_agrees_with_the_cash_ledger_and_leaves_out_transfers(): void
    {
        $this->asOwner()->post(route('transactions.store'), ['account_id' => $this->bank->id, 'description' => 'Cash sale', 'amount' => '300.00', 'type' => 'income', 'date' => '2026-11-27']);
        $report = app(CashFlowReport::class)->monthly($this->ledger, 3);
        $november = collect($report['currencies']['ZAR'])->firstWhere('month', '2026-11');
        // by hand: receipts 5,000.00 + 20,700.00, interest 35.00 and the cash sale 300.00 in; two supplier payments, the claim, the fee out
        $this->assertSame(2_603_500, $november['income_cents']);
        $this->assertSame(414_000 + 115_000 + 103_500 + 12_000, $november['expense_cents']);
        $this->assertSame(1_929_000 + 30_000, $november['net_cents'], 'the same movement the cash-flow statement shows, plus the new sale');

        $savings = Account::factory()->forCompany($this->company)->create(['name' => 'Savings', 'type' => 'savings', 'currency' => 'ZAR', 'opening_balance_cents' => 0, 'opening_balance_date' => '2026-10-01']);
        $this->enableAccountingMapping($savings);
        $this->asOwner()->post(route('transfers.store'), ['from_account_id' => $this->bank->id, 'to_account_id' => $savings->id, 'amount' => '500.00', 'date' => '2026-11-28'])->assertSessionHasNoErrors();
        $again = collect(app(CashFlowReport::class)->monthly($this->ledger, 3)['currencies']['ZAR'])->firstWhere('month', '2026-11');
        $this->assertSame($november, $again, 'a transfer is neither income nor expense');
        $this->asOwner()->get(route('reports.cash-flow', ['months' => 3]))->assertOk()->assertSee('Nov 2026');
    }

    public function test_the_cash_position_starts_from_actual_cash_and_shows_the_projection_as_a_projection(): void
    {
        $page = $this->asOwner()->get(route('planning.cash.index'))->assertOk()->getContent();
        $this->assertStringContainsString('119,290.00', $page, 'cash today is the actual bank balance');
        $this->assertMatchesRegularExpression('/projection|projected/i', $page);
    }

    private function enableAccountingMapping(Account $account): void
    {
        app(Chart::class)->mapAll($this->ledger);
        app(GlSync::class)->account($account->fresh());
    }

    private function glLabel(Account $account): string
    {
        $gl = GlAccount::query()->forContext($this->ledger)->findOrFail($account->fresh()->gl_account_id);

        return $gl->code.' '.$gl->name;
    }
}
