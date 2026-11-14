<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * The header carries the shared Dot UI (dot-ui): the search pill and the platform switcher. Presentation changed; the
 * search form, its route, parameter, permissions and results did not, and the switcher only ever shows what InfoDot says
 * the company whose books are open holds (the one the entitlement check asks about), linking through InfoDot's /go
 * (never straight to a host, never with a token). The ledger switcher in the sidebar is a different feature and stays.
 */
class DotUiHeaderTest extends TestCase
{
    use BuildsCompanyLedgers, RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosystem.infodot' => ['redeem_url' => self::REDEEM, 'platform' => 'finance', 'secret' => 'a-test-secret', 'timeout' => 2, 'provision' => true, 'entitlement_check' => true],
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
        ]);
    }

    private static function url(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** InfoDot's read endpoint, as it answers this platform about one company. */
    private static function body(string $uuid, array $held = ['finance', 'files', 'infodot']): array
    {
        $rows = [];
        foreach (['finance' => 'Dot.Finance', 'files' => 'Dot.Files', 'engage' => 'Dot.Engage', 'hr' => 'Dot.HR', 'infodot' => 'InfoDot', 'press' => 'Dot.Press'] as $key => $name) {
            $rows[] = ['key' => $key, 'name' => $name, 'status' => 'active', 'entitled' => in_array($key, $held, true), 'is_core' => $key === 'finance', 'access_mode' => 'everyone'];
        }

        return ['platform' => 'finance', 'company' => ['uuid' => $uuid, 'personal' => false], 'platforms' => $rows];
    }

    private function fakeInfoDot(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    /** @return array{0: User, 1: Team} a person with one company, the way InfoDot sign-in makes it */
    private function acme(string $name = 'Acme Ltd'): array
    {
        $owner = $this->user();

        return [$owner, $this->company($owner, $name)];
    }

    /** The markup of the switcher only, so the assertions cannot be satisfied by the rest of the page. */
    private function switcherHtml(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="dsw dsw--header".*?<p class="dsw-sr" data-dsw-live[^>]*><\/p>\s*<\/div>\s*<\/div>\s*<\/div>/s', $html, $m), 'the header carries the switcher');

        return $m[0];
    }

    /** The header pill only (its wrapper through the closing tag of the root). */
    private function pillHtml(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="fx-hsearch">.*?<\/form>\s*<\/div>\s*<\/div>/s', $html, $m), 'the header carries the pill');

        return $m[0];
    }

    /** @return list<string> the descriptions the transactions page LISTS (not the search it echoes back) */
    private function listed($response): array
    {
        preg_match_all('/<span class="fx-cell-main">([^<]+)<\/span>/', $response->getContent(), $m);

        return $m[1];
    }

    // ── The search pill ──────────────────────────────────────────────────────

    public function test_the_header_search_keeps_its_form_route_and_parameter(): void
    {
        [$owner, $team] = $this->acme();
        $this->fakeInfoDot([self::url($team->infodot_team_uuid) => Http::response(self::body($team->infodot_team_uuid))]);

        $html = $this->inCompany($owner, $team)->get(route('transactions.index', ['search' => 'rent']))->assertOk()->getContent();
        $pill = $this->pillHtml($html);

        $this->assertSame(1, preg_match('/<form role="search" method="get"\s+action="([^"]+)"/', $pill, $form), 'a GET search form');
        $this->assertSame(route('transactions.index'), html_entity_decode($form[1]));
        $this->assertMatchesRegularExpression('/<input[^>]*\btype="search"[^>]*\bname="search"/', $pill, 'same parameter');
        $this->assertStringContainsString('placeholder="Search transactions"', $pill);
        $this->assertStringContainsString('class="dsr-input"', $pill, 'the shared pill');
        $this->assertStringContainsString('id="fx-search"', $pill, 'the input keeps its id');
        $this->assertStringNotContainsString('<form class="fx-search"', $html, 'the old search form is gone');
    }

    public function test_the_header_search_shows_no_invented_shortcut(): void
    {
        [$owner, $team] = $this->acme();
        $this->fakeInfoDot([self::url($team->infodot_team_uuid) => Http::response(self::body($team->infodot_team_uuid))]);

        $pill = $this->pillHtml($this->inCompany($owner, $team)->get('/dashboard')->getContent());

        $this->assertStringNotContainsString('dsr-kbd', $pill, 'Ctrl K already opens the quick-actions bar: the pill claims no shortcut');
        $this->assertStringNotContainsString('data-dsr-shortcut', $pill);
        $this->assertStringContainsString('dsr dsr--nohint', $pill);
    }

    public function test_the_quick_actions_bar_and_its_buttons_are_still_there(): void
    {
        $html = $this->actingAs($this->user())->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('id="fx-command"', $html);
        $this->assertSame(2, substr_count($html, 'data-fx-command-open'), 'the wide and the phone button');
    }

    public function test_a_guest_sees_no_search_and_no_switcher(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('dsr-input', false)->assertDontSee('data-dsw', false);
    }

    // ── Search results: the logic and the tenant isolation are unchanged ────

    public function test_search_filters_by_description_and_never_shows_another_ledger(): void
    {
        $this->fakeInfoDot([]);
        $owner = $this->user();
        $acme = $this->company($owner, 'Acme Ltd');
        $globex = $this->company($owner, 'Globex Ltd');
        $mine = $this->companyAccount($acme);
        $theirs = $this->companyAccount($globex);
        $this->entryOn($mine, ['description' => 'Rent for the Acme depot']);
        $this->entryOn($mine, ['description' => 'Stationery']);
        $this->entryOn($theirs, ['description' => 'Rent for the Globex depot']);

        $found = $this->inCompany($owner, $acme)->get(route('transactions.index', ['search' => 'Rent']))->assertOk();
        $this->assertSame(['Rent for the Acme depot'], $this->listed($found));

        $none = $this->inCompany($owner, $acme)->get(route('transactions.index', ['search' => 'Globex']))->assertOk();
        $this->assertSame([], $this->listed($none), 'the other ledger is never searched');

        $other = $this->inCompany($owner, $globex)->get(route('transactions.index', ['search' => 'Rent']))->assertOk();
        $this->assertSame(['Rent for the Globex depot'], $this->listed($other));
    }

    public function test_search_still_needs_a_signed_in_person(): void
    {
        $this->get(route('transactions.index', ['search' => 'Rent']))->assertRedirect(route('login'));
    }

    public function test_the_in_page_filters_are_the_shared_compact_filter_with_the_same_names(): void
    {
        $this->fakeInfoDot([]);
        $user = $this->user();

        $pages = [
            [route('transactions.index'), 'search'],
            [route('parties.index'), 'q'],
            [route('sales.customers.index'), 'q'],
            [route('purchases.suppliers.index'), 'q'],
            [route('purchases.bills.index'), 'q'],
        ];
        foreach ($pages as [$url, $name]) {
            $html = $this->inPersonal($user)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<input[^>]*\bname="'.$name.'"[^>]*\bclass="dsr-filter"|<input[^>]*\bclass="dsr-filter"[^>]*\bname="'.$name.'"/', $html, $url);
            $this->assertStringNotContainsString('class="fx-input" type="search"', $html, $url);
        }
    }

    // ── The switcher ────────────────────────────────────────────────────────

    public function test_a_member_sees_the_switcher_with_what_their_company_holds_and_the_current_platform_marked(): void
    {
        [$owner, $team] = $this->acme();
        $uuid = $team->infodot_team_uuid;
        $this->fakeInfoDot([self::url($uuid) => Http::response(self::body($uuid))]);

        $html = $this->inCompany($owner, $team)->get('/dashboard')->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="ok"', $panel);
        $this->assertStringContainsString('data-platform="finance"', $panel);
        $this->assertStringContainsString('Acme Ltd', $panel, 'the sentence names the company');

        $this->assertSame(1, preg_match('/<div class="dsw-bay is-current" aria-current="page">.*?You are here/s', $panel, $here));
        $this->assertStringContainsString('Dot.Finance', $here[0]);

        $this->assertStringContainsString('href="https://infodot.test/go/files?company='.$uuid.'"', $panel);
        $this->assertStringContainsString('href="https://infodot.test/home"', $panel);
        $this->assertStringNotContainsString('/go/hr', $panel);
        $this->assertStringContainsString('href="https://infodot.test/platforms/hr"', $panel);

        $this->assertStringNotContainsString('token', strtolower($panel));
        $this->assertStringNotContainsString('a-test-secret', $html);
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href, 'every way out of the switcher goes through InfoDot');
        }
    }

    public function test_the_switcher_asks_infodot_about_the_active_company_only_with_the_service_secret(): void
    {
        [$owner, $team] = $this->acme();
        $other = $this->company($owner, 'Globex Ltd');
        $this->fakeInfoDot([self::url($team->infodot_team_uuid) => Http::response(self::body($team->infodot_team_uuid))]);

        $this->inCompany($owner, $team)->get('/dashboard')->assertOk();

        Http::assertSent(fn (OutgoingRequest $request) => str_contains($request->url(), '/companies/'.$team->infodot_team_uuid.'/platforms')
            && $request->hasHeader('Authorization', 'Bearer a-test-secret')
            && $request->hasHeader('X-Dot-Platform', 'finance'));
        Http::assertNotSent(fn (OutgoingRequest $request) => str_contains($request->url(), $other->infodot_team_uuid));
    }

    public function test_the_switcher_follows_the_active_ledger_when_the_person_changes_it(): void
    {
        [$owner, $acme] = $this->acme();
        $globex = $this->company($owner, 'Globex Ltd');
        $this->fakeInfoDot([
            self::url($acme->infodot_team_uuid) => Http::response(self::body($acme->infodot_team_uuid, ['finance', 'infodot', 'press'])),
            self::url($globex->infodot_team_uuid) => Http::response(self::body($globex->infodot_team_uuid, ['finance', 'infodot', 'hr'])),
        ]);

        $a = $this->switcherHtml($this->inCompany($owner, $acme)->get('/dashboard')->getContent());
        $g = $this->switcherHtml($this->inCompany($owner, $globex)->get('/dashboard')->getContent());

        $this->assertStringContainsString('/go/press?company='.$acme->infodot_team_uuid, $a);
        $this->assertStringNotContainsString($globex->infodot_team_uuid, $a);
        $this->assertStringContainsString('/go/hr?company='.$globex->infodot_team_uuid, $g);
        $this->assertStringNotContainsString($acme->infodot_team_uuid, $g);
    }

    public function test_a_team_in_the_address_wins_over_the_active_ledger_exactly_as_the_entitlement_check_decides(): void
    {
        [$owner, $acme] = $this->acme();
        $globex = $this->company($owner, 'Globex Ltd');
        $this->fakeInfoDot([
            self::url($acme->infodot_team_uuid) => Http::response(self::body($acme->infodot_team_uuid, ['finance', 'infodot', 'press'])),
            self::url($globex->infodot_team_uuid) => Http::response(self::body($globex->infodot_team_uuid, ['finance', 'infodot', 'hr'])),
        ]);

        // Acme is the active ledger, Globex's team page is open: the page is about Globex, and so is the switcher.
        $html = $this->inCompany($owner, $acme)->get(route('teams.show', $globex))->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('/go/hr?company='.$globex->infodot_team_uuid, $panel);
        $this->assertStringNotContainsString($acme->infodot_team_uuid, $panel);
    }

    public function test_a_team_in_the_address_the_person_does_not_belong_to_does_not_choose_the_company(): void
    {
        [$owner, $acme] = $this->acme();
        [, $stranger] = $this->acme('Stranger Ltd');
        $this->fakeInfoDot([self::url($acme->infodot_team_uuid) => Http::response(self::body($acme->infodot_team_uuid))]);

        $this->inCompany($owner, $acme)->get(route('teams.show', $stranger));

        Http::assertNotSent(fn (OutgoingRequest $request) => str_contains($request->url(), $stranger->infodot_team_uuid));
    }

    public function test_two_people_in_different_companies_never_see_each_others_switcher(): void
    {
        [$ownerA, $acme] = $this->acme();
        [$ownerB, $globex] = $this->acme('Globex Ltd');
        $this->fakeInfoDot([
            self::url($acme->infodot_team_uuid) => Http::response(self::body($acme->infodot_team_uuid, ['finance', 'infodot', 'press'])),
            self::url($globex->infodot_team_uuid) => Http::response(self::body($globex->infodot_team_uuid, ['finance', 'infodot', 'hr'])),
        ]);

        $a = $this->switcherHtml($this->inCompany($ownerA, $acme)->get('/dashboard')->getContent());
        $g = $this->switcherHtml($this->inCompany($ownerB, $globex)->get('/dashboard')->getContent());

        $this->assertStringNotContainsString('/go/hr', $a);
        $this->assertStringNotContainsString($globex->infodot_team_uuid, $a);
        $this->assertStringNotContainsString('/go/press', $g);
        $this->assertStringNotContainsString($acme->infodot_team_uuid, $g);
    }

    public function test_when_infodot_cannot_be_reached_the_switcher_says_so_and_never_shows_active(): void
    {
        // The entitlement check and the switcher both get a 503: the check carries on (as it always did), the switcher degrades.
        [$owner, $team] = $this->acme();
        $this->fakeInfoDot([self::url($team->infodot_team_uuid) => Http::response('down', 503)]);

        $html = $this->inCompany($owner, $team)->get('/dashboard')->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="degraded"', $panel);
        $this->assertStringContainsString('Could not check your platforms just now', $panel);
        $this->assertStringNotContainsString('Active', $panel);
        $this->assertStringContainsString('/go/files', $panel, 'the core set still leaves through InfoDot, which decides again');
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href);
        }
    }

    public function test_a_connection_failure_never_breaks_the_page(): void
    {
        [$owner, $team] = $this->acme();
        $this->fakeInfoDot([self::url($team->infodot_team_uuid) => fn () => throw new ConnectionException('refused')]);

        $html = $this->inCompany($owner, $team)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('data-state="degraded"', $html);
    }

    public function test_a_company_that_does_not_hold_finance_is_still_refused_exactly_as_before(): void
    {
        // The R-50 check is untouched: a clear "no" is the plain 403 page, and the switcher never renders for it.
        [$owner, $team] = $this->acme();
        $this->fakeInfoDot([self::url($team->infodot_team_uuid) => Http::response(self::body($team->infodot_team_uuid, ['infodot']))]);

        $this->inCompany($owner, $team)->get('/dashboard')->assertStatus(403)->assertDontSee('data-dsw', false);
    }

    public function test_a_personal_ledger_gets_the_honest_sentence_and_infodot_is_not_asked(): void
    {
        $this->fakeInfoDot([]);

        $html = $this->inPersonal($this->user())->get('/dashboard')->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="personal"', $panel);
        $this->assertStringContainsString('dsw-personal', $panel);
        $this->assertStringNotContainsString('dsw-bay', $panel, 'no platforms are listed for a workspace that is not a company');
        $this->assertStringContainsString('href="https://infodot.test/platforms"', $panel);
        Http::assertNothingSent();
    }

    public function test_the_ledger_switcher_in_the_sidebar_is_still_there_and_still_posts_to_the_same_route(): void
    {
        [$owner, $team] = $this->acme();
        $this->fakeInfoDot([self::url($team->infodot_team_uuid) => Http::response(self::body($team->infodot_team_uuid))]);

        $html = $this->inCompany($owner, $team)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('class="fx-ledger-switch ledger-switch"', $html);
        $this->assertStringContainsString('action="'.route('ledger.context.switch').'"', $html);
        $this->assertStringContainsString('Acme Ltd', $html);
    }

    // ── CSP: self-hosted, nothing inline, nothing external ──────────────────

    public function test_the_shared_assets_are_served_from_this_site_and_resolve(): void
    {
        [$owner, $team] = $this->acme();
        $this->fakeInfoDot([self::url($team->infodot_team_uuid) => Http::response(self::body($team->infodot_team_uuid))]);

        $response = $this->inCompany($owner, $team)->get('/dashboard')->assertOk();
        $html = $response->getContent();

        foreach (['css/dot-fonts.css', 'css/dot-tokens.css', 'css/dot-search.css', 'css/dot-switcher-fonts.css', 'css/dot-switcher.css', 'js/dot-switcher.js'] as $asset) {
            $this->assertStringContainsString('"/dot-ui/'.$asset.'"', $html, $asset.' is linked from this site');
            $this->assertFileExists(public_path('dot-ui/'.$asset));
        }
        $this->assertMatchesRegularExpression('#<script src="/dot-ui/js/dot-switcher.js" defer nonce="[^"]+"#', $html, 'the script carries the CSP nonce');

        foreach (glob(public_path('dot-ui/css/*.css')) as $css) {
            preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/', (string) file_get_contents($css), $urls);
            foreach ($urls[1] as $url) {
                $this->assertDoesNotMatchRegularExpression('#^(https?:)?//#', $url, basename($css).' reaches outside this site');
                $this->assertFileExists(dirname($css).'/'.$url, basename($css).' -> '.$url);
            }
        }

        preg_match_all('/<img src="([^"]+)" alt="Dot\./', $this->switcherHtml($html), $imgs);
        $this->assertNotEmpty($imgs[1]);
        foreach ($imgs[1] as $src) {
            $this->assertStringStartsWith('/dot-ui/img/platforms/', $src);
            $this->assertFileExists(public_path($src));
        }

        // The policy gained no origin: nothing outside 'self'.
        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('http', preg_replace('#report-uri[^;]*#', '', $policy));
        $this->assertStringNotContainsString('unsafe-inline', $policy);
    }

    public function test_the_shared_markup_has_no_inline_style_or_script_and_the_page_has_no_style_attributes_it_added(): void
    {
        [$owner, $team] = $this->acme();
        $this->fakeInfoDot([self::url($team->infodot_team_uuid) => Http::response(self::body($team->infodot_team_uuid))]);
        $html = $this->inCompany($owner, $team)->get('/dashboard')->getContent();

        foreach ([$this->pillHtml($html), $this->switcherHtml($html)] as $fragment) {
            $this->assertStringNotContainsString(' style=', $fragment);
            $this->assertStringNotContainsString('<style', $fragment);
            $this->assertStringNotContainsString('<script', $fragment);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $fragment, 'no inline event handlers');
        }
    }

    public function test_the_company_the_switcher_uses_is_the_one_the_entitlement_check_uses(): void
    {
        // One rule, one class: the middleware and the layout both read it from EntitledCompany.
        $middleware = (string) file_get_contents(app_path('Http/Middleware/EnsureCompanyEntitled.php'));
        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('EntitledCompany', $middleware);
        $this->assertStringContainsString('EntitledCompany', $layout);
    }
}
