<?php

namespace Tests\Feature;

use App\Models\Insight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        Insight::create(['user_id' => $user->id, 'type' => 'reserve_runway_healthy', 'insight' => 'Mine.']);

        $otherUser = User::factory()->withPersonalTeam()->create();
        Insight::create(['user_id' => $otherUser->id, 'type' => 'reserve_runway_healthy', 'insight' => 'Not mine.']);

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
        $response->assertSee('No summaries yet');
    }

    public function test_the_page_labels_summaries_as_rule_based_and_not_ai(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Insight::create(['user_id' => $user->id, 'type' => 'reserve_runway_healthy', 'insight' => 'A summary body.']);

        $this->actingAs($user)->get(route('insights.index'))->assertOk()
            ->assertSee('Rule-based summary')
            ->assertSee('not AI')
            ->assertDontSee('AI-powered')
            ->assertDontSee('AI Insight');
    }

    public function test_the_dashboard_card_is_labelled_rule_based_and_counts_summaries_not_unread_items(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Insight::create(['user_id' => $user->id, 'type' => 'reserve_runway_healthy', 'insight' => 'One.', 'iso_week' => '2026-W40']);
        Insight::create(['user_id' => $user->id, 'type' => 'reserve_runway_healthy', 'insight' => 'Two.', 'iso_week' => '2026-W41']);

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $response->assertSee('Rule-based summary')
            ->assertSee('2 summaries')
            ->assertDontSee('AI Insights')
            ->assertDontSee('unread');
    }

    public function test_legacy_duplicate_weeks_are_kept_but_only_the_latest_takes_the_unique_week(): void
    {
        $user = User::factory()->create();
        $migration = require database_path('migrations/2026_10_18_220000_add_iso_week_and_indexes_for_reports.php');
        $migration->down();

        $now = now();
        $insert = fn (string $text, string $createdAt) => DB::table('ai_insights')->insertGetId([
            'user_id' => $user->id, 'insight' => $text, 'type' => 'reserve_runway_warning', 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
        $first = $insert('Monday run', '2026-10-12 08:00:00');   // ISO week 42
        $second = $insert('Friday rerun', '2026-10-16 08:00:00'); // ISO week 42 again
        $third = $insert('Next week', '2026-10-20 08:00:00');    // ISO week 43

        $migration->up();

        $weeks = DB::table('ai_insights')->pluck('iso_week', 'id');
        $this->assertNull($weeks[$first], 'the older duplicate keeps its row but gives up the unique week');
        $this->assertSame('2026-W42', $weeks[$second]);
        $this->assertSame('2026-W43', $weeks[$third]);
        $this->assertSame(3, DB::table('ai_insights')->count(), 'nothing is deleted');
    }
}
