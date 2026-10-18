<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashFlowReport;
use App\Services\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CashFlowReportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_income_expense_and_net_per_month_per_currency(): void
    {
        Carbon::setTestNow('2026-08-20');
        $user = User::factory()->create();
        $zar = Account::factory()->for($user)->currency('ZAR')->create();
        $usd = Account::factory()->for($user)->currency('USD')->create();

        Transaction::factory()->for($zar)->income()->cents(500_000)->on('2026-08-01')->create();
        Transaction::factory()->for($zar)->expense()->cents(120_000)->on('2026-08-05')->create();
        Transaction::factory()->for($zar)->expense()->cents(30_000)->on('2026-08-06')->create();
        Transaction::factory()->for($zar)->income()->cents(400_000)->on('2026-07-01')->create();
        Transaction::factory()->for($usd)->income()->cents(7_000)->on('2026-08-02')->create();

        $report = (new CashFlowReport)->monthly($user->id, 3);

        $this->assertSame(['USD', 'ZAR'], array_keys($report['currencies']));
        $zarRows = collect($report['currencies']['ZAR'])->keyBy('month');
        $this->assertSame(['2026-06', '2026-07', '2026-08'], array_keys($zarRows->all()));
        $this->assertSame(500_000, $zarRows['2026-08']['income_cents']);
        $this->assertSame(150_000, $zarRows['2026-08']['expense_cents']);
        $this->assertSame(350_000, $zarRows['2026-08']['net_cents']);
        $this->assertSame(400_000, $zarRows['2026-07']['income_cents']);
        $this->assertSame(0, $zarRows['2026-06']['income_cents'], 'a month with no activity is a zero row, not a gap');
        $this->assertSame(7_000, collect($report['currencies']['USD'])->keyBy('month')['2026-08']['income_cents']);
    }

    public function test_transfers_and_deleted_entries_never_count(): void
    {
        Carbon::setTestNow('2026-08-20');
        $user = User::factory()->create();
        $a = Account::factory()->for($user)->create();
        $b = Account::factory()->for($user)->create();
        app(TransferService::class)->create($user, $a, $b, 999_000, null, Carbon::parse('2026-08-10'), 'Internal', null);
        Transaction::factory()->for($a)->expense()->cents(77_700)->on('2026-08-11')->create()->delete();
        Transaction::factory()->for($a)->expense()->cents(1_000)->on('2026-08-12')->create();

        $month = collect((new CashFlowReport)->monthly($user->id, 1)['currencies']['ZAR'])->sole();

        $this->assertSame(0, $month['income_cents']);
        $this->assertSame(1_000, $month['expense_cents']);
    }

    public function test_it_only_reads_the_given_users_ledger(): void
    {
        Carbon::setTestNow('2026-08-20');
        $user = User::factory()->create();
        $other = User::factory()->create();
        Transaction::factory()->for(Account::factory()->for($other)->create())->income()->cents(555_000)->on('2026-08-01')->create();

        $this->assertSame([], (new CashFlowReport)->monthly($user->id, 3)['currencies']);
    }

    public function test_the_report_is_one_grouped_query(): void
    {
        Carbon::setTestNow('2026-08-20');
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        Transaction::factory()->for($account)->count(5)->create();

        DB::enableQueryLog();
        (new CashFlowReport)->monthly($user->id, 12);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
    }

    public function test_the_page_shows_a_section_per_currency_with_exact_figures(): void
    {
        Carbon::setTestNow('2026-08-20');
        $user = User::factory()->withPersonalTeam()->create();
        $zar = Account::factory()->for($user)->currency('ZAR')->create();
        $usd = Account::factory()->for($user)->currency('USD')->create();
        Transaction::factory()->for($zar)->income()->cents(123_456_78)->on('2026-08-01')->create();
        Transaction::factory()->for($usd)->expense()->cents(9_876_54)->on('2026-08-02')->create();

        $this->actingAs($user)->get(route('reports.cash-flow'))->assertOk()
            ->assertSee('Cash flow')
            ->assertSee('ZAR 123,456.78')
            ->assertSee('USD 9,876.54')
            ->assertSee('Transfers between your own accounts are not counted');
    }

    public function test_the_page_has_an_honest_empty_state(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->get(route('reports.cash-flow'))->assertOk()->assertSee('No income or spending recorded');
    }

    public function test_the_month_range_is_validated_and_clamped(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->get(route('reports.cash-flow', ['months' => 6]))->assertOk()->assertViewHas('months', 6);
        $this->actingAs($user)->get(route('reports.cash-flow', ['months' => 9999]))->assertOk()->assertViewHas('months', 12);
        $this->actingAs($user)->get(route('reports.cash-flow', ['months' => 'abc']))->assertOk()->assertViewHas('months', 12);
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('reports.cash-flow'))->assertRedirect(route('login'));
    }

    public function test_transactions_have_the_composite_indexes_the_ledger_queries_use(): void
    {
        $indexes = collect(Schema::getIndexes('transactions'))->map(fn ($i) => implode(',', $i['columns']));

        $this->assertContains('user_id,date', $indexes->all());
        $this->assertContains('account_id,date', $indexes->all());
    }
}
