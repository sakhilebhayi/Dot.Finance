<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Laravel\Jetstream\Jetstream;
use Tests\TestCase;

/**
 * Signing in through InfoDot applies the companies InfoDot lists for the person (each one is a
 * company ledger here, and the role decides what they may do in it):
 * creation by owners and admins only, membership and role following InfoDot,
 * revocation of companies InfoDot no longer lists, and the active company.
 */
class InfoDotTeamSyncTest extends TestCase
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

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        config(['ecosystem.infodot' => [
            'redeem_url' => self::URL, 'platform' => 'finance', 'secret' => 'secret-for-tests', 'timeout' => 2, 'provision' => true,
        ]]);
    }

    private function signIn(string $email, array $teams, ?string $current = null): void
    {
        // A fresh client each time: Http::fake() keeps the FIRST stub for a URL.
        Http::swap(new Factory);
        Http::fake([self::URL => Http::response([
            'user' => ['id' => 1, 'name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'email_verified' => true],
            'teams' => $teams,
            'current_team_id' => 1,
            'current_team_uuid' => $current,
        ])]);

        auth()->logout();
        $this->post(route('ecosystem.auth'), ['token' => 'one-time'], $this->fromInfodot())->assertRedirect();
    }

    private function company(string $uuid, string $name, string $role, bool $personal = false): array
    {
        return ['id' => random_int(1, 999), 'uuid' => $uuid, 'name' => $name, 'personal' => $personal, 'role' => $role];
    }

    public function test_an_owner_creates_the_company_and_it_becomes_their_active_team(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME);

        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $user = User::where('email', 'ann@acme.test')->firstOrFail();

        $this->assertSame('Acme Ltd', $team->name);
        $this->assertFalse($team->personal_team);
        $this->assertSame($user->id, $team->user_id);
        $this->assertSame($team->id, $user->fresh()->current_team_id);
    }

    public function test_an_ordinary_member_cannot_create_a_company_but_is_attached_once_it_exists(): void
    {
        $this->signIn('bob@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'editor')], self::ACME);
        $this->assertNull(Team::where('infodot_team_uuid', self::ACME)->first(), 'a member must not become the local owner');

        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME);
        $this->signIn('bob@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'editor')], self::ACME);

        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $bob = User::where('email', 'bob@acme.test')->firstOrFail();

        $this->assertTrue($bob->belongsToTeam($team));
        $this->assertSame('editor', $bob->teamRole($team)->key);
        $this->assertSame(Team::where('infodot_team_uuid', self::ACME)->count(), 1);
    }

    public function test_roles_follow_infodot_and_admin_maps_to_admin(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME);
        $this->signIn('carl@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'editor')], self::ACME);

        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $carl = User::where('email', 'carl@acme.test')->firstOrFail();
        $this->assertSame('editor', $carl->teamRole($team)->key);

        $this->signIn('carl@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'admin')], self::ACME);
        $this->assertSame('admin', $carl->fresh()->teamRole($team->fresh())->key);
    }

    public function test_a_person_removed_in_infodot_loses_the_company_here_at_next_sign_in(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME);
        $this->signIn('bob@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'editor')], self::ACME);
        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $bob = User::where('email', 'bob@acme.test')->firstOrFail();
        $this->assertTrue($bob->belongsToTeam($team));

        $this->signIn('bob@acme.test', [], null);

        $this->assertFalse($bob->fresh()->belongsToTeam($team));
        $this->assertNotSame($team->id, $bob->fresh()->current_team_id);
    }

    public function test_a_person_in_two_companies_gets_both_and_the_active_one_is_current(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME);
        $this->signIn('gina@globex.test', [$this->company(self::GLOBEX, 'Globex Corp', 'owner')], self::GLOBEX);
        $acme = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $globex = Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail();

        $both = [$this->company(self::ACME, 'Acme Ltd', 'editor'), $this->company(self::GLOBEX, 'Globex Corp', 'editor')];
        $this->signIn('dan@both.test', $both, self::GLOBEX);
        $dan = User::where('email', 'dan@both.test')->firstOrFail();

        $this->assertTrue($dan->belongsToTeam($acme));
        $this->assertTrue($dan->belongsToTeam($globex));
        $this->assertSame($globex->id, $dan->fresh()->current_team_id);

        $this->signIn('dan@both.test', $both, self::ACME);
        $this->assertSame($acme->id, $dan->fresh()->current_team_id);
    }

    public function test_the_current_team_is_never_one_the_person_does_not_belong_to(): void
    {
        $this->signIn('gina@globex.test', [$this->company(self::GLOBEX, 'Globex Corp', 'owner')], self::GLOBEX);
        $globex = Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail();

        // InfoDot claims Eve is acting for Globex but lists no membership of it.
        $this->signIn('eve@else.test', [], self::GLOBEX);
        $eve = User::where('email', 'eve@else.test')->firstOrFail();

        $this->assertFalse($eve->belongsToTeam($globex));
        $this->assertNotSame($globex->id, $eve->fresh()->current_team_id);
    }

    public function test_personal_teams_in_the_payload_are_never_linked(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Ann personal', 'owner', true)], self::ACME);

        $this->assertSame(0, Team::whereNotNull('infodot_team_uuid')->count());
    }

    public function test_link_team_command_attaches_an_existing_team_and_refuses_bad_links(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $existing = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Existing', 'personal_team' => false]);

        $this->artisan('infodot:link-team', ['team' => $existing->id, 'uuid' => self::ACME])->assertSuccessful();
        $this->assertSame(self::ACME, $existing->fresh()->infodot_team_uuid);

        $this->artisan('infodot:link-team', ['team' => $existing->id, 'uuid' => self::GLOBEX])->assertFailed();
        $this->artisan('infodot:link-team', ['team' => $owner->currentTeam->id, 'uuid' => self::GLOBEX])->assertFailed();
        $this->assertNull($owner->currentTeam->fresh()->infodot_team_uuid, 'a personal team is refused without --promote');
        $this->artisan('infodot:link-team', ['team' => $existing->id, 'uuid' => 'not-a-uuid'])->assertFailed();

        $other = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Other', 'personal_team' => false]);
        $this->artisan('infodot:link-team', ['team' => $other->id, 'uuid' => self::ACME])->assertFailed();
    }

    public function test_promote_turns_a_personal_team_into_a_linked_company_and_keeps_its_data(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $personal = $owner->currentTeam;
        $this->assertTrue($personal->personal_team);

        $this->artisan('infodot:link-team', ['team' => $personal->id, 'uuid' => self::ACME, '--promote' => true])->assertSuccessful();

        $fresh = $personal->fresh();
        $this->assertFalse($fresh->personal_team);
        $this->assertSame(self::ACME, $fresh->infodot_team_uuid);
        $this->assertSame($owner->id, $fresh->user_id);
        $this->assertTrue($owner->fresh()->belongsToTeam($fresh));
    }

    public function test_after_promotion_the_next_sign_in_reuses_the_team_instead_of_creating_another(): void
    {
        $owner = User::factory()->withPersonalTeam()->create(['email' => 'ann@acme.test']);
        $this->artisan('infodot:link-team', ['team' => $owner->currentTeam->id, 'uuid' => self::ACME, '--promote' => true])->assertSuccessful();
        $before = Team::count();

        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME);

        $this->assertSame($before, Team::count());
        $this->assertSame($owner->currentTeam->id, $owner->fresh()->current_team_id);
    }

    public function test_an_infodot_viewer_becomes_a_local_viewer_not_an_editor(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME);
        $this->signIn('vera@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'viewer')], self::ACME);

        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $vera = User::where('email', 'vera@acme.test')->firstOrFail();

        $this->assertSame('viewer', $vera->teamRole($team)->key);
        $this->assertFalse(LedgerAccess::for($vera, LedgerContext::company($team))->canRecordEntries(), 'a viewer must not be able to write');
        $this->assertTrue(LedgerAccess::for($vera, LedgerContext::company($team))->canRead());
    }

    public function test_a_role_infodot_sends_that_this_app_does_not_know_is_a_viewer_never_an_editor(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME);
        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();

        foreach (['auditor' => 'una@acme.test', 'member' => 'mel@acme.test', '' => 'eve@acme.test', 'EDITOR' => 'ed@acme.test', 'superuser' => 'sue@acme.test'] as $role => $email) {
            $this->signIn($email, [$this->company(self::ACME, 'Acme Ltd', $role)], self::ACME);
            $person = User::where('email', $email)->firstOrFail();
            $team = $team->fresh();

            $this->assertSame('viewer', $person->teamRole($team)->key, "InfoDot role '{$role}' must map to viewer");
            $this->assertFalse(LedgerAccess::for($person, LedgerContext::company($team))->canRecordEntries());
        }
    }

    public function test_an_infodot_role_with_no_role_key_at_all_is_a_viewer(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME);
        $company = $this->company(self::ACME, 'Acme Ltd', 'x');
        unset($company['role']);
        $this->signIn('noel@acme.test', [$company], self::ACME);

        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $this->assertSame('viewer', User::where('email', 'noel@acme.test')->firstOrFail()->teamRole($team)->key);
    }

    public function test_an_editor_who_becomes_a_viewer_in_infodot_loses_write_access_at_next_sign_in(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME);
        $this->signIn('ed@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'editor')], self::ACME);
        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $ed = User::where('email', 'ed@acme.test')->firstOrFail();
        $this->assertTrue(LedgerAccess::for($ed, LedgerContext::company($team))->canRecordEntries());

        $this->signIn('ed@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'viewer')], self::ACME);

        $ed = $ed->fresh();
        $this->assertSame('viewer', $ed->teamRole($team->fresh())->key);
        $this->assertFalse(LedgerAccess::for($ed, LedgerContext::company($team))->canRecordEntries());
    }

    public function test_an_admin_who_becomes_a_viewer_in_infodot_loses_even_the_audit_log(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME);
        $this->signIn('al@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'admin')], self::ACME);
        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $al = User::where('email', 'al@acme.test')->firstOrFail();
        $this->assertTrue(LedgerAccess::for($al, LedgerContext::company($team))->canViewAudit());

        $this->signIn('al@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'viewer')], self::ACME);

        $this->assertFalse(LedgerAccess::for($al->fresh(), LedgerContext::company($team))->canViewAudit());
    }

    public function test_the_viewer_role_exists_with_only_the_read_permission(): void
    {
        $viewer = Jetstream::findRole('viewer');

        $this->assertNotNull($viewer);
        $this->assertSame(['read'], $viewer->permissions);
        $this->assertSame(['create', 'read', 'update', 'delete'], Jetstream::findRole('admin')->permissions);
    }

    public function test_the_local_owner_is_never_detached_when_infodot_stops_listing_the_company(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME);
        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $ann = User::where('email', 'ann@acme.test')->firstOrFail();

        $this->signIn('ann@acme.test', [], null);

        $this->assertSame($ann->id, $team->fresh()->user_id);
        $this->assertTrue($ann->fresh()->belongsToTeam($team->fresh()));
    }

    public function test_syncing_teams_does_not_touch_the_persons_own_data(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME);
        $ann = User::where('email', 'ann@acme.test')->firstOrFail();
        $personal = $ann->ownedTeams()->where('personal_team', true)->count();

        $this->signIn('ann@acme.test', [], null);

        $this->assertSame($personal, $ann->fresh()->ownedTeams()->where('personal_team', true)->count());
        $this->assertSame(1, User::count());
    }
}
