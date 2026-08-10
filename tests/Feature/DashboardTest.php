<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiInsight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_view_the_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_dashboard_shows_empty_states_for_a_fresh_user(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('No transactions yet')
            ->assertSee('No budgets set up yet');
    }

    public function test_dashboard_totals_only_reflect_the_authenticated_users_own_accounts(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $otherUser = User::factory()->create();

        Account::create(['user_id' => $user->id, 'name' => 'My Wallet', 'type' => 'cash', 'currency' => 'ZAR', 'balance' => 100]);
        Account::create(['user_id' => $otherUser->id, 'name' => 'Their Wallet', 'type' => 'cash', 'currency' => 'ZAR', 'balance' => 999999]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('My Wallet')
            ->assertDontSee('Their Wallet');
    }

    public function test_dashboard_view_insights_link_points_at_the_insights_index(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        AiInsight::create(['user_id' => $user->id, 'type' => 'reserve_runway_healthy', 'insight' => 'Test insight.']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee(route('insights.index'), false);
    }
}
