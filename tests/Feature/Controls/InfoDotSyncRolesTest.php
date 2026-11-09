<?php

namespace Tests\Feature\Controls;

use App\Actions\Ecosystem\SyncTeamsFromInfoDot;
use App\Enums\Capability;
use App\Enums\FinanceRole;
use App\Models\Controls\ApprovalRequest;
use App\Models\Controls\LedgerMemberRole;
use App\Models\Controls\SpendLimit;
use App\Models\FinanceAuditLog;
use App\Services\Controls\ApprovalPolicies;
use App\Services\Controls\ApprovalService;
use App\Services\Controls\SpendLimits;
use App\Support\Ledger\LedgerAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Tests\Concerns\BuildsControlsFixtures;
use Tests\TestCase;

/** The InfoDot sync is the ceiling: it never escalates, only lowers; and a removed member keeps nothing. */
class InfoDotSyncRolesTest extends TestCase
{
    use BuildsControlsFixtures;
    use RefreshDatabase;

    /** @param  list<array<string, mixed>>  $teams */
    private function sync($user, array $teams): void
    {
        app(SyncTeamsFromInfoDot::class)($user->fresh(), ['teams' => $teams, 'current_team_uuid' => null]);
    }

    private function infodot(string $role): array
    {
        return [['id' => 1, 'uuid' => $this->acme->infodot_team_uuid, 'name' => $this->acme->name, 'personal' => false, 'role' => $role]];
    }

    public function test_the_sync_never_creates_or_raises_a_local_role(): void
    {
        $this->buildAcme();
        $before = LedgerMemberRole::query()->forContext($this->ctx())->count();

        foreach (['admin', 'owner', 'editor', 'viewer'] as $role) {
            $this->sync($this->people['viewer'], $this->infodot($role));
        }
        $this->sync($this->people['viewer'], $this->infodot('admin')); // finishes as an InfoDot admin

        $this->assertSame($before, LedgerMemberRole::query()->forContext($this->ctx())->count(), 'no grants were created by syncing');
        $access = LedgerAccess::for($this->people['viewer'], $this->ctx());
        $this->assertSame(FinanceRole::FinanceManager, $access->financeRole(), 'InfoDot made them an admin, so the baseline is finance manager');
        $this->assertFalse($access->hasLocalRole());
    }

    public function test_a_local_grant_survives_a_sync_that_changes_nothing(): void
    {
        $this->buildAcme();

        $this->sync($this->people['accountant'], $this->infodot('editor'));
        $this->sync($this->people['approver'], $this->infodot('viewer'));

        $this->assertSame(FinanceRole::Accountant, LedgerAccess::for($this->people['accountant'], $this->ctx())->financeRole());
        $this->assertSame(FinanceRole::Approver, LedgerAccess::for($this->people['approver'], $this->ctx())->financeRole());
    }

    public function test_when_infodot_lowers_a_person_grants_above_the_new_ceiling_are_removed_and_logged(): void
    {
        $this->buildAcme();
        // The accountant was granted by the owner on top of an InfoDot editor. InfoDot now says viewer.
        $this->sync($this->people['accountant'], $this->infodot('viewer'));

        $this->assertSame(0, LedgerMemberRole::query()->forContext($this->ctx())->where('member_user_id', $this->people['accountant']->id)->count());
        $access = LedgerAccess::for($this->people['accountant']->fresh(), $this->ctx());
        $this->assertSame(FinanceRole::Viewer, $access->financeRole());
        $this->assertFalse($access->canRecordEntries());

        $log = FinanceAuditLog::query()->forContext($this->ctx())->where('subject_type', 'member_role')->where('action', 'revoked')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('InfoDot role lowered', $log->subject_label);
    }

    public function test_an_approver_grant_on_an_infodot_viewer_is_within_the_ceiling_and_stays_when_infodot_still_says_viewer(): void
    {
        $this->buildAcme();
        $this->sync($this->people['approver'], $this->infodot('viewer'));
        $this->assertTrue(LedgerAccess::for($this->people['approver'], $this->ctx())->can(Capability::ApproveBill));
    }

    public function test_infodot_removing_a_person_removes_their_role_limits_and_cancels_what_they_asked_for(): void
    {
        $this->buildAcme();
        app(ApprovalPolicies::class)->applyPreset($this->people['owner'], $this->ctx(), 'owner_bills', 100_000, 'ZAR');
        app(SpendLimits::class)->set($this->people['owner'], $this->ctx(), $this->people['approver'], null, 'ZAR', 'per_approval', 50_000);
        $bill = $this->bill(200_000);
        $request = app(ApprovalService::class)->request($this->people['accountant'], $bill);

        // InfoDot no longer lists the accountant for this company at all.
        $this->sync($this->people['accountant'], []);

        $this->assertNull(LedgerAccess::for($this->people['accountant']->fresh(), $this->ctx()), 'no access any more');
        $this->assertSame(0, LedgerMemberRole::query()->forContext($this->ctx())->where('member_user_id', $this->people['accountant']->id)->count());
        $this->assertSame(ApprovalRequest::CANCELLED, $request->fresh()->status);
        $this->assertSame('cancelled', $bill->fresh()->state, 'the subject was told');

        // The approver's limit goes when the approver goes.
        $this->sync($this->people['approver'], []);
        $this->assertSame(0, SpendLimit::query()->forContext($this->ctx())->count());
    }

    public function test_jetstream_removal_does_the_same(): void
    {
        $this->buildAcme();
        app(ApprovalPolicies::class)->applyPreset($this->people['owner'], $this->ctx(), 'owner_bills', 100_000, 'ZAR');
        $request = app(ApprovalService::class)->request($this->people['bookkeeper'], $this->bill(200_000));

        $this->acme->removeUser($this->people['bookkeeper']);
        TeamMemberRemoved::dispatch($this->acme, $this->people['bookkeeper']);

        $this->assertSame(ApprovalRequest::CANCELLED, $request->fresh()->status);
    }
}
