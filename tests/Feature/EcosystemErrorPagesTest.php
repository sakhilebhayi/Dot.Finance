<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EcosystemErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('errorCodes')]
    public function test_error_page_renders_branded_content(int $code, string $expectedHeading): void
    {
        $response = $this->get("/_test-only-error-render/{$code}");

        $response->assertStatus($code);
        $response->assertSee($expectedHeading);
        $response->assertSee('Dot.Finance', false);
        // R-44 polish (D-1715): an error page leads back to the product and offers a way to report it. It does not advertise the
        // other Dot platforms under a finance error.
        $response->assertDontSee('the rest of the Dot Ecosystem', false);
        $response->assertSee('Report this problem');
    }

    public function test_an_unmatched_url_knows_who_is_signed_in(): void
    {
        // No cookie: a plain 404 that offers sign-in and starts no session.
        $this->get('/no-such-page-here')->assertNotFound()->assertSee('Sign in')->assertDontSee('Back to the Command centre');
        $this->assertSame(0, DB::table('sessions')->count(), 'a request with no session cookie writes nothing');

        // Signed in for real, then an unmatched URL carrying the session cookie: the page leads back to the Command centre.
        $user = User::factory()->withPersonalTeam()->create();
        $login = $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
        $this->assertNotNull($cookie, 'the sign-in set a session cookie');

        $page = $this->call('GET', '/no-such-page-here', [], [$cookie->getName() => $cookie->getValue()]);
        $page->assertNotFound()->assertSee('Back to the Command centre')->assertDontSee('Ecosystem');
        $this->assertStringNotContainsString('>Sign in<', $page->getContent());
    }

    public function test_the_report_link_uses_the_configured_support_address(): void
    {
        config(['support.email' => 'help@example.test']);

        $this->get('/_test-only-error-render/500')->assertSee('mailto:help@example.test', false)->assertSee('Dot.Finance%20problem%20500', false);
    }

    public static function errorCodes(): array
    {
        return [
            '404 not found' => [404, "We couldn't find that page"],
            '403 forbidden' => [403, "You don't have access to this"],
            '419 page expired' => [419, 'Your session timed out'],
            '429 too many requests' => [429, 'Slow down a little'],
            '500 server error' => [500, 'Something went wrong on our end'],
            '503 unavailable' => [503, "We'll be right back"],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']->get('/_test-only-error-render/{code}', function (int $code) {
            return response()->view("errors.{$code}", [], $code);
        });
    }
}
