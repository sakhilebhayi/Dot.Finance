<?php

namespace Tests\Feature;

use App\Models\AiInsight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightListTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('insights.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_user_sees_only_their_own_insights(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        AiInsight::create(['user_id' => $user->id, 'type' => 'reserve_runway_healthy', 'insight' => 'Mine.']);

        $otherUser = User::factory()->withPersonalTeam()->create();
        AiInsight::create(['user_id' => $otherUser->id, 'type' => 'reserve_runway_healthy', 'insight' => 'Not mine.']);

        $response = $this->actingAs($user)->get(route('insights.index'));

        $response->assertOk();
        $response->assertSee('Mine.');
        $response->assertDontSee('Not mine.');
    }

    public function test_empty_state_renders_when_the_user_has_no_insights(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($user)->get(route('insights.index'));

        $response->assertOk();
        $response->assertSee('No insights yet');
    }
}
