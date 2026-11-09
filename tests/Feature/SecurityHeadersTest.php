<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R-44 (D-400, D-401): the Content-Security-Policy is enforced and nonce-based, the baseline browser headers are on every
 * response (error pages included), and no page carries inline style, an un-nonced inline script or a third-party asset.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function nonceFrom(string $policy): string
    {
        $this->assertSame(1, preg_match("/script-src 'self' 'nonce-([A-Za-z0-9+\\/=]+)'/", $policy, $m), 'the policy carries a script nonce');

        return $m[1];
    }

    public function test_every_response_carries_the_baseline_headers_and_an_enforced_policy(): void
    {
        foreach (['/', '/login', '/does-not-exist'] as $path) {
            $response = $this->get($path);

            $response->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $this->assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'), $path);
            $this->assertTrue($response->headers->has('Content-Security-Policy'), "$path: the policy is enforced");
            $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'), "$path: it is not report-only");
        }

        $this->get('/up')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_policy_is_nonce_based_self_hosted_and_has_no_unsafe_inline(): void
    {
        $policy = (string) $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertMatchesRegularExpression("/style-src 'self' 'nonce-[^']+'(?:;| |$)/", $policy);
        $this->assertStringNotContainsString("'unsafe-inline'", $policy);
        $this->assertStringContainsString("font-src 'self'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);
        $this->assertStringContainsString('report-uri /csp-report', $policy);
        // The one concession, and why: Livewire's Alpine compiles x-data expressions with new Function().
        $this->assertStringContainsString("'unsafe-eval'", $policy);
        $this->assertDoesNotMatchRegularExpression('#https?://#', $policy, 'no third-party origin is allowed anywhere');
    }

    public function test_the_nonce_is_new_every_request_and_matches_the_inline_script_that_carries_it(): void
    {
        $first = $this->get('/login');
        $second = $this->get('/login');

        $nonce = $this->nonceFrom((string) $first->headers->get('Content-Security-Policy'));
        $this->assertNotSame($nonce, $this->nonceFrom((string) $second->headers->get('Content-Security-Policy')), 'a nonce is never reused');
        $this->assertStringContainsString('<script nonce="'.$nonce.'">', $first->getContent(), 'the theme script carries this request\'s nonce');
        $this->assertSame(1, preg_match('/<script src="[^"]*livewire[^"]*"[^>]*>/', $first->getContent(), $m), 'Livewire\'s script is on the page');
        $this->assertStringContainsString('nonce="'.$nonce.'"', $m[0], 'Livewire\'s script is nonced too');
    }

    public function test_the_csp_can_be_made_report_only_without_a_deploy(): void
    {
        config(['security.csp_report_only' => true]);

        $response = $this->get('/login');

        $this->assertFalse($response->headers->has('Content-Security-Policy'));
        $this->assertStringContainsString("default-src 'self'", (string) $response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_hsts_is_sent_on_https_only_and_can_be_switched_off(): void
    {
        $this->assertFalse($this->get('/login')->headers->has('Strict-Transport-Security'), 'plain http never gets HSTS');
        $this->assertSame('max-age=15552000', (string) $this->get('https://localhost/login')->headers->get('Strict-Transport-Security'), 'six months, no includeSubDomains, no preload');

        config(['security.hsts_seconds' => 0]);
        $this->assertFalse($this->get('https://localhost/login')->headers->has('Strict-Transport-Security'));
    }

    public function test_violation_reports_are_accepted_anonymously_and_log_only_the_directive_and_host(): void
    {
        Log::shouldReceive('channel')->once()->andReturnSelf();
        Log::shouldReceive('warning')->once()->with('csp.violation', ['directive' => 'script-src-elem', 'blocked' => 'evil.example']);

        $body = json_encode(['csp-report' => [
            'effective-directive' => 'script-src-elem',
            'blocked-uri' => 'https://evil.example/steal?token=SECRET123',
            'document-uri' => 'https://example.test/x?code=SECRET456',
        ]]);

        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $body)->assertNoContent();
    }

    /** @return array<string, array{0: string}> */
    public static function signedInPages(): array
    {
        return [
            'dashboard' => ['dashboard'], 'transactions' => ['transactions.index'], 'new transaction' => ['transactions.create'],
            'import' => ['transactions.import.create'], 'transfer' => ['transfers.create'], 'accounts' => ['accounts.index'],
            'categories' => ['categories.index'], 'budgets' => ['budgets.index'], 'recurring' => ['recurring.index'],
            'cash flow' => ['reports.cash-flow'], 'forecast' => ['forecast.index'], 'insights' => ['insights.index'],
            'audit' => ['audit.index'], 'tie-out' => ['reconciliation.index'], 'profile' => ['profile.show'], 'create team' => ['teams.create'],
        ];
    }

    #[DataProvider('signedInPages')]
    public function test_no_signed_in_page_has_inline_style_an_unnonced_script_or_a_third_party_asset(string $routeName): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create(['name' => 'Nonce test account']);
        Transaction::factory()->create(['account_id' => $account->id, 'user_id' => $user->id, 'description' => 'Nonce test entry']);

        $response = $this->actingAs($user)->get(route($routeName))->assertOk();
        $html = $response->getContent();
        $nonce = $this->nonceFrom((string) $response->headers->get('Content-Security-Policy'));

        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html, "[$routeName] carries a style attribute");
        $this->assertDoesNotMatchRegularExpression('/<(?:script|style)\b(?![^>]*\bnonce="'.preg_quote($nonce, '/').'")(?![^>]*\bsrc=)[^>]*>/i', $html, "[$routeName] has an inline script or style without this request's nonce");
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*"/i', $html, "[$routeName] has an inline event handler");
        preg_match_all('#(?:src|href)="https?://([^/"]+)#i', $html, $hosts);
        $own = [parse_url((string) config('app.url'), PHP_URL_HOST), 'localhost'];
        $this->assertSame([], array_values(array_diff($hosts[1], $own)), "[$routeName] references a third-party origin");
        $this->assertDoesNotMatchRegularExpression('#cdn\.tailwindcss\.com|fonts\.googleapis\.com|fonts\.gstatic\.com#', $html, "[$routeName] loads a CDN or Google font");
    }

    public function test_public_pages_and_error_pages_are_clean_too(): void
    {
        foreach (['/', '/login', '/cookies', '/terms-of-service', '/privacy-policy', '/forgot-password', '/does-not-exist'] as $path) {
            $html = $this->get($path)->getContent();

            $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html, "$path carries a style attribute");
            $this->assertDoesNotMatchRegularExpression('/<style\b(?![^>]*\bnonce=)/i', $html, "$path has a <style> block without a nonce");
            $this->assertDoesNotMatchRegularExpression('/<script\b(?![^>]*\bnonce=)(?![^>]*\bsrc=)/i', $html, "$path has an un-nonced inline script");
        }
    }
}
