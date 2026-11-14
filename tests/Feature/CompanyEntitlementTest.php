<?php

namespace Tests\Feature;

use App\Console\Commands\GenerateRecurringTransactions;
use App\Http\Middleware\EnsureCompanyEntitled;
use App\Http\Middleware\ResolveLedgerContext;
use App\Models\Account;
use App\Models\Reporting\ReportExport;
use App\Models\Shared\Party;
use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\EntitlementClient;
use App\Services\Integrations\SignedRequest;
use App\Services\Shared\Attachments;
use App\Support\Ledger\LedgerContext;
use App\Support\Ledger\LedgerContextManager;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * R-50: Dot.Finance honours the company's entitlement. At sign-in only companies InfoDot marks entitled are admitted;
 * during a session the signed-in area asks InfoDot whether the active company still holds the platform. A clear "no"
 * is the plain 403 page; every failure lets the person carry on. Response bodies follow InfoDot's own tests
 * (CompanyPlatformsEndpointTest, the redeem controller).
 */
class CompanyEntitlementTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    private const PERSONAL = '44444444-4444-4444-8444-444444444444';

    private string $credential;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credential = Str::random(40);

        config(['ecosystem.infodot' => [
            'redeem_url' => self::REDEEM, 'platform' => 'finance', 'secret' => $this->credential, 'timeout' => 2, 'provision' => true,
            'entitlement_check' => true,
        ]]);
        config(['ecosystem.platforms.infodot.url' => 'https://infodot.test']);
    }

    // ------------------------------------------------------------------ helpers

    private static function readUrl(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** The read endpoint's body, as InfoDot's CompanyPlatformsEndpointTest asserts it. */
    private static function platforms(string $uuid, bool $entitled, string $status): array
    {
        return [
            'platform' => 'finance',
            'company' => ['uuid' => $uuid, 'personal' => false],
            'platforms' => [
                ['key' => 'finance', 'name' => 'Dot.Finance', 'status' => $status, 'entitled' => $entitled, 'is_core' => true, 'access_mode' => 'everyone'],
                ['key' => 'forms', 'name' => 'Dot.Forms', 'status' => 'subscription_expired', 'entitled' => false, 'is_core' => false],
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'payment_required', 'entitled' => false, 'is_core' => false],
                ['key' => 'infodot', 'name' => 'InfoDot', 'status' => 'included', 'entitled' => true, 'is_core' => true],
            ],
        ];
    }

    private function infodotSays(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    /** @return array{0: User, 1: Team, 2: Account} an owner, their InfoDot company, and an account in its books */
    private function acme(string $uuid = self::ACME, string $name = 'Acme Ltd', ?User $owner = null): array
    {
        $owner ??= $this->user();
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $account = $this->companyAccount($team, ['name' => 'Reserve at '.$name]);

        return [$owner->fresh(), $team, $account];
    }

    private function fromInfodot(): array
    {
        return ['Origin' => 'https://infodot.test'];
    }

    private function redeemTeam(string $uuid, string $name, string $role, ?bool $entitled = null, ?string $access = null, bool $personal = false): array
    {
        $team = ['id' => random_int(1, 999), 'uuid' => $uuid, 'name' => $name, 'personal' => $personal, 'role' => $role];

        return $entitled === null ? $team : $team + ['entitled' => $entitled, 'access' => $access];
    }

    private function signIn(string $email, array $teams, ?string $current = null)
    {
        $this->infodotSays([self::REDEEM => Http::response([
            'platform' => 'finance',
            'user' => ['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'email_verified' => true],
            'teams' => $teams,
            'current_team_id' => 1,
            'current_team_uuid' => $current,
        ])]);

        auth()->logout();

        return $this->post(route('ecosystem.auth'), ['token' => 'one-time'], $this->fromInfodot());
    }

    // ------------------------------------------------------------------ sign-in (redeem)

    public function test_a_company_marked_entitled_is_admitted_at_sign_in(): void
    {
        $this->signIn('ann@acme.test', [$this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', true)], self::ACME)->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();
        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($team->id, $user->current_team_id);
    }

    public function test_a_company_marked_not_entitled_is_not_created_updated_or_made_current(): void
    {
        // Globex exists here already under an older name, with Ann as a viewer working in it.
        [, $globex] = $this->acme(self::GLOBEX, 'Globex (old name)');
        $ann = User::factory()->withPersonalTeam()->create(['email' => 'ann@acme.test']);
        $globex->users()->attach($ann->id, ['role' => 'viewer']);
        $ann->forceFill(['current_team_id' => $globex->id])->save();

        $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', true),
            $this->redeemTeam(self::GLOBEX, 'Globex Corporation', 'admin', false, 'suspended'),
            $this->redeemTeam('33333333-3333-4333-8333-333333333333', 'Initech', 'owner', false, 'not_activated'),
        ], self::GLOBEX)->assertRedirect(route('dashboard'));

        $ann->refresh();
        $acme = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();

        $this->assertNull(Team::where('infodot_team_uuid', '33333333-3333-4333-8333-333333333333')->first(), 'a company that does not hold the platform is not created');
        $this->assertSame('Globex (old name)', $globex->fresh()->name, 'nor renamed');
        $this->assertSame('viewer', $globex->users()->where('users.id', $ann->id)->first()->membership->role, 'nor is the role changed');
        $this->assertTrue($ann->belongsToTeam($globex), 'membership is left as it was');
        $this->assertSame($acme->id, $ann->current_team_id, 'and it cannot be the current company');
    }

    public function test_without_the_key_sign_in_behaves_as_before(): void
    {
        $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner'),
            $this->redeemTeam(self::GLOBEX, 'Globex', 'admin'),
        ], self::GLOBEX)->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();

        $this->assertNotNull(Team::where('infodot_team_uuid', self::ACME)->first());
        $this->assertSame(Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail()->id, $user->current_team_id);
    }

    public function test_when_no_company_is_entitled_the_person_gets_the_plain_page_and_is_not_signed_in(): void
    {
        $response = $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::PERSONAL, "Ann's Team", 'owner', false, 'no_personal_access', personal: true),
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', false, 'payment_required'),
        ], self::ACME);

        $response->assertStatus(403)
            ->assertSee('Dot.Finance needs a payment for Acme Ltd')
            ->assertSee('href="https://infodot.test/platforms/finance"', false)
            ->assertSee('Open Platforms in InfoDot')
            ->assertDontSee('Whoops')
            ->assertDontSee('Stack trace');

        $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action'));
        $this->assertGuest();
        $this->assertNull(User::where('email', 'ann@acme.test')->first(), 'no account is created for nothing');
        $this->assertSame(0, Team::whereNotNull('infodot_team_uuid')->count());
    }

    public function test_the_page_escapes_the_company_name(): void
    {
        $this->signIn('ann@acme.test', [$this->redeemTeam(self::ACME, '<script>alert(1)</script>', 'owner', false, 'not_activated')], self::ACME)
            ->assertStatus(403)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    // ------------------------------------------------------------------ during a session

    public function test_an_entitled_company_carries_on(): void
    {
        [$owner, $team] = $this->acme();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'included'))]);

        $this->inCompany($owner, $team)->get(route('accounts.index'))->assertOk();

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://infodot.test/api/ecosystem/companies/'.self::ACME.'/platforms')
            && $request['platform'] === 'finance'
            && $request->hasHeader('X-Dot-Platform', 'finance')
            && $request->hasHeader('Authorization', 'Bearer '.$this->credential));
    }

    public static function refusals(): array
    {
        return [
            'not switched on' => ['free_while_pricing_finalised', 'Dot.Finance is not switched on for Acme Ltd'],
            'suspended' => ['suspended', 'Dot.Finance is suspended for Acme Ltd'],
            'payment needed' => ['payment_required', 'Dot.Finance needs a payment for Acme Ltd'],
            'ended' => ['subscription_expired', 'Dot.Finance has ended for Acme Ltd'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_company_that_no_longer_holds_the_platform_gets_403_and_no_data(string $status, string $title): void
    {
        [$owner, $team, $account] = $this->acme();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, $status))]);

        $urls = [
            route('dashboard'), route('accounts.index'), route('transactions.create'), route('insights.index'), route('sales.invoices.index'),
            route('bank.statements.index'), route('controls.index'), route('parties.index'), route('intel.assistant.index'), route('teams.show', $team),
        ];

        foreach ($urls as $url) {
            $response = $this->inCompany($owner, $team)->get($url)->assertStatus(403)
                ->assertSee($title)
                ->assertSee('href="https://infodot.test/platforms/finance"', false)
                ->assertDontSee('Reserve at Acme Ltd');

            $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action'), $url);
        }

        // Nothing is changed either.
        $this->inCompany($owner, $team)->post(route('accounts.store'), ['name' => 'Sneaky', 'type' => 'checking', 'currency' => 'ZAR'])->assertStatus(403);
        $this->assertSame(0, Account::withoutGlobalScopes()->where('name', 'Sneaky')->count());
        $this->assertSame('Reserve at Acme Ltd', $account->fresh()->name, 'the data is untouched');
    }

    public static function failures(): array
    {
        return [
            'bad secret (401)' => [fn () => Http::response(['error' => 'invalid_request'], 401)],
            'platform switched off (403)' => [fn () => Http::response(['error' => 'invalid_request'], 403)],
            'unknown company (404)' => [fn () => Http::response(['error' => 'not_found'], 404)],
            'throttled (429)' => [fn () => Http::response('', 429)],
            'server error (500)' => [fn () => Http::response('boom', 500)],
            'not json' => [fn () => Http::response('<html>maintenance</html>', 200)],
            'no platforms list' => [fn () => Http::response(['platform' => 'finance', 'company' => ['uuid' => self::ACME, 'personal' => false]])],
            'own row missing' => [fn () => Http::response(['platform' => 'finance', 'company' => ['uuid' => self::ACME, 'personal' => false], 'platforms' => [
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'suspended', 'entitled' => false, 'is_core' => false],
            ]])],
            'entitled is not a boolean' => [fn () => Http::response(['platform' => 'finance', 'platforms' => [['key' => 'finance', 'status' => 'suspended', 'entitled' => 'false']]])],
            'answer for another platform' => [fn () => Http::response(['platform' => 'hr', 'platforms' => [['key' => 'finance', 'status' => 'suspended', 'entitled' => false]]])],
            'connection error or timeout' => [fn () => fn () => throw new ConnectionException('timed out')],
        ];
    }

    #[DataProvider('failures')]
    public function test_any_failure_lets_the_person_carry_on_and_is_logged_once(Closure $answer): void
    {
        [$owner, $team] = $this->acme();
        $calls = 0;
        $reply = $answer();
        $this->infodotSays([self::readUrl(self::ACME) => function () use (&$calls, $reply) {
            $calls++;

            return $reply instanceof Closure ? $reply() : $reply;
        }]);
        Log::spy();

        $this->inCompany($owner, $team)->get(route('accounts.index'))->assertOk()->assertSee('Reserve at Acme Ltd');
        $this->inCompany($owner, $team)->get(route('dashboard'))->assertOk();

        $this->assertSame(1, $calls, 'one call for the cache window');
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'InfoDot entitlement check gave no answer'));
    }

    public function test_a_failure_is_remembered_for_a_minute_and_an_answer_for_ten(): void
    {
        [$owner, $team] = $this->acme();
        $this->infodotSays([self::readUrl(self::ACME) => Http::sequence()
            ->push('boom', 500)
            ->push(self::platforms(self::ACME, false, 'suspended'))
            ->push(self::platforms(self::ACME, true, 'included'))]);

        $this->inCompany($owner, $team)->get(route('accounts.index'))->assertOk();

        $this->travel(EntitlementClient::FAILURE_TTL + 1)->seconds();
        $this->inCompany($owner, $team)->get(route('accounts.index'))->assertStatus(403);

        $this->travel(EntitlementClient::ANSWER_TTL - 5)->seconds();
        $this->inCompany($owner, $team)->get(route('accounts.index'))->assertStatus(403);
        Http::assertSentCount(2);

        $this->travel(10)->seconds();
        $this->inCompany($owner, $team)->get(route('accounts.index'))->assertOk();
        Http::assertSentCount(3);
    }

    public function test_no_secret_configured_carries_on_without_calling(): void
    {
        [$owner, $team] = $this->acme();
        config(['ecosystem.infodot.secret' => null]);
        $this->infodotSays([]);

        $this->inCompany($owner, $team)->get(route('accounts.index'))->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_answer_is_cached_per_company(): void
    {
        [$owner, $team] = $this->acme();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'included'))]);

        $this->inCompany($owner, $team)->get(route('accounts.index'))->assertOk();
        $this->inCompany($owner, $team)->get(route('dashboard'))->assertOk();

        Http::assertSentCount(1);
    }

    public function test_with_the_flag_off_no_call_is_made(): void
    {
        [$owner, $team] = $this->acme();
        config(['ecosystem.infodot.entitlement_check' => false]);
        $this->infodotSays([]);

        $this->inCompany($owner, $team)->get(route('accounts.index'))->assertOk();
        $this->inCompany($owner, $team)->get(route('dashboard'))->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_flag_is_on_by_default(): void
    {
        $this->assertTrue((require config_path('ecosystem.php'))['infodot']['entitlement_check']);
        $this->assertStringContainsString('FINANCE_ENTITLEMENT_CHECK=true', file_get_contents(base_path('.env.example')));
    }

    public function test_the_personal_ledger_and_a_team_infodot_does_not_know_are_not_asked_about(): void
    {
        $this->infodotSays([]);

        $solo = $this->user();
        $this->inPersonal($solo)->get(route('accounts.index'))->assertOk();

        $local = Team::forceCreate(['user_id' => $solo->id, 'name' => 'Local Co', 'personal_team' => false]);
        $this->actingAs($solo->fresh())->get(route('teams.show', $local))->assertOk();

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ personal ledgers (individuals hold the free core only)

    /** Ann signs in with one company and a personal workspace marked as given, then works in her personal ledger. */
    private function signInThenWorkPersonally(?bool $personalEntitled): array
    {
        $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::PERSONAL, "Ann's Team", 'owner', $personalEntitled, $personalEntitled === false ? 'no_personal_access' : null, personal: true),
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', $personalEntitled === null ? null : true),
        ], self::ACME)->assertRedirect(route('dashboard'));

        // The test client does not carry the session between requests; hand over what sign-in remembered.
        $remembered = session()->has(EnsureCompanyEntitled::PERSONAL) ? [EnsureCompanyEntitled::PERSONAL => session(EnsureCompanyEntitled::PERSONAL)] : [];

        $ann = User::where('email', 'ann@acme.test')->firstOrFail();
        $mine = Account::factory()->create(['user_id' => $ann->id, 'name' => 'Private rainy day']);

        $this->actingAs($ann->fresh())->withSession($remembered + [LedgerContextManager::SESSION_KEY => 'personal']);

        return [$ann->fresh(), $mine, Team::where('infodot_team_uuid', self::ACME)->firstOrFail()];
    }

    public function test_a_personal_ledger_infodot_marked_not_entitled_is_refused_without_asking_the_read_endpoint(): void
    {
        [$ann, $mine, $acme] = $this->signInThenWorkPersonally(false);
        $this->assertFalse(session(EnsureCompanyEntitled::PERSONAL));

        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'included'))]);

        foreach ([route('dashboard'), route('accounts.index'), route('transactions.create'), route('sales.invoices.index')] as $url) {
            $response = $this->get($url)->assertStatus(403)
                ->assertSee('Dot.Finance needs a company that has switched it on')
                ->assertSee('href="https://infodot.test/platforms/finance"', false)
                ->assertSee('name="context" value="'.$acme->id.'"', false)
                ->assertDontSee('name="context" value="personal"', false)
                ->assertDontSee('Private rainy day');

            $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action'), $url);
        }

        $this->post(route('accounts.store'), ['name' => 'Sneaky', 'type' => 'checking', 'currency' => 'ZAR'])->assertStatus(403);
        Http::assertNothingSent();

        // Nothing in the ledger is deleted or altered.
        $this->assertSame('Private rainy day', $mine->fresh()->name);
        $this->assertSame(0, Account::withoutGlobalScopes()->where('name', 'Sneaky')->count());
        $this->get(route('profile.show'))->assertOk();

        // The company can still be switched to, and works.
        $this->post(route('ledger.context.switch'), ['context' => $acme->id])->assertRedirect(route('dashboard'));
        $this->get(route('accounts.index'))->assertOk();
    }

    public function test_a_personal_ledger_infodot_marked_entitled_carries_on_even_while_a_company_is_refused(): void
    {
        [$ann, $mine] = $this->signInThenWorkPersonally(true);
        $this->assertTrue(session(EnsureCompanyEntitled::PERSONAL));
        $this->infodotSays([]);

        $this->get(route('accounts.index'))->assertOk()->assertSee('Private rainy day');
        Http::assertNothingSent();
    }

    public function test_with_no_key_at_sign_in_nothing_is_remembered_and_the_personal_ledger_carries_on(): void
    {
        [$ann] = $this->signInThenWorkPersonally(null);
        $this->assertFalse(session()->has(EnsureCompanyEntitled::PERSONAL));
        $this->infodotSays([]);

        $this->get(route('accounts.index'))->assertOk()->assertSee('Private rainy day');
        Http::assertNothingSent();
    }

    public function test_a_company_refused_for_payment_on_another_platform_still_reaches_finance(): void
    {
        // The read endpoint lists every platform; only Dot.Finance's own row decides.
        [$owner, $team] = $this->acme();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'included'))]);

        $this->inCompany($owner, $team)->get(route('dashboard'))->assertOk();
    }

    public function test_a_later_sign_in_without_the_key_forgets_an_earlier_refusal(): void
    {
        $this->signInThenWorkPersonally(false);
        $this->signIn('ann@acme.test', [$this->redeemTeam(self::PERSONAL, "Ann's Team", 'owner', personal: true), $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner')], self::ACME)->assertRedirect();

        $this->assertFalse(session()->has(EnsureCompanyEntitled::PERSONAL));
    }

    public function test_the_personal_refusal_is_off_with_the_flag(): void
    {
        $this->signInThenWorkPersonally(false);
        config(['ecosystem.infodot.entitlement_check' => false]);

        $this->get(route('accounts.index'))->assertOk();
    }

    // ------------------------------------------------------------------ live updates (Livewire)

    /** A real Livewire update request (the test harness skips persistent middleware): rename the company on its settings page. */
    private function renameCompany(string $snapshot, string $name)
    {
        return $this->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => ['state.name' => $name],
            'calls' => [['path' => '', 'method' => 'updateTeamName', 'params' => []]],
        ]]], ['X-Livewire' => 'true']);
    }

    private function nameFormSnapshot(Team $team): string
    {
        $page = $this->get(route('teams.show', $team))->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $page, $matches);
        $snapshot = collect($matches[1])->map(fn ($s) => html_entity_decode($s, ENT_QUOTES))->first(fn ($s) => str_contains($s, 'update-team-name-form'));
        $this->assertNotNull($snapshot, 'the team name component is on the page');

        return $snapshot;
    }

    public function test_a_livewire_update_is_refused_once_the_company_loses_the_platform(): void
    {
        [$owner, $team] = $this->acme();
        $this->infodotSays([self::readUrl(self::ACME) => Http::sequence()
            ->push(self::platforms(self::ACME, true, 'included'))
            ->push(self::platforms(self::ACME, false, 'suspended'))]);
        $this->inCompany($owner, $team);
        $snapshot = $this->nameFormSnapshot($team);

        // While the company holds the platform the action works.
        $worked = $this->renameCompany($snapshot, 'Acme Renamed');
        $this->assertNotSame(403, $worked->getStatusCode());
        $this->assertSame('Acme Renamed', $team->fresh()->name);

        // The cached answer expires, InfoDot now says no: the same action is refused and returns no component.
        $this->travel(EntitlementClient::ANSWER_TTL + 1)->seconds();
        $response = $this->renameCompany($snapshot, 'Acme Hijacked');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('"components"', $response->getContent(), 'no component comes back');
        $this->assertSame('Acme Renamed', $team->fresh()->name, 'the data is unchanged');
    }

    public function test_livewire_updates_from_the_signed_in_area_carry_the_check(): void
    {
        $this->assertContains(EnsureCompanyEntitled::class, app(PersistentMiddleware::class)->getPersistentMiddleware());
        $this->assertContains(EnsureCompanyEntitled::class, config('jetstream.middleware'));
    }

    // ------------------------------------------------------------------ exempt routes and coverage

    public function test_every_signed_in_route_group_carries_the_check_right_after_the_ledger_context(): void
    {
        $missing = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array(ResolveLedgerContext::class, $route->gatherMiddleware(), true))
            ->reject(fn ($route) => in_array(EnsureCompanyEntitled::class, $route->gatherMiddleware(), true))
            ->map(fn ($route) => $route->uri())
            ->all();

        $this->assertSame([], $missing, 'a signed-in route without the entitlement check');
        $this->assertGreaterThan(100, collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => in_array(EnsureCompanyEntitled::class, $r->gatherMiddleware(), true))->count());
    }

    public function test_money_in_integration_and_public_routes_make_no_call_and_are_never_refused(): void
    {
        [$owner, $team] = $this->acme();
        $this->infodotSays([]);

        // Signed out: public pages, health, sign-in, the handoff receiver's legacy GET, an unknown share link.
        $this->get('/')->assertOk();
        $this->get('/login')->assertOk();
        $this->get('/up')->assertOk();
        $this->get('/cookies')->assertOk();
        $this->get('/auth/ecosystem')->assertRedirect('/login');
        $this->get(route('sales.share.show', ['shareToken' => Str::random(40)]))->assertNotFound();

        // The signed server-to-server routes answer for themselves (a bad signature is their own 401, never the page).
        $this->postJson('/api/ecosystem/membership', ['event' => 'member.removed'])->assertStatus(401);
        $this->postJson('/api/integrations/emall/sales', ['x' => 1])->assertStatus(401);

        // A signed InfoDot membership event for the company is applied whatever the company's access is.
        $body = json_encode(['event' => 'member.role_changed', 'team_uuid' => self::ACME, 'email' => $owner->email, 'role' => 'admin']);
        $at = time();
        $this->call('POST', '/api/ecosystem/membership', [], [], [], $this->transformHeadersToServerVars([
            'X-InfoDot-Timestamp' => (string) $at,
            'X-InfoDot-Signature' => SignedRequest::sign($this->credential, $at, $body),
            'CONTENT_TYPE' => 'application/json',
        ]), $body)->assertStatus(202);

        // Signed in: the same, the person's own account, and signing out.
        $this->inCompany($owner, $team);
        $this->get('/')->assertOk();
        $this->get(route('profile.show'))->assertOk();
        $this->post(route('logout'))->assertRedirect();

        Http::assertNothingSent();
    }

    public function test_scheduled_commands_make_no_entitlement_call(): void
    {
        $this->acme();
        $this->infodotSays([]);

        $this->artisan('schedule:list')->assertSuccessful();
        $this->artisan(GenerateRecurringTransactions::class)->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_second_company_that_is_entitled_can_still_be_switched_to(): void
    {
        [$owner, $acme] = $this->acme();
        [, $globex] = $this->acme(self::GLOBEX, 'Globex Corporation', $owner);

        $this->infodotSays([
            self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, 'suspended')),
            self::readUrl(self::GLOBEX) => Http::response(self::platforms(self::GLOBEX, true, 'included')),
        ]);

        // The refusal names the other company and offers the switch (and the personal ledger).
        $this->inCompany($owner, $acme)->get(route('dashboard'))->assertStatus(403)
            ->assertSee('Switch to Globex Corporation')
            ->assertSee('action="'.route('ledger.context.switch').'"', false)
            ->assertSee('name="context" value="'.$globex->id.'"', false)
            ->assertSee('name="context" value="personal"', false);

        $this->post(route('ledger.context.switch'), ['context' => $globex->id])->assertRedirect(route('dashboard'));
        $this->assertSame($globex->id, session(LedgerContextManager::SESSION_KEY));

        $this->get(route('accounts.index'))->assertOk()->assertSee('Reserve at Globex Corporation');

        // The suspended company's books stay shut, even when named in the address.
        $this->get(route('accounts.index', ['context' => $acme->id]))->assertStatus(403)->assertSee('Dot.Finance is suspended for Acme Ltd');
    }

    public function test_another_companys_books_are_never_opened_or_asked_about_by_naming_it_in_the_address(): void
    {
        [$owner, $team] = $this->acme();
        [, $other] = $this->acme(self::GLOBEX, 'Globex Corporation');
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'included'))]);

        $this->inCompany($owner, $team)->get(route('accounts.index', ['context' => $other->id]))->assertNotFound();
        $this->get(route('teams.show', $other))->assertForbidden()->assertDontSee('Globex Corporation');

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), self::GLOBEX));
    }

    // ------------------------------------------------------------------ a refused company's own records stay downloadable

    /** A finished export and a stored attachment in $team's own books, with their signed links. @return array{0: string, 1: string} */
    private function recordsOf(Team $team): array
    {
        $export = LedgerContext::run(LedgerContext::company($team), function () use ($team) {
            Storage::disk('local')->put('report-exports/'.$team->id.'.csv', "Net profit,535750.00\n");

            return ReportExport::create(['team_id' => $team->id, 'requested_by' => $team->user_id, 'report_key' => 'profit-loss', 'format' => 'csv', 'params' => [], 'params_hash' => 'h'.$team->id,
                'idempotency_key' => 'k'.$team->id, 'journal_seq' => 1, 'status' => 'done', 'disk' => 'local', 'path' => 'report-exports/'.$team->id.'.csv', 'sha256' => hash('sha256', "Net profit,535750.00\n"), 'size_bytes' => 22, 'row_count' => 1, 'attempts' => 1]);
        });
        $party = Party::factory()->forCompany($team)->create();
        $file = UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n% ".$team->id."\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $attachment = app(Attachments::class)->store($file, $party, null, context: LedgerContext::company($team));

        return [
            URL::temporarySignedRoute('reports.exports.download', now()->addMinutes(5), ['reportExport' => $export->id]),
            app(Attachments::class)->url($attachment),
        ];
    }

    public function test_a_refused_company_can_still_download_its_own_export_and_attachment_but_not_anothers_and_cannot_write(): void
    {
        Storage::fake('local');
        [$owner, $acme, $account] = $this->acme();
        [$other, $globex] = $this->acme(self::GLOBEX, 'Globex Corporation');
        [$exportUrl, $fileUrl] = $this->recordsOf($acme);
        [$foreignExport, $foreignFile] = $this->recordsOf($globex);
        $this->infodotSays([
            self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, 'suspended')),
            self::readUrl(self::GLOBEX) => Http::response(self::platforms(self::GLOBEX, true, 'included')),
        ]);

        // The pages are refused, the downloads of its own records are not.
        $this->inCompany($owner, $acme)->get(route('accounts.index'))->assertStatus(403);
        $export = $this->inCompany($owner, $acme)->get($exportUrl)->assertOk();
        $this->assertStringContainsString('Net profit,535750.00', $export->streamedContent());
        $this->inCompany($owner, $acme)->get($fileUrl)->assertOk();

        // Another company's records are still not reachable from here, and an unsigned link is still refused.
        $this->inCompany($owner, $acme)->get($foreignExport)->assertNotFound();
        $this->inCompany($owner, $acme)->get($foreignFile)->assertNotFound();
        $this->inCompany($owner, $acme)->get(route('reports.exports.download', 1))->assertForbidden();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->get($exportUrl)->assertRedirect();

        // Every write stays refused and nothing changes.
        $attachments = \DB::table('attachments')->count();
        $this->inCompany($owner, $acme)->post(route('accounts.store'), ['name' => 'Sneaky', 'type' => 'checking', 'currency' => 'ZAR'])->assertStatus(403);
        $this->inCompany($owner, $acme)->put(route('accounts.update', $account), ['name' => 'Renamed', 'type' => 'checking', 'currency' => 'ZAR'])->assertStatus(403);
        $this->inCompany($owner, $acme)->post(route('reports.exports.store'), ['report' => 'profit-loss', 'format' => 'csv'])->assertStatus(403);
        $this->assertSame('Reserve at Acme Ltd', $account->fresh()->name);
        $this->assertSame(0, Account::withoutGlobalScopes()->where('name', 'Sneaky')->count());
        $this->assertSame($attachments, \DB::table('attachments')->count());
    }
}
