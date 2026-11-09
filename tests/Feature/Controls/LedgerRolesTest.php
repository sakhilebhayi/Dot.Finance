<?php

namespace Tests\Feature\Controls;

use App\Enums\FinanceRole;
use App\Exceptions\Controls\ControlsViolation;
use App\Models\Controls\LedgerMemberRole;
use App\Models\FinanceAuditLog;
use App\Services\Controls\LedgerRoles;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsControlsFixtures;
use Tests\TestCase;

/** Local role grants: who may give what to whom, and the InfoDot ceiling. */
class LedgerRolesTest extends TestCase
{
    use BuildsControlsFixtures;
    use RefreshDatabase;

    private function assertViolation(string $reason, callable $do): void
    {
        try {
            $do();
            $this->fail("expected a '{$reason}' violation");
        } catch (ControlsViolation $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    public function test_an_owner_or_finance_manager_can_refine_an_infodot_editor_inside_the_ceiling(): void
    {
        $this->buildAcme();
        $roles = app(LedgerRoles::class);

        foreach (['owner', 'manager'] as $by) {
            foreach ([FinanceRole::Accountant, FinanceRole::Approver, FinanceRole::Viewer, FinanceRole::Bookkeeper] as $role) {
                $roles->assign($this->people[$by], $this->ctx(), $this->people['bookkeeper'], $role);
                $this->assertSame($role, LedgerAccess::for($this->people['bookkeeper'], $this->ctx())->financeRole(), "{$by} grants {$role->value}");
            }
        }

        $roles->assign($this->people['owner'], $this->ctx(), $this->people['bookkeeper'], null);
        $this->assertSame(FinanceRole::Bookkeeper, LedgerAccess::for($this->people['bookkeeper'], $this->ctx())->financeRole(), 'back to the InfoDot role');
        $this->assertSame(0, LedgerMemberRole::query()->forContext($this->ctx())->where('member_user_id', $this->people['bookkeeper']->id)->count());
    }

    public function test_a_local_grant_can_never_go_beyond_what_infodot_allows(): void
    {
        $this->buildAcme();
        $roles = app(LedgerRoles::class);

        // An InfoDot viewer can be an approver (reads, approves, writes nothing) but never a writer.
        foreach ([FinanceRole::Bookkeeper, FinanceRole::Accountant, FinanceRole::FinanceManager, FinanceRole::Owner] as $role) {
            $this->assertViolation('above_infodot_role', fn () => $roles->assign($this->people['owner'], $this->ctx(), $this->people['viewer'], $role));
        }

        // An InfoDot editor cannot become a finance manager or owner.
        foreach ([FinanceRole::FinanceManager, FinanceRole::Owner] as $role) {
            $this->assertViolation('above_infodot_role', fn () => $roles->assign($this->people['owner'], $this->ctx(), $this->people['bookkeeper'], $role));
        }

        // Nobody is ever given "owner" locally, not even an InfoDot admin.
        $this->assertViolation('above_infodot_role', fn () => $roles->assign($this->people['owner'], $this->ctx(), $this->people['manager'], FinanceRole::Owner));

        // An InfoDot admin can be narrowed to anything below.
        $roles->assign($this->people['owner'], $this->ctx(), $this->people['manager'], FinanceRole::Viewer);
        $this->assertFalse(LedgerAccess::for($this->people['manager'], $this->ctx())->canRecordEntries());
    }

    public function test_a_grant_row_above_the_ceiling_is_ignored_when_read(): void
    {
        $this->buildAcme();
        // Planted directly: a viewer "granted" finance manager, as if InfoDot had lowered them afterwards.
        $this->grant($this->acme, $this->people['viewer'], FinanceRole::FinanceManager);

        $access = LedgerAccess::for($this->people['viewer'], $this->ctx());
        $this->assertSame(FinanceRole::Viewer, $access->financeRole());
        $this->assertFalse($access->canRecordEntries());
        $this->assertFalse($access->hasLocalRole());
    }

    public function test_a_finance_manager_cannot_touch_the_owner_and_a_narrowed_manager_loses_the_power(): void
    {
        $this->buildAcme();
        $roles = app(LedgerRoles::class);

        $this->assertViolation('owner_role', fn () => $roles->assign($this->people['manager'], $this->ctx(), $this->people['owner'], FinanceRole::Viewer));

        // Narrowed locally to accountant, the same person no longer manages members.
        $this->grant($this->acme, $this->people['manager'], FinanceRole::Accountant);
        $this->assertViolation('missing_capability', fn () => $roles->assign($this->people['manager'], $this->ctx(), $this->people['bookkeeper'], FinanceRole::Viewer));
        // (The "above your own role" guard in assign() is a second line of defence: with the current role set only an
        // owner or finance manager reaches it and nothing above finance manager is grantable, so no input can trigger it.)
    }

    public function test_the_manager_cannot_change_someone_who_outranks_them(): void
    {
        $this->buildAcme();
        $roles = app(LedgerRoles::class);
        $second = $this->memberOf($this->acme, 'admin');

        // Two finance managers are peers: allowed. Narrow the first, then it cannot touch the second (who now outranks it).
        $roles->assign($this->people['owner'], $this->ctx(), $this->people['manager'], FinanceRole::FinanceManager);
        $roles->assign($this->people['manager'], $this->ctx(), $second, FinanceRole::Accountant);
        $this->assertSame(FinanceRole::Accountant, LedgerAccess::for($second, $this->ctx())->financeRole());

        // A finance manager cannot grant what they hold only via a lower local role: demote the manager to approver and see them locked out.
        $roles->assign($this->people['owner'], $this->ctx(), $this->people['manager'], FinanceRole::Approver);
        $this->assertViolation('missing_capability', fn () => $roles->assign($this->people['manager'], $this->ctx(), $second, FinanceRole::Viewer));
    }

    public function test_only_people_who_manage_members_can_assign_roles(): void
    {
        $this->buildAcme();
        $roles = app(LedgerRoles::class);

        foreach (['accountant', 'bookkeeper', 'approver', 'viewer'] as $by) {
            $this->assertViolation('missing_capability', fn () => $roles->assign($this->people[$by], $this->ctx(), $this->people['viewer'], FinanceRole::Viewer));
        }
    }

    public function test_cross_ledger_a_manager_of_another_company_can_do_nothing_here(): void
    {
        $this->buildAcme();
        $roles = app(LedgerRoles::class);
        $otherOwner = $this->user();
        $other = $this->company($otherOwner, 'Other Ltd');

        $this->assertViolation('no_access', fn () => $roles->assign($otherOwner, $this->ctx(), $this->people['viewer'], FinanceRole::Approver));
        // ...and a member of Acme is not a member of Other.
        $this->assertViolation('not_a_member', fn () => $roles->assign($otherOwner, $this->ctx($other), $this->people['viewer'], FinanceRole::Approver));
        $this->assertSame(0, LedgerMemberRole::query()->forContext($this->ctx($other))->count());
    }

    public function test_a_role_in_one_company_does_not_leak_into_another(): void
    {
        $this->buildAcme();
        $other = $this->company($this->user(), 'Other Ltd');
        $other->users()->attach($this->people['approver']->id, ['role' => 'viewer']);

        $inOther = LedgerAccess::for($this->people['approver']->fresh(), $this->ctx($other));
        $this->assertSame(FinanceRole::Viewer, $inOther->financeRole(), 'the approver grant belongs to Acme only');
        $this->assertSame(FinanceRole::Approver, LedgerAccess::for($this->people['approver']->fresh(), $this->ctx())->financeRole());
    }

    public function test_personal_ledgers_have_no_role_grants(): void
    {
        $person = $this->user();
        $this->assertViolation('personal_ledger', fn () => app(LedgerRoles::class)->assign($person, LedgerContext::personal($person), $person, FinanceRole::Viewer));
        $members = app(LedgerRoles::class)->members(LedgerContext::personal($person));
        $this->assertCount(1, $members);
        $this->assertSame(FinanceRole::Owner, $members[0]['access']->financeRole());
    }

    public function test_granting_is_idempotent_and_audited_with_actor_and_member(): void
    {
        $this->buildAcme();
        $roles = app(LedgerRoles::class);

        $roles->assign($this->people['owner'], $this->ctx(), $this->people['bookkeeper'], FinanceRole::Accountant);
        $roles->assign($this->people['owner'], $this->ctx(), $this->people['bookkeeper'], FinanceRole::Accountant);
        $roles->assign($this->people['manager'], $this->ctx(), $this->people['bookkeeper'], FinanceRole::Approver);
        $roles->assign($this->people['manager'], $this->ctx(), $this->people['bookkeeper'], null);

        $rows = FinanceAuditLog::query()->forContext($this->ctx())->where('subject_type', 'member_role')
            ->where('context', 'member:'.$this->people['bookkeeper']->id)->orderBy('id')->get();

        $this->assertSame(['granted', 'changed', 'revoked'], $rows->pluck('action')->all(), 'the repeat was a no-op');
        $this->assertSame([$this->people['owner']->id, $this->people['manager']->id, $this->people['manager']->id], $rows->pluck('actor_user_id')->all());
        $this->assertSame('bookkeeper', $rows[0]->changes['role']['before']);
        $this->assertSame('accountant', $rows[0]->changes['role']['after']);
    }

    public function test_the_database_refuses_an_owner_grant_and_a_row_with_two_owners(): void
    {
        $this->buildAcme();

        $this->expectException(QueryException::class);
        DB::table('ledger_member_roles')->insert(['team_id' => $this->acme->id, 'member_user_id' => $this->people['viewer']->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_database_refuses_a_ledger_row_with_both_owners(): void
    {
        $this->buildAcme();

        $this->expectException(QueryException::class);
        DB::table('ledger_member_roles')->insert(['user_id' => $this->people['owner']->id, 'team_id' => $this->acme->id, 'member_user_id' => $this->people['viewer']->id, 'role' => 'viewer', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_one_grant_per_member_per_ledger(): void
    {
        $this->buildAcme();

        $this->expectException(QueryException::class);
        $this->grant($this->acme, $this->people['accountant'], FinanceRole::Viewer); // already has one
    }
}
