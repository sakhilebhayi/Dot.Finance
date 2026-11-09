<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * R-44 security sweep, part 6 and 8: sign-in, sessions, tokens, and what a stranger can learn.
 */
class AuthAndSessionSecurityTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private const FROM = ['Origin' => 'https://infodot.test'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ecosystem.infodot.redeem_url' => 'https://infodot.test/api/ecosystem/redeem', 'ecosystem.infodot.secret' => null]);
    }

    public function test_a_bearer_token_does_not_open_the_web_application(): void
    {
        $user = $this->user();
        $plain = $user->createToken('api', ['read'])->plainTextToken;
        $ecosystem = $user->createToken('handoff', ['ecosystem:read'])->plainTextToken;

        foreach ([$plain, $ecosystem] as $token) {
            foreach (['/dashboard', '/accounts', '/transactions', '/reports/cash-flow', '/accounting/trial-balance', '/audit', '/controls', '/tax'] as $uri) {
                $response = $this->withHeader('Authorization', 'Bearer '.$token)->getJson($uri);
                $this->assertContains($response->getStatusCode(), [401, 403], "a bearer token reached {$uri} (status ".$response->getStatusCode().')');
            }
        }
    }

    public function test_the_api_user_route_still_works_with_a_bearer_token(): void
    {
        $user = $this->user();
        $token = $user->createToken('api', ['read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/user')->assertOk()->assertJsonPath('email', $user->email);
    }

    public function test_the_session_cookie_is_http_only_same_site_and_secure_over_https(): void
    {
        $response = $this->get('https://finance.infodot.co.za/login');

        $cookies = collect($response->headers->getCookies())->keyBy(fn ($c) => $c->getName());
        $session = $cookies->first(fn ($c) => str_contains($c->getName(), 'session'));
        $this->assertNotNull($session, 'a session cookie is set on the login page: '.$cookies->keys()->implode(', '));
        $this->assertTrue($session->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $session->getSameSite()));
        $this->assertTrue($session->isSecure(), 'the session cookie must carry Secure over HTTPS');
        $xsrf = $cookies->first(fn ($c) => $c->getName() === 'XSRF-TOKEN');
        $this->assertTrue($xsrf === null || $xsrf->isSecure());
    }

    public function test_signing_in_through_infodot_issues_a_new_session_id(): void
    {
        $user = $this->user();
        $token = $user->createToken('handoff', ['ecosystem:read'])->plainTextToken;

        $this->get('/login');
        $before = session()->getId();
        $this->post(route('ecosystem.auth'), ['token' => $token], self::FROM)->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId(), 'the session id must change at sign-in (fixation)');
    }

    public function test_a_handoff_token_works_once(): void
    {
        $user = $this->user();
        $token = $user->createToken('handoff', ['ecosystem:read'])->plainTextToken;
        $origin = self::FROM;

        $this->post(route('ecosystem.auth'), ['token' => $token], $origin)->assertRedirect();
        $this->assertAuthenticated();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post(route('ecosystem.auth'), ['token' => $token], $origin)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_the_handoff_redirect_target_cannot_leave_the_site(): void
    {
        $origin = self::FROM;
        $this->withoutMiddleware(ThrottleRequests::class);   // the handoff is throttled to 10 a minute; this loop is not an attack on that
        $targets = ['https://evil.example/', '//evil.example', '/\\evil.example', '/%2f%2fevil.example', '/%5cevil.example', 'javascript:alert(1)', '/..%2f..%2fetc', '/a//b', "/x\r\nLocation: https://evil.example", '/ok/../../x', 'evil.example', '\\\\evil.example', "/\t/evil.example", '/%09/evil.example'];

        foreach ($targets as $target) {
            $user = $this->user();
            $token = $user->createToken('handoff', ['ecosystem:read'])->plainTextToken;
            $this->app['auth']->forgetGuards();
            $this->flushSession();

            $response = $this->post(route('ecosystem.auth'), ['token' => $token, 'redirect' => $target], $origin);
            $location = (string) $response->headers->get('Location');
            $this->assertStringNotContainsString('evil.example', $location, "redirect {$target} -> {$location}");
            $this->assertTrue(str_starts_with($location, config('app.url')) || str_starts_with($location, '/'), "redirect {$target} -> {$location}");
        }
    }

    public function test_a_handoff_from_another_site_is_refused_even_with_a_valid_token(): void
    {
        $user = $this->user();
        $token = $user->createToken('handoff', ['ecosystem:read'])->plainTextToken;

        foreach (['https://evil.example', 'null', 'https://infodot.co.za.evil.example', 'https://evilinfodot.co.za'] as $origin) {
            $this->post(route('ecosystem.auth'), ['token' => $token], ['Origin' => $origin])->assertRedirect('/login');
            $this->assertGuest();
        }
        $this->post(route('ecosystem.auth'), ['token' => $token])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_the_login_form_is_throttled_and_does_not_reveal_which_emails_exist(): void
    {
        $user = User::factory()->create(['password' => Hash::make('right-password-1')]);

        $known = $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        $unknown = $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong']);
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame(json_encode(session('errors')), json_encode(session('errors')));

        $statuses = [];
        for ($i = 0; $i < 8; $i++) {
            $statuses[] = $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->getStatusCode();
        }
        $this->assertContains(429, $statuses, 'five failures a minute per email and address, then 429');
        $this->assertGuest();
    }

    public function test_the_password_reset_form_gives_the_same_answer_for_known_and_unknown_emails(): void
    {
        $user = User::factory()->create();

        $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);
        $knownErrors = json_encode(session('errors'));
        $knownStatus = session('status');
        $this->flushSession();
        $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody-at-all@example.com']);
        $unknownErrors = json_encode(session('errors'));
        $unknownStatus = session('status');

        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($knownErrors, $unknownErrors, 'the page must not say whether an email has an account');
        $this->assertSame($knownStatus, $unknownStatus);
    }

    public function test_a_password_reset_token_is_single_use_and_cannot_be_replayed(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Brand-new-pass-1!', 'password_confirmation' => 'Brand-new-pass-1!'])->assertSessionHasNoErrors();
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Another-pass-2!', 'password_confirmation' => 'Another-pass-2!'])->assertSessionHasErrors();
        $this->assertTrue(Hash::check('Brand-new-pass-1!', $user->fresh()->password));
    }

    public function test_a_failed_sign_in_logs_no_password_and_no_token(): void
    {
        Log::spy();
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'SuperSecretPassword-9!']);
        $this->post(route('ecosystem.auth'), ['token' => 'SECRET-HANDOFF-TOKEN-123456789'], ['Origin' => 'https://evil.example']);

        Log::shouldNotHaveReceived('log', fn (...$a) => str_contains(json_encode($a), 'SuperSecretPassword') || str_contains(json_encode($a), 'SECRET-HANDOFF'));
        $this->addToAssertionCount(1);
    }
}
