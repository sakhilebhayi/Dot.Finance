<?php

namespace Tests\Feature\Security;

use App\Services\Sales\ShareLinks;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSales;
use Tests\TestCase;

/**
 * R-44 security sweep, part 4b and 8: the public share links (signed by being unguessable, expiring, revocable, throttled),
 * and what anyone can learn by asking.
 */
class ShareLinkAndEnumerationTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSales();
    }

    private function link(?int $days = null): array
    {
        $invoice = $this->issued();

        return LedgerContext::run($this->ledger, fn () => app(ShareLinks::class)->forDocument($invoice, $this->owner, $days));
    }

    public function test_a_live_link_shows_the_document_and_the_page_is_not_cacheable_or_indexable(): void
    {
        $made = $this->link();
        $response = $this->get('/s/'.$made['token'])->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame([], $response->headers->getCookies(), 'a public page sets no cookie');
        $this->get('/s/'.$made['token'].'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_unknown_malformed_expired_and_revoked_links_all_look_exactly_alike(): void
    {
        $live = $this->link();
        $expired = $this->link(1);
        \DB::table('sales_share_links')->where('id', $expired['link']->id)->update(['expires_at' => now()->subMinute()]);
        $revoked = $this->link();
        $this->inCompany($this->owner, $this->company)->post(route('sales.links.revoke', $revoked['link']))->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $answers = [];
        foreach (['unknown' => Str::random(40), 'short' => 'abc', 'symbols' => str_repeat('!', 40), 'long' => Str::random(200), 'expired' => $expired['token'], 'revoked' => $revoked['token']] as $what => $token) {
            $response = $this->get('/s/'.rawurlencode($token));
            $this->assertSame(404, $response->getStatusCode(), $what);
            $answers[$what] = $response->getContent();
            $this->get('/s/'.rawurlencode($token).'/pdf')->assertNotFound();
        }
        $this->assertCount(1, array_unique(array_map(fn ($c) => preg_replace('/nonce="[^"]+"|nonce-[A-Za-z0-9+\/=]+/', '', $c), $answers)), 'the same page whatever was wrong, so nothing is learned');

        $this->get('/s/'.$live['token'])->assertOk();
    }

    public function test_the_raw_token_is_never_stored(): void
    {
        $made = $this->link();

        $this->assertSame(0, \DB::table('sales_share_links')->where('token_hash', $made['token'])->count());
        $this->assertSame(hash('sha256', $made['token']), \DB::table('sales_share_links')->where('id', $made['link']->id)->value('token_hash'));
        $this->assertStringNotContainsString($made['token'], json_encode(\DB::table('finance_audit_log')->get()->all()));
    }

    public function test_guessing_many_different_tokens_meets_a_wall_per_address(): void
    {
        config(['sales.share.throttle_per_minute' => 30, 'sales.share.throttle_per_ip_per_minute' => 60]);

        $statuses = [];
        for ($i = 0; $i < 70; $i++) {
            $statuses[] = $this->get('/s/'.Str::random(40))->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'every guess used to get a fresh bucket; the per-address limit must stop a sweep');
        $this->assertSame(404, $statuses[0]);
    }

    public function test_a_link_only_ever_shows_the_one_thing_it_was_made_for(): void
    {
        $a = $this->issued(null, ['reference' => 'ALPHA']);
        $b = $this->issued(null, ['reference' => 'BRAVO']);
        $made = LedgerContext::run($this->ledger, fn () => app(ShareLinks::class)->forDocument($a, $this->owner));

        $html = $this->get('/s/'.$made['token'])->assertOk()->getContent();
        $this->assertStringContainsString($a->number, $html);
        $this->assertStringNotContainsString($b->number, $html);
        $this->assertStringNotContainsString('Sentinel', $html);
    }
}
