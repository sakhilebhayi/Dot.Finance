<?php

namespace Tests\Feature\Integrations;

use App\Enums\FinanceRole;
use App\Models\Controls\LedgerMemberRole;
use App\Models\Controls\SpendLimit;
use App\Models\Team;
use App\Services\Integrations\SignedRequest;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

class MembershipWebhookTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private const SECRET = 'service-secret-for-tests';

    private Team $acme;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ecosystem.infodot.secret' => self::SECRET]);
        $this->acme = $this->company($this->user(), 'Acme');
    }

    private function send(array $payload, ?string $secret = self::SECRET, ?int $at = null, ?string $body = null)
    {
        $body ??= json_encode($payload);
        $at ??= time();
        $headers = ['X-InfoDot-Timestamp' => (string) $at, 'X-InfoDot-Signature' => SignedRequest::sign((string) $secret, $at, $body), 'CONTENT_TYPE' => 'application/json'];

        return $this->call('POST', '/api/ecosystem/membership', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    public function test_a_removed_member_loses_the_company_their_grants_and_their_current_team_at_once(): void
    {
        $member = $this->memberOf($this->acme, 'editor');
        $member->forceFill(['current_team_id' => $this->acme->id])->save();
        LedgerMemberRole::create(['team_id' => $this->acme->id, 'member_user_id' => $member->id, 'role' => 'accountant']);
        SpendLimit::create(['team_id' => $this->acme->id, 'member_user_id' => $member->id, 'currency' => 'ZAR', 'period' => 'daily', 'limit_cents' => 100]);

        $this->send(['event' => 'member.removed', 'team_uuid' => $this->acme->infodot_team_uuid, 'email' => strtoupper($member->email)])->assertStatus(202);

        $this->assertFalse($member->fresh()->belongsToTeam($this->acme->fresh()));
        $this->assertNull($member->fresh()->current_team_id);
        $this->assertSame(0, LedgerMemberRole::query()->where('team_id', $this->acme->id)->where('member_user_id', $member->id)->count());
        $this->assertSame(0, SpendLimit::query()->where('team_id', $this->acme->id)->where('member_user_id', $member->id)->count());
        $this->assertNull(LedgerAccess::for($member->fresh(), LedgerContext::company($this->acme)), 'no access to the company ledger any more');
    }

    public function test_a_role_change_applies_and_a_local_grant_above_the_new_ceiling_is_dropped(): void
    {
        $member = $this->memberOf($this->acme, 'editor');
        LedgerMemberRole::create(['team_id' => $this->acme->id, 'member_user_id' => $member->id, 'role' => 'accountant']);

        $this->send(['event' => 'member.role_changed', 'team_uuid' => $this->acme->infodot_team_uuid, 'email' => $member->email, 'role' => 'viewer'])->assertStatus(202);

        $this->assertSame('viewer', $this->acme->fresh()->users()->find($member->id)->membership->role);
        $this->assertSame(0, LedgerMemberRole::query()->where('team_id', $this->acme->id)->where('member_user_id', $member->id)->count(), 'a viewer cannot hold an accountant grant');
        $this->assertSame(FinanceRole::Viewer, LedgerAccess::for($member->fresh(), LedgerContext::company($this->acme))->financeRole());

        $this->send(['event' => 'member.role_changed', 'team_uuid' => $this->acme->infodot_team_uuid, 'email' => $member->email, 'role' => 'mystery'])->assertStatus(202);
        $this->assertSame('viewer', $this->acme->fresh()->users()->find($member->id)->membership->role, 'an unknown role is read-only, never more');
    }

    public function test_the_local_owner_is_never_removed(): void
    {
        $this->send(['event' => 'member.removed', 'team_uuid' => $this->acme->infodot_team_uuid, 'email' => $this->acme->owner->email])->assertStatus(202);

        $this->assertTrue($this->acme->owner->fresh()->ownsTeam($this->acme));
        $this->inCompany($this->acme->owner, $this->acme)->get(route('accounts.index'))->assertOk();
    }

    public function test_a_deleted_company_ends_everyone_elses_access_but_keeps_the_books_with_their_owner(): void
    {
        $a = $this->memberOf($this->acme, 'admin');
        $b = $this->memberOf($this->acme, 'editor');
        $this->companyAccount($this->acme, ['name' => 'Kept']);

        $this->send(['event' => 'team.deleted', 'team_uuid' => $this->acme->infodot_team_uuid])->assertStatus(202);

        $this->assertSame(0, $this->acme->fresh()->users()->count());
        $this->assertNotNull($this->acme->fresh()->infodot_team_uuid, 'the link stays so the owner keeps the records');
        $this->inCompany($this->acme->owner, $this->acme)->get(route('accounts.index'))->assertOk()->assertSee('Kept');
        $this->assertNull(LedgerAccess::for($a->fresh(), LedgerContext::company($this->acme)));
        $this->assertNull(LedgerAccess::for($b->fresh(), LedgerContext::company($this->acme)));
    }

    public function test_events_are_idempotent_and_unknown_companies_or_people_are_ignored_without_a_word(): void
    {
        $member = $this->memberOf($this->acme, 'editor');
        $payload = ['event' => 'member.removed', 'team_uuid' => $this->acme->infodot_team_uuid, 'email' => $member->email];

        $this->send($payload)->assertStatus(202);
        $this->send($payload)->assertStatus(202);
        $this->send(['event' => 'member.removed', 'team_uuid' => '11111111-1111-4111-8111-111111111111', 'email' => $member->email])->assertStatus(202);
        $this->send(['event' => 'member.removed', 'team_uuid' => $this->acme->infodot_team_uuid, 'email' => 'nobody@example.test'])->assertStatus(202);

        $this->assertSame(0, $this->acme->fresh()->users()->count());
    }

    public function test_every_unsigned_stale_or_forged_call_is_the_same_generic_401(): void
    {
        $member = $this->memberOf($this->acme, 'editor');
        $payload = ['event' => 'member.removed', 'team_uuid' => $this->acme->infodot_team_uuid, 'email' => $member->email];
        $body = json_encode($payload);

        $responses = [
            $this->send($payload, 'wrong-secret'),
            $this->send($payload, self::SECRET, time() - 3600),
            $this->send($payload, self::SECRET, time() + 3600),
            $this->postJson('/api/ecosystem/membership', $payload),
        ];
        // signed for one body, sent as another
        $at = time();
        $responses[] = $this->call('POST', '/api/ecosystem/membership', [], [], [], $this->transformHeadersToServerVars(['X-InfoDot-Timestamp' => (string) $at, 'X-InfoDot-Signature' => SignedRequest::sign(self::SECRET, $at, json_encode(['event' => 'member.removed', 'team_uuid' => $this->acme->infodot_team_uuid, 'email' => 'other@x.test'])), 'CONTENT_TYPE' => 'application/json']), $body);

        foreach ($responses as $i => $response) {
            $response->assertStatus(401);
            $this->assertSame(['error' => 'invalid_request'], $response->json(), "case {$i}");
        }

        $this->assertTrue($member->fresh()->belongsToTeam($this->acme->fresh()), 'none of them changed anything');
    }

    public function test_with_no_secret_configured_nothing_authenticates(): void
    {
        config(['ecosystem.infodot.secret' => null]);

        $this->send(['event' => 'team.deleted', 'team_uuid' => $this->acme->infodot_team_uuid], '')->assertStatus(401);
        $this->send(['event' => 'team.deleted', 'team_uuid' => $this->acme->infodot_team_uuid], null)->assertStatus(401);
    }

    public function test_a_signed_but_malformed_payload_is_a_422_and_changes_nothing(): void
    {
        foreach ([['event' => 'member.exploded', 'team_uuid' => $this->acme->infodot_team_uuid], ['event' => 'member.removed', 'team_uuid' => 'not-a-uuid'], ['event' => 'member.removed'], ['event' => 'member.removed', 'team_uuid' => $this->acme->infodot_team_uuid, 'email' => 'not-an-email']] as $bad) {
            $this->send($bad)->assertStatus(422);
        }

        $this->assertNotNull($this->acme->fresh());
    }
}
