<?php

namespace Tests\Feature\Polish;

use App\Models\Accounting\GlAccount;
use App\Models\Category;
use App\Models\User;
use App\Services\Accounting\Chart;
use App\Services\Accounting\ChartTemplates;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAccounting;
use Tests\TestCase;

/** R-44 polish (jury defect 17, D-1707): a category called "Rent" posts to the chart's own Rent account, not to a second one. */
class CategoryMappingTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    public function test_a_category_with_the_name_of_a_plain_chart_account_shares_it_and_others_get_their_own(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $ctx = LedgerContext::personal($user);
        $rent = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Rent']);
        $fuel = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Fuel']);
        $sales = Category::factory()->create(['user_id' => $user->id, 'type' => 'income', 'name' => 'Sales']);

        $this->enableAccounting($ctx, 'ZAR', ChartTemplates::COMPANY);

        LedgerContext::run($ctx, function () use ($rent, $fuel, $sales): void {
            $templateRent = GlAccount::query()->where('name', 'Rent')->where('code', '6100')->firstOrFail();
            $this->assertSame($templateRent->id, $rent->fresh()->gl_account_id, 'the Rent category uses the chart\'s Rent account');
            $this->assertSame(1, GlAccount::query()->where('name', 'Rent')->count(), 'there is one Rent account, not two');

            $fuelAccount = GlAccount::find($fuel->fresh()->gl_account_id);
            $this->assertSame('category', $fuelAccount->subtype, 'a name the chart does not have gets its own account as before');
            $this->assertNotSame('6100', $fuelAccount->code);

            $salesAccount = GlAccount::find($sales->fresh()->gl_account_id);
            $this->assertNotSame('sales', $salesAccount->system_key, 'a system account the posting engine owns is never shared with a category');
        });
    }

    public function test_a_second_category_with_the_same_name_does_not_take_a_shared_account_too(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $ctx = LedgerContext::personal($user);
        $this->enableAccounting($ctx, 'ZAR', ChartTemplates::COMPANY);

        LedgerContext::run($ctx, function () use ($user): void {
            $first = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Insurance']);
            $second = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'insurance']);
            $a = app(Chart::class)->mapCategory($first);
            $b = app(Chart::class)->mapCategory($second);

            $this->assertSame('6130', $a->code, 'the first takes the chart\'s Insurance account (matched ignoring case)');
            $this->assertNotSame($a->id, $b->id, 'a second category never shares the account the first one uses');
        });
    }
}
