<?php

namespace Tests\Feature\Controls;

use App\Enums\FinanceRole;
use App\Events\ApprovalDecided;
use App\Exceptions\Controls\ControlsViolation;
use App\Models\Controls\ApprovalDecision;
use App\Models\Controls\ApprovalPolicy;
use App\Models\Controls\ApprovalRequest;
use App\Models\FinanceAuditLog;
use App\Services\Controls\ApprovalPolicies;
use App\Services\Controls\ApprovalService;
use App\Services\Controls\SpendLimits;
use App\Support\Ledger\LedgerContext;
use App\Support\Ledger\LedgerContextViolation;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsControlsFixtures;
use Tests\Support\ControlsBill;
use Tests\TestCase;

class ApprovalServiceTest extends TestCase
{
    use BuildsControlsFixtures;
    use RefreshDatabase;

    private ApprovalService $approvals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAcme();
        $this->approvals = app(ApprovalService::class);
    }

    /** Bills over R5,000 need one approver (any role with approve-bill). */
    private function billPolicy(array $over = []): ApprovalPolicy
    {
        return app(ApprovalPolicies::class)->create($this->people['owner'], $this->ctx(), $over + [
            'subject_type' => 'bill', 'name' => 'Bills over R5,000', 'threshold_cents' => 500_000, 'currency' => 'ZAR',
        ]);
    }

    private function assertViolation(string $reason, callable $do): void
    {
        try {
            $do();
            $this->fail("expected a '{$reason}' violation");
        } catch (ControlsViolation $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    // ---- policies and the gate ----

    public function test_nothing_needs_approval_by_default(): void
    {
        $this->assertFalse(ApprovalService::requiresApproval('bill', 99_999_999, 'ZAR', $this->ctx()));
        $this->assertSame(0, ApprovalPolicy::query()->forContext($this->ctx())->count());
        $this->assertViolation('no_policy', fn () => $this->approvals->request($this->people['bookkeeper'], $this->bill(10_000_000)));
    }

    public function test_the_threshold_is_strictly_greater_than_and_other_currencies_fail_closed(): void
    {
        $this->billPolicy();
        $ctx = $this->ctx();

        $this->assertFalse(ApprovalService::requiresApproval('bill', 500_000, 'ZAR', $ctx), 'exactly R5,000 is not over R5,000');
        $this->assertTrue(ApprovalService::requiresApproval('bill', 500_001, 'ZAR', $ctx));
        $this->assertTrue(ApprovalService::requiresApproval('bill', 100, 'USD', $ctx), 'cannot compare a USD amount with a ZAR threshold, so approval is asked for');
        $this->assertFalse(ApprovalService::requiresApproval('payment', 99_999_999, 'ZAR', $ctx), 'another subject type');
    }

    public function test_a_policy_without_a_threshold_covers_every_amount_and_an_inactive_one_covers_none(): void
    {
        $policy = app(ApprovalPolicies::class)->create($this->people['owner'], $this->ctx(), ['subject_type' => 'journal']);
        $this->assertTrue(ApprovalService::requiresApproval('journal', 1, 'ZAR', $this->ctx()));
        $this->assertTrue(ApprovalService::requiresApproval('journal', 0, null, $this->ctx()));

        app(ApprovalPolicies::class)->update($this->people['owner'], $this->ctx(), $policy, ['is_active' => false]);
        $this->assertFalse(ApprovalService::requiresApproval('journal', 1, 'ZAR', $this->ctx()));
    }

    public function test_the_gate_is_confined_to_the_ledger_and_refuses_to_guess_without_one(): void
    {
        $this->billPolicy();
        $other = $this->company($this->user(), 'Other Ltd');

        $this->assertFalse(ApprovalService::requiresApproval('bill', 9_999_999, 'ZAR', $this->ctx($other)), "Acme's policy does not apply to Other");
        $this->assertViolation('invalid', fn () => ApprovalService::requiresApproval('bill', 1, 'ZAR'));
        $this->assertViolation('invalid', fn () => ApprovalService::requiresApproval('nonsense', 1, 'ZAR', $this->ctx()));
    }

    public function test_the_strictest_matching_policy_wins(): void
    {
        $this->billPolicy();
        $strict = $this->billPolicy(['name' => 'Over R20,000: two people', 'threshold_cents' => 2_000_000, 'steps_required' => 2]);

        $this->assertSame($strict->id, ApprovalService::policyFor('bill', 2_000_001, 'ZAR', $this->ctx())->id);
        $this->assertNotSame($strict->id, ApprovalService::policyFor('bill', 600_000, 'ZAR', $this->ctx())->id);
    }

    public function test_only_people_who_manage_approvals_can_change_policies(): void
    {
        foreach (['accountant', 'bookkeeper', 'approver', 'viewer'] as $who) {
            $this->assertViolation('missing_capability', fn () => app(ApprovalPolicies::class)->create($this->people[$who], $this->ctx(), ['subject_type' => 'bill']));
        }

        $this->assertViolation('invalid', fn () => app(ApprovalPolicies::class)->create($this->people['owner'], $this->ctx(), ['subject_type' => 'bill', 'steps_required' => 4]));
        $this->assertViolation('invalid', fn () => app(ApprovalPolicies::class)->create($this->people['owner'], $this->ctx(), ['subject_type' => 'bill', 'threshold_cents' => 100]));
    }

    public function test_presets_are_one_click_and_do_not_double_up(): void
    {
        $policies = app(ApprovalPolicies::class);
        $one = $policies->applyPreset($this->people['owner'], $this->ctx(), 'owner_bills');
        $again = $policies->applyPreset($this->people['owner'], $this->ctx(), 'owner_bills', 800_000, 'ZAR');

        $this->assertSame($one->id, $again->id);
        $this->assertSame(1, ApprovalPolicy::query()->forContext($this->ctx())->count());
        $this->assertSame(800_000, $again->fresh()->threshold_cents);
        $this->assertSame(FinanceRole::Owner, $again->min_role);
        $this->assertSame('Owner approves bills over ZAR 8,000.00', $again->fresh()->name);
        $this->assertFalse($again->allow_self_approval, 'a multi-person company does not get self-approval');
    }

    // ---- the happy path ----

    public function test_request_then_approve_by_a_different_approver_updates_the_subject_and_fires_once(): void
    {
        $this->billPolicy();
        Event::fake([ApprovalDecided::class]);
        $bill = $this->bill(600_000);

        $request = $this->approvals->request($this->people['bookkeeper'], $bill, 'Please approve');
        $this->assertSame(ApprovalRequest::PENDING, $request->status);
        $this->assertSame(600_000, $request->snapshot['amount_cents']);
        $this->assertSame('bill', $request->snapshot['kind']);
        $this->assertSame('Bill B-'.$bill->id, $request->label);
        $this->assertFalse($this->approvals->isApproved($bill));

        $done = $this->approvals->approve($this->people['approver'], $request, 'Looks right');

        $this->assertSame(ApprovalRequest::APPROVED, $done->status);
        $this->assertSame($this->people['approver']->id, $done->decided_by);
        $this->assertNotNull($done->decided_at);
        $this->assertSame(['approved'], ControlsBill::$decided);
        $this->assertSame('approved', $bill->fresh()->state);
        $this->assertTrue($this->approvals->isApproved($bill->fresh()));
        Event::assertDispatchedTimes(ApprovalDecided::class, 1);
        Event::assertDispatched(ApprovalDecided::class, fn ($e) => $e->decision === 'approved' && $e->request->is($request));
    }

    public function test_the_event_really_fires_after_the_commit_with_a_real_listener(): void
    {
        $this->billPolicy();
        $seen = [];
        Event::listen(ApprovalDecided::class, function (ApprovalDecided $e) use (&$seen) {
            $seen[] = [$e->decision, $e->request->fresh()->status];
        });

        $request = $this->approvals->request($this->people['bookkeeper'], $this->bill(600_000));
        $this->approvals->approve($this->people['approver'], $request);

        $this->assertSame([['approved', 'approved']], $seen);
    }

    public function test_the_request_is_idempotent_and_replaced_when_the_amount_changes(): void
    {
        $this->billPolicy();
        $bill = $this->bill(600_000);

        $a = $this->approvals->request($this->people['bookkeeper'], $bill);
        $b = $this->approvals->request($this->people['accountant'], $bill);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, ApprovalRequest::query()->forContext($this->ctx())->count());

        $bill->forceFill(['amount_cents' => 900_000])->save();
        $c = $this->approvals->request($this->people['bookkeeper'], $bill);

        $this->assertNotSame($a->id, $c->id);
        $this->assertSame(ApprovalRequest::CANCELLED, $a->fresh()->status);
        $this->assertSame(ApprovalRequest::PENDING, $c->status);
        $this->assertSame(900_000, $c->amount_cents);
    }

    public function test_the_database_allows_one_pending_request_per_subject(): void
    {
        $this->billPolicy();
        $bill = $this->bill(600_000);
        $first = $this->approvals->request($this->people['bookkeeper'], $bill);

        $this->expectException(UniqueConstraintViolationException::class);
        ApprovalRequest::create($this->ctx()->ownerAttributes() + [
            'subject_kind' => 'bill', 'approvable_type' => ControlsBill::class, 'approvable_id' => $bill->id, 'amount_cents' => 1,
            'snapshot' => [], 'status' => 'pending', 'approver_capability' => 'approve-bill', 'steps_required' => 1,
        ]);
    }

    // ---- separation of duties, capability, role ----

    public function test_the_requester_cannot_approve_their_own_request_even_with_the_capability(): void
    {
        $this->billPolicy();
        // The finance manager holds approve-bill AND asks for it.
        $request = $this->approvals->request($this->people['manager'], $this->bill(600_000));

        $this->assertViolation('separation_of_duties', fn () => $this->approvals->approve($this->people['manager'], $request));
        $this->assertSame(ApprovalRequest::PENDING, $request->fresh()->status);
        $this->assertSame(0, ApprovalDecision::query()->forContext($this->ctx())->count());

        // ...but another finance manager can, and so can the owner.
        $this->assertSame(ApprovalRequest::APPROVED, $this->approvals->approve($this->people['owner'], $request)->status);
    }

    public function test_a_sole_member_may_approve_their_own_request_only_when_the_policy_allows_it(): void
    {
        $solo = $this->company($owner = $this->user(), 'Solo Ltd');
        $ctx = $this->ctx($solo);
        $strict = app(ApprovalPolicies::class)->create($owner, $ctx, ['subject_type' => 'bill']);

        $bill = $this->bill(100, 'ZAR', $solo);
        $request = $this->approvals->request($owner, $bill);
        $this->assertViolation('separation_of_duties', fn () => $this->approvals->approve($owner, $request));

        app(ApprovalPolicies::class)->update($owner, $ctx, $strict, ['allow_self_approval' => true]);
        $allowed = $this->approvals->request($owner, $this->bill(200, 'ZAR', $solo));
        $this->assertSame(ApprovalRequest::APPROVED, $this->approvals->approve($owner, $allowed)->status);

        // The moment a second person joins, the rule applies again.
        $solo->users()->attach($this->user()->id, ['role' => 'viewer']);
        $third = $this->approvals->request($owner, $this->bill(300, 'ZAR', $solo));
        $this->assertViolation('separation_of_duties', fn () => $this->approvals->approve($owner->fresh(), $third));
    }

    public function test_a_personal_ledger_owner_can_self_approve_when_the_policy_allows_it(): void
    {
        $person = $this->user();
        $ctx = LedgerContext::personal($person);
        app(ApprovalPolicies::class)->applyPreset($person, $ctx, 'owner_bills', 1_000, 'ZAR');
        $bill = ControlsBill::create(['user_id' => $person->id, 'amount_cents' => 5_000, 'currency' => 'ZAR']);

        $request = $this->approvals->request($person, $bill);
        $this->assertSame(ApprovalRequest::APPROVED, $this->approvals->approve($person, $request)->status, 'the preset allows self-approval in a one-person ledger');
    }

    public function test_the_approver_must_hold_the_capability(): void
    {
        $this->billPolicy();
        $request = $this->approvals->request($this->people['bookkeeper'], $this->bill(600_000));

        foreach (['bookkeeper', 'accountant', 'viewer'] as $who) {
            $this->assertViolation('missing_capability', fn () => $this->approvals->approve($this->people[$who], $request));
        }

        $this->assertSame(ApprovalRequest::PENDING, $request->fresh()->status);
    }

    public function test_a_policy_can_require_a_minimum_role(): void
    {
        $this->billPolicy(['min_role' => 'owner']);
        $request = $this->approvals->request($this->people['bookkeeper'], $this->bill(600_000));

        $this->assertViolation('role_too_low', fn () => $this->approvals->approve($this->people['approver'], $request));
        $this->assertViolation('role_too_low', fn () => $this->approvals->approve($this->people['manager'], $request));
        $this->assertSame(ApprovalRequest::APPROVED, $this->approvals->approve($this->people['owner'], $request)->status);
    }

    public function test_the_rules_a_request_was_asked_under_do_not_change_when_the_policy_does(): void
    {
        $policy = $this->billPolicy(['min_role' => 'owner']);
        $request = $this->approvals->request($this->people['bookkeeper'], $this->bill(600_000));

        app(ApprovalPolicies::class)->update($this->people['owner'], $this->ctx(), $policy, ['min_role' => null]);

        $this->assertViolation('role_too_low', fn () => $this->approvals->approve($this->people['approver'], $request));
    }

    // ---- limits ----

    public function test_a_per_approval_limit_stops_a_bigger_approval(): void
    {
        $this->billPolicy();
        app(SpendLimits::class)->set($this->people['owner'], $this->ctx(), $this->people['approver'], null, 'ZAR', 'per_approval', 700_000);

        $small = $this->approvals->request($this->people['bookkeeper'], $this->bill(600_000));
        $big = $this->approvals->request($this->people['bookkeeper'], $this->bill(900_000));

        $this->assertViolation('over_limit', fn () => $this->approvals->approve($this->people['approver'], $big));
        $this->assertSame(ApprovalRequest::APPROVED, $this->approvals->approve($this->people['approver'], $small)->status);
        $this->assertSame(ApprovalRequest::APPROVED, $this->approvals->approve($this->people['owner'], $big)->status, 'someone without that limit can');
    }

    public function test_a_daily_limit_counts_what_the_person_already_approved(): void
    {
        $this->billPolicy();
        app(SpendLimits::class)->set($this->people['owner'], $this->ctx(), $this->people['approver'], null, 'ZAR', 'daily', 1_000_000);

        $a = $this->approvals->request($this->people['bookkeeper'], $this->bill(600_000));
        $b = $this->approvals->request($this->people['bookkeeper'], $this->bill(600_000));

        $this->approvals->approve($this->people['approver'], $a);
        try {
            $this->approvals->approve($this->people['approver'], $b);
            $this->fail('the second approval would take them over the daily limit');
        } catch (ControlsViolation $e) {
            $this->assertSame('over_limit', $e->reason);
            $this->assertStringContainsString('ZAR 4,000.00 left', $e->getMessage());
        }
        $this->assertSame(ApprovalRequest::PENDING, $b->fresh()->status);
        $this->assertSame(ApprovalRequest::APPROVED, $this->approvals->approve($this->people['owner'], $b)->status, 'someone without that limit can');
    }

    public function test_a_role_limit_applies_unless_the_person_has_their_own(): void
    {
        $this->billPolicy();
        $limits = app(SpendLimits::class);
        $limits->set($this->people['owner'], $this->ctx(), null, FinanceRole::Approver, 'ZAR', 'per_approval', 100_000);
        $request = $this->approvals->request($this->people['bookkeeper'], $this->bill(600_000));

        $this->assertViolation('over_limit', fn () => $this->approvals->approve($this->people['approver'], $request));

        $limits->set($this->people['owner'], $this->ctx(), $this->people['approver'], null, 'ZAR', 'per_approval', 900_000);
        $this->assertSame(ApprovalRequest::APPROVED, $this->approvals->approve($this->people['approver'], $request)->status, 'their own limit replaces the role limit');
    }

    public function test_limits_in_another_currency_fail_closed(): void
    {
        $this->billPolicy();
        app(SpendLimits::class)->set($this->people['owner'], $this->ctx(), $this->people['approver'], null, 'ZAR', 'per_approval', 99_999_999);
        $usd = $this->approvals->request($this->people['bookkeeper'], $this->bill(100, 'USD'));

        $this->assertViolation('over_limit', fn () => $this->approvals->approve($this->people['approver'], $usd));
    }

    public function test_limits_are_validated_and_managed_only_by_those_who_manage_approvals(): void
    {
        $limits = app(SpendLimits::class);
        $this->assertViolation('missing_capability', fn () => $limits->set($this->people['approver'], $this->ctx(), $this->people['viewer'], null, 'ZAR', 'daily', 100));
        $this->assertViolation('invalid', fn () => $limits->set($this->people['owner'], $this->ctx(), $this->people['viewer'], FinanceRole::Viewer, 'ZAR', 'daily', 100));
        $this->assertViolation('invalid', fn () => $limits->set($this->people['owner'], $this->ctx(), $this->people['viewer'], null, 'ZAR', 'hourly', 100));
        $this->assertViolation('invalid', fn () => $limits->set($this->people['owner'], $this->ctx(), $this->people['viewer'], null, 'ZAR', 'daily', 0));
        $this->assertViolation('not_a_member', fn () => $limits->set($this->people['owner'], $this->ctx(), $this->user(), null, 'ZAR', 'daily', 100));
        $this->assertViolation('role_too_low', fn () => $limits->set($this->people['manager'], $this->ctx(), $this->people['owner'], null, 'ZAR', 'daily', 100));

        $one = $limits->set($this->people['owner'], $this->ctx(), $this->people['viewer'], null, 'ZAR', 'daily', 100);
        $again = $limits->set($this->people['owner'], $this->ctx(), $this->people['viewer'], null, 'ZAR', 'daily', 200);
        $this->assertSame($one->id, $again->id);
        $this->assertSame(200, $again->fresh()->limit_cents);
    }

    // ---- amount re-check ----

    public function test_a_changed_amount_blocks_the_approval_and_nothing_is_recorded(): void
    {
        $this->billPolicy();
        $bill = $this->bill(600_000);
        $request = $this->approvals->request($this->people['bookkeeper'], $bill);

        $bill->forceFill(['amount_cents' => 9_000_000])->save();

        $this->assertViolation('stale_amount', fn () => $this->approvals->approve($this->people['approver'], $request));
        $this->assertSame(ApprovalRequest::PENDING, $request->fresh()->status);
        $this->assertSame(0, ApprovalDecision::query()->forContext($this->ctx())->count());
        $this->assertSame([], ControlsBill::$decided);
    }

    public function test_a_changed_amount_after_approval_means_it_is_no_longer_approved(): void
    {
        $this->billPolicy();
        $bill = $this->bill(600_000);
        $this->approvals->approve($this->people['approver'], $this->approvals->request($this->people['bookkeeper'], $bill));
        $this->assertTrue($this->approvals->isApproved($bill->fresh()));

        $bill->forceFill(['amount_cents' => 700_000])->save();
        $this->assertFalse($this->approvals->isApproved($bill->fresh()));
    }

    public function test_a_deleted_subject_cannot_be_approved(): void
    {
        $this->billPolicy();
        $bill = $this->bill(600_000);
        $request = $this->approvals->request($this->people['bookkeeper'], $bill);
        $bill->delete();

        $this->assertViolation('subject_gone', fn () => $this->approvals->approve($this->people['approver'], $request));
    }

    // ---- steps ----

    public function test_two_steps_need_two_different_people_and_the_second_is_not_the_first_again(): void
    {
        $this->billPolicy(['steps_required' => 2]);
        $request = $this->approvals->request($this->people['bookkeeper'], $this->bill(600_000));

        $first = $this->approvals->approve($this->people['approver'], $request);
        $this->assertSame(ApprovalRequest::PENDING, $first->status);
        $this->assertSame(1, $first->steps_done);
        $this->assertSame([], ControlsBill::$decided, 'the subject is not told until the last step');

        $again = $this->approvals->approve($this->people['approver'], $request);
        $this->assertSame(1, $again->steps_done, 'the same person approving again changes nothing');
        $this->assertSame(1, ApprovalDecision::query()->forContext($this->ctx())->count());

        $final = $this->approvals->approve($this->people['manager'], $request);
        $this->assertSame(ApprovalRequest::APPROVED, $final->status);
        $this->assertSame(2, ApprovalDecision::query()->forContext($this->ctx())->count());
        $this->assertSame(['approved'], ControlsBill::$decided);
        $this->assertSame([$this->people['approver']->id, $this->people['manager']->id], ApprovalDecision::query()->forContext($this->ctx())->orderBy('step')->pluck('decided_by')->all());
    }

    public function test_a_rejection_at_any_step_ends_it(): void
    {
        $this->billPolicy(['steps_required' => 2]);
        $request = $this->approvals->request($this->people['bookkeeper'], $this->bill(600_000));
        $this->approvals->approve($this->people['approver'], $request);

        $this->assertViolation('invalid', fn () => $this->approvals->reject($this->people['manager'], $request, '  '));
        $rejected = $this->approvals->reject($this->people['manager'], $request, 'Wrong supplier');

        $this->assertSame(ApprovalRequest::REJECTED, $rejected->status);
        $this->assertSame('Wrong supplier', $rejected->reason);
        $this->assertSame(['rejected'], ControlsBill::$decided);
        $this->assertViolation('already_decided', fn () => $this->approvals->approve($this->people['owner'], $request));
        $this->assertSame(ApprovalRequest::REJECTED, $this->approvals->reject($this->people['owner'], $request, 'Agreed')->status, 'the same outcome again is a no-op');
    }

    // ---- idempotency and races ----

    public function test_approving_twice_and_two_approvers_racing_leave_one_decision_and_one_event(): void
    {
        $this->billPolicy();
        Event::fake([ApprovalDecided::class]);
        $request = $this->approvals->request($this->people['bookkeeper'], $this->bill(600_000));

        // Two approvers each hold the SAME stale copy (pending) of the request, as in a race.
        $copyA = ApprovalRequest::query()->forContext($this->ctx())->find($request->id);
        $copyB = ApprovalRequest::query()->forContext($this->ctx())->find($request->id);

        $a = $this->approvals->approve($this->people['approver'], $copyA);
        $b = $this->approvals->approve($this->people['manager'], $copyB);
        $c = $this->approvals->approve($this->people['approver'], $copyA);

        foreach ([$a, $b, $c] as $result) {
            $this->assertSame(ApprovalRequest::APPROVED, $result->status);
        }
        $this->assertSame(1, ApprovalDecision::query()->forContext($this->ctx())->count());
        $this->assertSame($this->people['approver']->id, $request->fresh()->decided_by, 'the first decision stands');
        $this->assertSame(['approved'], ControlsBill::$decided, 'the subject was told once');
        Event::assertDispatchedTimes(ApprovalDecided::class, 1);
        $this->assertSame(1, FinanceAuditLog::query()->forContext($this->ctx())->where('subject_type', 'approval_request')->where('action', 'approved')->count());
    }

    public function test_the_database_allows_one_decision_per_step(): void
    {
        $this->billPolicy();
        $request = $this->approvals->request($this->people['bookkeeper'], $this->bill(600_000));
        $this->approvals->approve($this->people['approver'], $request);

        $this->expectException(UniqueConstraintViolationException::class);
        ApprovalDecision::create($this->ctx()->ownerAttributes() + ['approval_request_id' => $request->id, 'step' => 1, 'decided_by' => $this->people['manager']->id, 'decision' => 'approved']);
    }

    public function test_a_rejected_request_cannot_be_flipped_to_approved_by_a_late_approver(): void
    {
        $this->billPolicy();
        $request = $this->approvals->request($this->people['bookkeeper'], $this->bill(600_000));
        $stale = ApprovalRequest::query()->forContext($this->ctx())->find($request->id);
        $this->approvals->reject($this->people['approver'], $request, 'No');

        $this->assertViolation('already_decided', fn () => $this->approvals->approve($this->people['manager'], $stale));
        $this->assertSame(ApprovalRequest::REJECTED, $request->fresh()->status);
    }

    // ---- cancel ----

    public function test_cancel_by_the_requester_or_a_manager_only_and_only_while_pending(): void
    {
        $this->billPolicy();
        $request = $this->approvals->request($this->people['bookkeeper'], $this->bill(600_000));

        $this->assertViolation('missing_capability', fn () => $this->approvals->cancel($this->people['approver'], $request));
        $this->assertViolation('missing_capability', fn () => $this->approvals->cancel($this->people['accountant'], $request));

        $this->assertSame(ApprovalRequest::CANCELLED, $this->approvals->cancel($this->people['bookkeeper'], $request, 'Wrong bill')->status);
        $this->assertSame(ApprovalRequest::CANCELLED, $this->approvals->cancel($this->people['bookkeeper'], $request)->status, 'again: no-op');
        $this->assertSame(['cancelled'], ControlsBill::$decided);
        $this->assertViolation('not_pending', fn () => $this->approvals->approve($this->people['approver'], $request));

        $other = $this->approvals->request($this->people['bookkeeper'], $this->bill(700_000));
        $this->assertSame(ApprovalRequest::CANCELLED, $this->approvals->cancel($this->people['manager'], $other)->status, 'a manager may');

        $done = $this->approvals->request($this->people['bookkeeper'], $this->bill(800_000));
        $this->approvals->approve($this->people['approver'], $done);
        $this->assertViolation('not_pending', fn () => $this->approvals->cancel($this->people['bookkeeper'], $done));
    }

    // ---- who can still act ----

    public function test_a_removed_approver_cannot_approve_and_a_demoted_one_loses_the_power(): void
    {
        $this->billPolicy();
        $request = $this->approvals->request($this->people['bookkeeper'], $this->bill(600_000));

        // Demoted: InfoDot-side change from admin to viewer for the manager; local grants none.
        $this->acme->users()->updateExistingPivot($this->people['manager']->id, ['role' => 'viewer']);
        $this->assertViolation('missing_capability', fn () => $this->approvals->approve($this->people['manager']->fresh(), $request));

        // Removed: no access at all.
        $this->acme->removeUser($this->people['approver']);
        $this->assertViolation('no_access', fn () => $this->approvals->approve($this->people['approver']->fresh(), $request));

        $this->assertSame(ApprovalRequest::PENDING, $request->fresh()->status);
        $this->assertSame(ApprovalRequest::APPROVED, $this->approvals->approve($this->people['owner'], $request)->status);
    }

    public function test_the_waiting_list_shows_only_what_the_person_could_decide(): void
    {
        $this->billPolicy();
        $mine = $this->approvals->request($this->people['manager'], $this->bill(600_000));
        $theirs = $this->approvals->request($this->people['bookkeeper'], $this->bill(700_000));

        $forApprover = $this->approvals->waitingFor($this->people['approver'], $this->ctx())->pluck('request.id')->all();
        $forManager = $this->approvals->waitingFor($this->people['manager'], $this->ctx())->pluck('request.id')->all();
        $forViewer = $this->approvals->waitingFor($this->people['viewer'], $this->ctx())->pluck('request.id')->all();

        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id], $forApprover);
        $this->assertSame([$theirs->id], $forManager, 'not their own');
        $this->assertSame([], $forViewer);
        $this->assertSame([], $this->approvals->waitingFor($this->user(), $this->ctx())->all(), 'a stranger sees nothing');
    }

    // ---- cross-ledger ----

    public function test_another_companys_people_cannot_decide_this_companys_request(): void
    {
        $this->billPolicy();
        $request = $this->approvals->request($this->people['bookkeeper'], $this->bill(600_000));

        $otherOwner = $this->user();
        $other = $this->company($otherOwner, 'Other Ltd');
        app(ApprovalPolicies::class)->create($otherOwner, $this->ctx($other), ['subject_type' => 'bill']);

        foreach (['approve', 'cancel'] as $action) {
            $this->assertViolation('no_access', fn () => $this->approvals->{$action}($otherOwner, $request));
        }
        $this->assertViolation('no_access', fn () => $this->approvals->reject($otherOwner, $request, 'no'));
        $this->assertViolation('no_access', fn () => $this->approvals->request($otherOwner, $this->bill(100, 'ZAR', $this->acme)));
        $this->assertSame(ApprovalRequest::PENDING, $request->fresh()->status);

        $this->assertSame(1, ApprovalPolicy::query()->forContext($this->ctx($other))->count());
        $this->assertSame(0, ApprovalRequest::query()->forContext($this->ctx($other))->count());
    }

    public function test_a_request_cannot_point_at_another_ledgers_policy(): void
    {
        $this->billPolicy();
        $otherOwner = $this->user();
        $other = $this->company($otherOwner, 'Other Ltd');
        $foreign = app(ApprovalPolicies::class)->create($otherOwner, $this->ctx($other), ['subject_type' => 'bill']);

        $this->expectException(LedgerContextViolation::class);
        ApprovalRequest::create($this->ctx()->ownerAttributes() + [
            'approval_policy_id' => $foreign->id, 'subject_kind' => 'bill', 'approvable_type' => ControlsBill::class, 'approvable_id' => 1,
            'snapshot' => [], 'approver_capability' => 'approve-bill',
        ]);
    }

    // ---- audit and evidence ----

    public function test_every_step_of_an_approval_is_audited_with_who_did_it(): void
    {
        $policy = $this->billPolicy();
        $request = $this->approvals->request($this->people['bookkeeper'], $this->bill(600_000));
        $this->approvals->approve($this->people['approver'], $request);

        $rows = FinanceAuditLog::query()->forContext($this->ctx())->where('subject_type', 'approval_request')->orderBy('id')->get();
        $this->assertSame(['requested', 'approved'], $rows->pluck('action')->all());
        $this->assertSame([$this->people['bookkeeper']->id, $this->people['approver']->id], $rows->pluck('actor_user_id')->all());
        $this->assertSame('pending', $rows[1]->changes['status']['before']);
        $this->assertSame('approved', $rows[1]->changes['status']['after']);

        $policyLog = FinanceAuditLog::query()->forContext($this->ctx())->where('subject_type', 'approval_policy')->where('subject_id', $policy->id)->first();
        $this->assertSame('created', $policyLog->action);
    }

    public function test_decisions_are_evidence_and_cannot_be_changed(): void
    {
        $this->billPolicy();
        $request = $this->approvals->request($this->people['bookkeeper'], $this->bill(600_000));
        $this->approvals->approve($this->people['approver'], $request);
        $decision = ApprovalDecision::query()->forContext($this->ctx())->firstOrFail();

        try {
            $decision->update(['decision' => 'rejected']);
            $this->fail('the model must refuse');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        DB::table('approval_decisions')->where('id', $decision->id)->update(['decision' => 'rejected']);
    }
}
