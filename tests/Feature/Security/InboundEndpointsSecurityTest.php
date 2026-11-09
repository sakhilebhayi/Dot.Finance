<?php

namespace Tests\Feature\Security;

use App\Models\Team;
use App\Services\Integrations\SignedRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * R-44 security sweep, part 5: the four signed server-to-server doors (InfoDot membership, Emall sales, Dot.HR payroll
 * summary, plus their throttles). The functional behaviour of each is covered by tests/Feature/Integrations; this file
 * attacks them: forged and stale signatures, a signature made with an empty secret, replay, oversize bodies, answering
 * "who exists" to an unauthenticated caller, and throttling.
 */
class InboundEndpointsSecurityTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private const SECRET = 'service-secret-for-tests';

    private Team $acme;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ecosystem.infodot.secret' => self::SECRET, 'integrations.emall.secret' => 'emall-secret', 'tax.payroll_import.secret' => 'hr-secret']);
        $this->acme = $this->company($this->user(), 'Acme');
        Cache::flush();
    }

    private function signed(string $uri, array|string $payload, string $secret, ?int $at = null, ?string $signature = null)
    {
        $body = is_string($payload) ? $payload : json_encode($payload);
        $at ??= time();

        return $this->call('POST', $uri, [], [], [], $this->transformHeadersToServerVars([
            'X-InfoDot-Timestamp' => (string) $at, 'X-InfoDot-Signature' => $signature ?? SignedRequest::sign($secret, $at, $body), 'CONTENT_TYPE' => 'application/json',
        ]), $body);
    }

    private function payroll(string $body, ?string $signature, ?string $secret = 'hr-secret')
    {
        $headers = ['CONTENT_TYPE' => 'application/json'] + ($signature === null ? [] : ['X-Dot-Signature' => $signature]);

        return $this->call('POST', '/api/tax/payroll-import', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    // ── membership ────────────────────────────────────────────────────────

    public function test_membership_refuses_every_kind_of_bad_signature_with_the_same_answer(): void
    {
        $event = ['event' => 'team.deleted', 'team_uuid' => $this->acme->infodot_team_uuid];
        $answers = [];

        $answers['wrong secret'] = $this->signed('/api/ecosystem/membership', $event, 'not-the-secret');
        $answers['stale'] = $this->signed('/api/ecosystem/membership', $event, self::SECRET, time() - 301);
        $answers['from the future'] = $this->signed('/api/ecosystem/membership', $event, self::SECRET, time() + 301);
        $answers['body changed after signing'] = $this->signed('/api/ecosystem/membership', json_encode($event + ['email' => 'x@example.com']), self::SECRET, null, SignedRequest::sign(self::SECRET, time(), json_encode($event)));
        $answers['no headers'] = $this->call('POST', '/api/ecosystem/membership', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($event));
        $answers['timestamp zero'] = $this->signed('/api/ecosystem/membership', $event, self::SECRET, 0);
        $answers['timestamp not a number'] = $this->call('POST', '/api/ecosystem/membership', [], [], [], $this->transformHeadersToServerVars(['X-InfoDot-Timestamp' => 'abc', 'X-InfoDot-Signature' => 'sha256=00', 'CONTENT_TYPE' => 'application/json']), json_encode($event));

        foreach ($answers as $what => $response) {
            $this->assertSame(401, $response->getStatusCode(), $what);
            $this->assertSame(['error' => 'invalid_request'], $response->json(), $what.' must not say why');
        }
        $this->assertCount(0, $this->acme->fresh()->users, 'nothing happened');
    }

    public function test_a_signature_made_with_an_empty_secret_authenticates_nothing_when_the_integration_is_off(): void
    {
        config(['ecosystem.infodot.secret' => '']);
        $event = ['event' => 'team.deleted', 'team_uuid' => $this->acme->infodot_team_uuid];

        $this->signed('/api/ecosystem/membership', $event, '')->assertStatus(401);
        $this->signed('/api/integrations/emall/sales', ['delivery_id' => 'x'], '')->assertStatus(401);

        config(['integrations.emall.secret' => '']);
        $this->signed('/api/integrations/emall/sales', ['delivery_id' => 'x'], '')->assertStatus(401);
        $this->signed('/api/integrations/emall/sales', ['delivery_id' => 'x'], 'emall-secret')->assertStatus(401);
    }

    public function test_a_captured_membership_delivery_cannot_be_replayed_to_hand_a_role_back(): void
    {
        $member = $this->memberOf($this->acme, 'viewer');
        $body = json_encode(['event' => 'member.role_changed', 'team_uuid' => $this->acme->infodot_team_uuid, 'email' => $member->email, 'role' => 'admin']);
        $at = time();

        $this->signed('/api/ecosystem/membership', $body, self::SECRET, $at)->assertStatus(202);
        $this->assertSame('admin', $this->acme->users()->whereKey($member->id)->first()->membership->role);

        // InfoDot later demotes the person; an attacker replays the captured admin delivery inside the window.
        $this->acme->users()->updateExistingPivot($member->id, ['role' => 'viewer']);
        $this->signed('/api/ecosystem/membership', $body, self::SECRET, $at)->assertStatus(202);

        $this->assertSame('viewer', $this->acme->users()->whereKey($member->id)->first()->membership->role, 'the replay must not re-apply');
    }

    public function test_membership_refuses_an_oversized_body_before_checking_anything(): void
    {
        $body = json_encode(['event' => 'team.deleted', 'team_uuid' => $this->acme->infodot_team_uuid, 'pad' => str_repeat('x', 20000)]);

        $this->signed('/api/ecosystem/membership', $body, self::SECRET)->assertStatus(413);
    }

    public function test_membership_is_throttled(): void
    {
        $statuses = [];
        for ($i = 0; $i < 62; $i++) {
            $statuses[] = $this->signed('/api/ecosystem/membership', ['event' => 'nope'], 'wrong')->getStatusCode();
        }

        $this->assertSame(429, end($statuses), 'the 61st call in a minute is throttled');
        $this->assertNotContains(500, $statuses);
    }

    // ── Emall ─────────────────────────────────────────────────────────────

    public function test_emall_says_nothing_about_which_companies_exist_to_a_caller_who_cannot_sign(): void
    {
        $known = ['delivery_id' => 'd1', 'company_uuid' => $this->acme->infodot_team_uuid, 'currency' => 'ZAR', 'lines' => []];
        $unknown = ['delivery_id' => 'd1', 'company_uuid' => '00000000-0000-4000-8000-000000000000', 'currency' => 'ZAR', 'lines' => []];

        $a = $this->signed('/api/integrations/emall/sales', $known, 'wrong');
        $b = $this->signed('/api/integrations/emall/sales', $unknown, 'wrong');

        $this->assertSame(401, $a->getStatusCode());
        $this->assertSame($a->getContent(), $b->getContent());
    }

    public function test_emall_refuses_an_oversized_body_and_a_signature_that_leaves_out_the_timestamp(): void
    {
        $this->signed('/api/integrations/emall/sales', str_repeat('x', 600_000), 'emall-secret')->assertStatus(413);

        $body = json_encode(['delivery_id' => 'd1']);
        $bodyOnly = 'sha256='.hash_hmac('sha256', $body, 'emall-secret');
        $this->signed('/api/integrations/emall/sales', $body, 'emall-secret', null, $bodyOnly)->assertStatus(401);
    }

    // ── Dot.HR payroll summary ────────────────────────────────────────────

    private function summary(array $override = []): array
    {
        return $override + [
            'schema' => 'dot.hr.payroll-summary.v1', 'hr_run_id' => 'HR-2026-10', 'company_uuid' => $this->acme->infodot_team_uuid, 'period' => '2026-10', 'currency' => 'ZAR',
            'issued_at' => now()->toIso8601String(),
            'totals' => ['gross_pay_cents' => 100_000, 'paye_cents' => 10_000, 'uif_employee_cents' => 1_000, 'uif_employer_cents' => 1_000, 'sdl_cents' => 1_000, 'eti_claimed_cents' => 0, 'employee_count' => 1],
        ];
    }

    public function test_the_payroll_import_needs_a_valid_signature_before_it_says_anything_about_the_company(): void
    {
        $body = json_encode($this->summary());

        $this->payroll($body, null)->assertStatus(401);
        $this->payroll($body, 'sha256='.hash_hmac('sha256', $body, 'wrong'))->assertStatus(401);
        $this->payroll($body, hash_hmac('sha256', $body, 'hr-secret'))->assertStatus(401);   // no "sha256=" prefix
        $unknown = json_encode($this->summary(['company_uuid' => '00000000-0000-4000-8000-000000000000']));
        $this->payroll($unknown, 'sha256='.hash_hmac('sha256', $unknown, 'wrong'))->assertStatus(401);

        $this->payroll($body, 'sha256='.hash_hmac('sha256', $body, 'hr-secret'))->assertStatus(201);
    }

    public function test_the_payroll_import_refuses_a_replayed_old_body_and_an_oversized_one(): void
    {
        $old = json_encode($this->summary(['issued_at' => now()->subHour()->toIso8601String()]));
        $this->payroll($old, 'sha256='.hash_hmac('sha256', $old, 'hr-secret'))->assertStatus(422);

        $future = json_encode($this->summary(['issued_at' => now()->addHour()->toIso8601String()]));
        $this->payroll($future, 'sha256='.hash_hmac('sha256', $future, 'hr-secret'))->assertStatus(422);

        $big = json_encode($this->summary() + ['padding' => str_repeat('x', 2_200_000)]);
        $this->payroll($big, 'sha256='.hash_hmac('sha256', $big, 'hr-secret'))->assertStatus(413);
    }

    public function test_the_payroll_import_is_off_without_a_secret_and_throttled_with_one(): void
    {
        $body = json_encode($this->summary());
        $sig = 'sha256='.hash_hmac('sha256', $body, 'hr-secret');

        config(['tax.payroll_import.secret' => '']);
        $this->payroll($body, 'sha256='.hash_hmac('sha256', $body, ''))->assertStatus(503);

        config(['tax.payroll_import.secret' => 'hr-secret']);
        $last = null;
        for ($i = 0; $i < 32; $i++) {
            $last = $this->payroll($body, $sig);
        }
        $this->assertSame(429, $last->getStatusCode());
    }

    public function test_a_payroll_import_for_company_a_never_touches_company_b(): void
    {
        $other = $this->company($this->user(), 'Other Ltd');
        $body = json_encode($this->summary());
        $this->payroll($body, 'sha256='.hash_hmac('sha256', $body, 'hr-secret'))->assertStatus(201);

        $this->assertSame(1, DB::table('tax_payroll_runs')->where('team_id', $this->acme->id)->count());
        $this->assertSame(0, DB::table('tax_payroll_runs')->where('team_id', $other->id)->count());
    }
}
