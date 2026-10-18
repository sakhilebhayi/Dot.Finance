<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\Category;
use App\Models\Insight;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class GenerateInsightsTest extends TestCase
{
    use RefreshDatabase;

    private function createAccount(User $user, string $type, float $balance): Account
    {
        return Account::create([
            'user_id' => $user->id,
            'name' => ucfirst($type).' account',
            'type' => $type,
            'currency' => 'ZAR',
            'opening_balance_cents' => (int) round($balance * 100),
            'institution' => null,
        ]);
    }

    private function createTransaction(User $user, Account $account, string $type, float $amount, Carbon $date, ?Category $category = null): Transaction
    {
        return Transaction::create([
            'account_id' => $account->id,
            'category_id' => $category?->id,
            'user_id' => $user->id,
            'description' => 'Test transaction',
            'amount' => $amount,
            'type' => $type,
            'date' => $date,
        ]);
    }

    public function test_user_with_insufficient_data_gets_no_insight(): void
    {
        $user = User::factory()->create();
        $this->createAccount($user, 'checking', 1000);
        // No transactions.

        $this->artisan('finance:generate-insights');

        $this->assertSame(0, Insight::where('user_id', $user->id)->count());
    }

    public function test_growing_reserves_gets_a_healthy_insight(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 1000);
        $this->createTransaction($user, $account, 'income', 500, now());
        $this->createTransaction($user, $account, 'expense', 200, now());

        $this->artisan('finance:generate-insights');

        $insight = Insight::where('user_id', $user->id)->first();
        $this->assertNotNull($insight);
        $this->assertSame('reserve_runway_healthy', $insight->type);
        $this->assertStringContainsString('growing', $insight->insight);
    }

    public function test_shrinking_but_comfortable_runway_gets_a_healthy_insight(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 3200);
        // 3200 opening - 200 expense = 3000 derived, / 200 per month = 15 months -- well above the 3-month threshold.
        $this->createTransaction($user, $account, 'expense', 200, now());

        $this->artisan('finance:generate-insights');

        $insight = Insight::where('user_id', $user->id)->first();
        $this->assertNotNull($insight);
        $this->assertSame('reserve_runway_healthy', $insight->type);
        $this->assertStringContainsString('15 months', $insight->insight);
    }

    public function test_critically_low_runway_gets_a_warning_insight(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 300);
        // 300 opening - 100 expense = 200 derived, / 100 per month = 2 months -- below the 3-month threshold.
        $this->createTransaction($user, $account, 'expense', 100, now());

        $this->artisan('finance:generate-insights');

        $insight = Insight::where('user_id', $user->id)->first();
        $this->assertNotNull($insight);
        $this->assertSame('reserve_runway_warning', $insight->type);
        $this->assertStringContainsString('2 months', $insight->insight);
    }

    public function test_warning_insight_includes_the_what_if_category_when_available(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 350);
        $rent = Category::create(['user_id' => $user->id, 'name' => 'Rent', 'type' => 'expense', 'color' => '#000000']);
        $food = Category::create(['user_id' => $user->id, 'name' => 'Food', 'type' => 'expense', 'color' => '#000000']);

        // Two categories so removing the top one (Rent) still leaves burn --
        // the calculator's what_if returns null when removing the top
        // category would eliminate the burn entirely (see
        // ReserveRunwayCalculator::computeWhatIf()).
        $this->createTransaction($user, $account, 'expense', 100, now(), $rent);
        $this->createTransaction($user, $account, 'expense', 50, now(), $food);

        $this->artisan('finance:generate-insights');

        $insight = Insight::where('user_id', $user->id)->first();
        $this->assertSame('reserve_runway_warning', $insight->type);
        $this->assertStringContainsString('Rent', $insight->insight);
    }

    public function test_running_twice_in_the_same_iso_week_creates_one_summary(): void
    {
        Carbon::setTestNow('2026-10-14'); // a Wednesday in ISO week 42
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 200);
        $this->createTransaction($user, $account, 'expense', 100, now());

        $this->artisan('finance:generate-insights');
        $this->artisan('finance:generate-insights');

        $this->assertSame(1, Insight::where('user_id', $user->id)->count());
        $this->assertSame('2026-W42', Insight::where('user_id', $user->id)->value('iso_week'));

        Carbon::setTestNow();
    }

    public function test_a_new_iso_week_gets_a_new_summary(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 200);
        $this->createTransaction($user, $account, 'expense', 100, Carbon::parse('2026-10-14'));

        Carbon::setTestNow('2026-10-14');
        $this->artisan('finance:generate-insights');
        Carbon::setTestNow('2026-10-21');
        $this->artisan('finance:generate-insights');

        $this->assertSame(['2026-W42', '2026-W43'], Insight::where('user_id', $user->id)->orderBy('iso_week')->pluck('iso_week')->all());

        Carbon::setTestNow();
    }

    public function test_the_iso_week_uses_the_iso_year_across_new_year(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 200);
        $this->createTransaction($user, $account, 'expense', 100, Carbon::parse('2027-01-01'));

        Carbon::setTestNow('2027-01-01'); // Friday: ISO week 53 of 2026
        $this->artisan('finance:generate-insights');

        $this->assertSame('2026-W53', Insight::where('user_id', $user->id)->value('iso_week'));

        Carbon::setTestNow();
    }

    public function test_the_database_itself_refuses_a_second_summary_for_the_same_week(): void
    {
        $user = User::factory()->create();
        Insight::create(['user_id' => $user->id, 'type' => 'reserve_runway_healthy', 'insight' => 'First.', 'iso_week' => '2026-W42']);

        $this->expectException(UniqueConstraintViolationException::class);
        Insight::create(['user_id' => $user->id, 'type' => 'reserve_runway_healthy', 'insight' => 'Second.', 'iso_week' => '2026-W42']);
    }

    public function test_summaries_name_the_currency_and_never_hardcode_a_dollar_sign(): void
    {
        $user = User::factory()->create();
        $zar = $this->createAccount($user, 'checking', 300);
        $usd = Account::create(['user_id' => $user->id, 'name' => 'Dollar account', 'type' => 'checking', 'currency' => 'USD', 'opening_balance_cents' => 50_000]);
        $this->createTransaction($user, $zar, 'expense', 100, now());
        $this->createTransaction($user, $usd, 'expense', 50, now());

        $this->artisan('finance:generate-insights');

        $text = Insight::where('user_id', $user->id)->sole()->insight;
        $this->assertStringContainsString('ZAR', $text);
        $this->assertStringContainsString('USD', $text);
        $this->assertStringContainsString('ZAR 200.00', $text);
        $this->assertStringNotContainsString('$', $text);
    }

    public function test_the_command_describes_itself_as_rule_based_not_ai(): void
    {
        $description = Artisan::all()['finance:generate-insights']->getDescription();

        $this->assertStringContainsString('rule-based', strtolower($description));
        $this->assertDoesNotMatchRegularExpression('/\bAI[ -](insight|generated|powered|driven)/i', $description);
    }

    public function test_a_users_insight_never_leaks_into_another_users_query(): void
    {
        $userA = User::factory()->create();
        $accountA = $this->createAccount($userA, 'checking', 200);
        $this->createTransaction($userA, $accountA, 'expense', 100, now());

        $userB = User::factory()->create();
        $this->createAccount($userB, 'checking', 1000);
        // userB has no transactions -- insufficient data, no insight.

        $this->artisan('finance:generate-insights');

        $this->assertSame(1, Insight::where('user_id', $userA->id)->count());
        $this->assertSame(0, Insight::where('user_id', $userB->id)->count());
    }
}
