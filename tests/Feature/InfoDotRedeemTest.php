<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Production gives every Dot app its own database, so InfoDot's handoff token is
 * not in ours. The app asks InfoDot to prove it, then matches or creates the user.
 */
class InfoDotRedeemTest extends TestCase
{
    use RefreshDatabase;

    /** InfoDot's launch page, as the browser reports it. */
    private function fromInfodot(): array
    {
        $infodot = parse_url((string) config('ecosystem.infodot.redeem_url', ''), PHP_URL_HOST);

        if (is_string($infodot) && $infodot !== '') {
            return ['Origin' => 'https://'.$infodot];
        }

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return ['Origin' => 'https://'.(str_contains($host, '.') ? substr($host, strpos($host, '.') + 1) : $host)];
    }

    private const URL = 'https://infodot.test/api/ecosystem/redeem';

    protected function setUp(): void
    {
        parent::setUp();

        config(['ecosystem.infodot' => [
            'redeem_url' => self::URL,
            'platform' => 'finance',
            'secret' => 'service-secret-for-tests',
            'timeout' => 2,
            'provision' => true,
        ]]);
    }

    private function identity(array $user = [], array $extra = []): array
    {
        return array_merge([
            'user' => array_merge(['id' => 7, 'name' => 'Amara Dlamini', 'email' => 'Amara@Example.test', 'email_verified' => true], $user),
            'teams' => [['id' => 3, 'uuid' => '33333333-3333-4333-8333-333333333333', 'name' => 'Amara Team', 'personal' => true, 'role' => 'owner']],
            'current_team_id' => 3,
            'current_team_uuid' => null,
        ], $extra);
    }

    private function localToken(User $user, array $attributes = []): string
    {
        // Inserted directly, as a token minted by a shared-database InfoDot would be.
        $plain = bin2hex(random_bytes(20));
        $record = PersonalAccessToken::forceCreate(array_merge([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'handoff',
            'token' => hash('sha256', $plain),
            'abilities' => ['ecosystem:read'],
        ], $attributes));

        return $record->id.'|'.$plain;
    }

    public function test_a_person_vouched_for_by_infodot_gets_an_account_and_is_signed_in(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 'one-time-token'], $this->fromInfodot())->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $user = User::where('email', 'amara@example.test')->firstOrFail();
        $this->assertSame('Amara Dlamini', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertCount(1, $user->ownedTeams);
        $this->assertTrue($user->ownedTeams->first()->personal_team);
        $this->assertSame($user->ownedTeams->first()->id, $user->current_team_id);
    }

    public function test_a_provisioned_user_has_an_unusable_random_password_and_reaches_the_dashboard(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 'one-time-token'], $this->fromInfodot());
        $user = User::where('email', 'amara@example.test')->firstOrFail();

        foreach (['', 'password', 'Amara Dlamini', 'amara@example.test'] as $guess) {
            $this->assertFalse(Hash::check($guess, $user->password));
        }

        $this->get(route('dashboard'))->assertOk();
    }

    public function test_the_secret_and_platform_go_in_the_request_and_the_token_never_in_the_url(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 'one-time-token'], $this->fromInfodot());

        Http::assertSent(function (Request $request) {
            return $request->url() === self::URL
                && $request->hasHeader('Authorization', 'Bearer service-secret-for-tests')
                && $request['token'] === 'one-time-token'
                && $request['platform'] === 'finance';
        });
    }

    public function test_an_existing_account_is_matched_by_email_without_a_duplicate(): void
    {
        $existing = User::factory()->withPersonalTeam()->create(['email' => 'amara@example.test', 'name' => 'Local Name']);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot());

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::where('email', 'amara@example.test')->count());
        $this->assertSame('Local Name', $existing->fresh()->name);
    }

    public function test_an_unverified_local_account_is_adopted_and_its_password_replaced(): void
    {
        $squatter = User::factory()->create([
            'email' => 'amara@example.test',
            'email_verified_at' => null,
            'password' => Hash::make('attacker-knows-this'),
        ]);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot());

        $this->assertAuthenticatedAs($squatter);
        $this->assertFalse(Hash::check('attacker-knows-this', $squatter->fresh()->password));
        $this->assertNotNull($squatter->fresh()->email_verified_at);
    }

    public function test_a_token_infodot_refuses_signs_nobody_in(): void
    {
        foreach ([403, 401, 422] as $status) {
            Http::fake([self::URL => Http::response(['error' => 'invalid_request'], $status)]);

            $this->post(route('ecosystem.auth'), ['token' => 'bad'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        }

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_an_identity_infodot_does_not_vouch_for_is_refused(): void
    {
        foreach ([false, 'true', 1, null] as $flag) {
            Http::fake([self::URL => Http::response($this->identity(['email_verified' => $flag]))]);
            $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        }

        $noFlag = $this->identity();
        unset($noFlag['user']['email_verified']);
        Http::fake([self::URL => Http::response($noFlag)]);
        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        Http::fake([self::URL => Http::response($this->identity(['email' => 'not-an-email']))]);
        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_when_infodot_is_down_the_person_is_told_to_retry_not_that_they_are_forbidden(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);
        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login')->assertSessionHas('status', fn (string $m) => str_contains($m, 'unavailable'));

        Http::fake([self::URL => Http::response('slow down', 429)]);
        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login')->assertSessionHas('status', fn (string $m) => str_contains($m, 'unavailable'));

        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login')->assertSessionHas('status', fn (string $m) => str_contains($m, 'unavailable'));

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_without_a_secret_the_old_local_only_behaviour_remains_and_infodot_is_never_called(): void
    {
        config(['ecosystem.infodot.secret' => null]);
        Http::fake();

        $this->post(route('ecosystem.auth'), ['token' => 'unknown'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_a_token_in_our_own_database_still_works_and_is_spent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $this->localToken($user);
        Http::fake();

        $this->post(route('ecosystem.auth'), ['token' => $token], $this->fromInfodot())->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        Http::assertNothingSent();

        auth()->logout();
        Http::fake([self::URL => Http::response(['error' => 'invalid_request'], 403)]);
        $this->post(route('ecosystem.auth'), ['token' => $token], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_an_expired_or_wrongly_scoped_local_token_is_refused(): void
    {
        config(['ecosystem.infodot.secret' => null]);
        $user = User::factory()->withPersonalTeam()->create();

        $expired = $this->localToken($user, ['expires_at' => now()->subMinute()]);
        $this->post(route('ecosystem.auth'), ['token' => $expired], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        $wrong = $this->localToken($user, ['abilities' => ['read']]);
        $this->post(route('ecosystem.auth'), ['token' => $wrong], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_provisioning_can_be_switched_off(): void
    {
        config(['ecosystem.infodot.provision' => false]);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        $this->assertSame(0, User::count());
    }

    public function test_a_missing_empty_or_non_string_token_is_forbidden_without_calling_infodot(): void
    {
        Http::fake();

        $this->post(route('ecosystem.auth'), [], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->post(route('ecosystem.auth'), ['token' => ''], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->post(route('ecosystem.auth'), ['token' => ['x']], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->post(route('ecosystem.auth'), ['token[a][b]' => 'x'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_an_unknown_token_is_a_clean_403_not_a_server_error(): void
    {
        config(['ecosystem.infodot.secret' => null]);

        foreach (['nope', '1|nope', '999999|'.str_repeat('a', 40), '|', '0'] as $token) {
            $this->post(route('ecosystem.auth'), ['token' => $token], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        }
    }

    public function test_the_session_id_is_regenerated_on_sign_in(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);
        $this->startSession();
        $before = session()->getId();

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertRedirect();

        $this->assertAuthenticated();
        $this->assertNotSame($before, session()->getId());
    }

    public function test_only_a_same_site_single_slash_path_is_followed_as_a_redirect(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 't', 'redirect' => '/profile'], $this->fromInfodot())
            ->assertRedirect(url('/profile'));

        $this->post(route('ecosystem.auth'), ['token' => 't', 'redirect' => '/profile/show?tab=security'], $this->fromInfodot())
            ->assertRedirect(url('/profile/show?tab=security'));
    }

    public function test_open_redirect_attempts_fall_back_to_the_dashboard(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class); // many attempts in one test; throttling has its own test
        Http::fake([self::URL => Http::response($this->identity())]);

        $attempts = [
            'https://evil.test/x',
            '//evil.test/x',
            '///evil.test',
            '/\\evil.test',
            '\\\\evil.test',
            'evil.test',
            'javascript:alert(1)',
            '/../etc/passwd',
            '/a/../b',
            '/%2e%2e/b',
            '/%2E%2E%2Fb',
            '/%5Cevil.test',
            "/x\r\nLocation: https://evil.test",
            '/%0d%0aLocation:%20https://evil.test',
            '',
            ['/profile'],
        ];

        foreach ($attempts as $redirect) {
            auth()->logout();
            $this->post(route('ecosystem.auth'), ['token' => 't', 'redirect' => $redirect], $this->fromInfodot())
                ->assertRedirect(route('dashboard'));
        }
    }

    public function test_the_route_is_throttled(): void
    {
        config(['ecosystem.infodot.secret' => null]);

        for ($i = 0; $i < 10; $i++) {
            $this->post(route('ecosystem.auth'), ['token' => 'guess-'.$i], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        }

        $this->post(route('ecosystem.auth'), ['token' => 'guess-11'], $this->fromInfodot())->assertStatus(429);
    }
}
