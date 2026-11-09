<?php

namespace Tests\Feature\Integrations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** The handoff token is a credential: never an error that depends on the database, never in a log. */
class EcosystemTokenHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function fromInfodot(): array
    {
        return ['Origin' => 'https://infodot.test'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['ecosystem.infodot' => ['redeem_url' => 'https://infodot.test/api/ecosystem/redeem', 'platform' => 'finance', 'secret' => null, 'timeout' => 2, 'provision' => true]]);
    }

    public function test_ids_that_are_not_numbers_or_do_not_fit_a_bigint_are_a_clean_miss(): void
    {
        foreach (['|', 'x|y', '-1|abc', '1.5|abc', '99999999999999999999|abc', '9223372036854775808|abc', str_repeat('9', 400).'|abc', " 1|abc", "1 |abc", "1\0|abc", '0|', '|abc|def'] as $token) {
            $this->post(route('ecosystem.auth'), ['token' => $token], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
            $this->assertGuest();
        }
    }

    public function test_the_token_is_never_written_to_a_log(): void
    {
        config(['ecosystem.infodot.secret' => 'service-secret']);
        $token = 'super-secret-handoff-token-123456';
        Http::fake(['infodot.test/*' => Http::sequence()->push([], 403)->push([], 500)->push([], 401)]);
        Log::spy();

        foreach (range(1, 3) as $i) {
            $this->post(route('ecosystem.auth'), ['token' => $token], $this->fromInfodot())->assertStatus(303);
        }

        // Whatever was logged (the refusals do log), nothing handed to the logger contains the token.
        Log::shouldHaveReceived('log')->atLeast()->once();
        Log::shouldNotHaveReceived('log', fn (...$args) => str_contains(json_encode($args), $token));
        Log::shouldNotHaveReceived('warning', fn (...$args) => str_contains(json_encode($args), $token));
        Log::shouldNotHaveReceived('error', fn (...$args) => str_contains(json_encode($args), $token));
        $this->assertSame(0, User::count());
    }

    public function test_a_token_in_the_query_string_is_never_read(): void
    {
        Http::fake();

        $this->get('/auth/ecosystem?token=abc')->assertRedirect('/login');
        $this->post('/auth/ecosystem?token=abc', [], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_a_refusal_never_echoes_the_token(): void
    {
        config(['ecosystem.infodot.secret' => 'service-secret']);
        Http::fake(['infodot.test/*' => Http::response([], 403)]);

        $response = $this->post(route('ecosystem.auth'), ['token' => 'echo-me-not-0123456789'], $this->fromInfodot());

        $response->assertStatus(303);
        $this->assertStringNotContainsString('echo-me-not', (string) $response->headers->get('Location').json_encode(session()->all()));
    }
}
