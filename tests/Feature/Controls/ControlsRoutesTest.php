<?php

namespace Tests\Feature\Controls;

use App\Enums\FinanceRole;
use App\Models\Controls\AccessReview;
use App\Models\Controls\ApprovalPolicy;
use App\Models\Controls\ApprovalRequest;
use App\Models\Controls\LedgerMemberRole;
use App\Models\Controls\SpendLimit;
use App\Services\Controls\ApprovalPolicies;
use App\Services\Controls\ApprovalService;
use App\Support\Ledger\LedgerAccess;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsControlsFixtures;
use Tests\TestCase;

/** The Controls screens at the HTTP layer: who gets in, what a write does, and that another ledger's ids are invisible. */
class ControlsRoutesTest extends TestCase
{
    use BuildsControlsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAcme();
    }

    // ---- page access ----

    public function test_each_page_for_each_role(): void
    {
        // route => [people who may open it]
        $matrix = [
            'controls.index' => ['owner', 'manager', 'accountant', 'bookkeeper', 'approver', 'viewer'],
            'controls.approvals.index' => ['owner', 'manager', 'accountant', 'bookkeeper', 'approver', 'viewer'],
            'controls.members.index' => ['owner', 'manager', 'accountant'],
            'controls.policies.index' => ['owner', 'manager', 'accountant'],
            'controls.limits.index' => ['owner', 'manager', 'accountant'],
            'controls.access-review.index' => ['owner', 'manager', 'accountant'],
        ];

        foreach ($matrix as $route => $allowed) {
            foreach (array_keys($this->people) as $who) {
                $response = $this->inCompany($this->people[$who], $this->acme)->get(route($route));
                in_array($who, $allowed, true)
                    ? $response->assertOk()
                    : $response->assertForbidden();
            }
        }
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('controls.members.index'))->assertRedirect();
        $this->put(route('controls.members.update', 1), ['role' => 'viewer'])->assertRedirect();
    }

    public function test_the_pages_render_in_a_personal_ledger_too(): void
    {
        $me = $this->user();

        foreach (['controls.index', 'controls.approvals.index', 'controls.members.index', 'controls.policies.index', 'controls.limits.index', 'controls.access-review.index'] as $route) {
            $this->inPersonal($me)->get(route($route))->assertOk();
        }

        $this->inPersonal($me)->get(route('controls.members.index'))->assertSee('one member')->assertSee($me->email);
    }

    // ---- members and roles ----

    public function test_the_members_page_shows_roles_and_only_offers_roles_inside_the_ceiling(): void
    {
        $page = $this->inCompany($this->people['owner'], $this->acme)->get(route('controls.members.index'));
        $page->assertOk()->assertSee($this->people['bookkeeper']->email)->assertSee('Finance manager');

        $html = $page->getContent();
        $viewerSelect = $this->selectFor($html, 'role-'.$this->people['viewer']->id);
        $this->assertStringContainsString('value="approver"', $viewerSelect);
        $this->assertStringNotContainsString('value="bookkeeper"', $viewerSelect, 'an InfoDot viewer cannot be offered a writing role');
        $this->assertStringNotContainsString('value="owner"', $viewerSelect);

        $editorSelect = $this->selectFor($html, 'role-'.$this->people['bookkeeper']->id);
        $this->assertStringContainsString('value="accountant"', $editorSelect);
        $this->assertStringNotContainsString('value="finance_manager"', $editorSelect);
    }

    private function selectFor(string $html, string $id): string
    {
        preg_match('/<select id="'.preg_quote($id, '/').'".*?<\/select>/s', $html, $m);

        return $m[0] ?? '';
    }

    public function test_an_owner_changes_a_role_and_it_takes_effect(): void
    {
        $this->inCompany($this->people['owner'], $this->acme)
            ->put(route('controls.members.update', $this->people['bookkeeper']->id), ['role' => 'accountant'])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame(FinanceRole::Accountant, LedgerAccess::for($this->people['bookkeeper']->fresh(), $this->ctx())->financeRole());
    }

    public function test_a_role_beyond_infodot_is_refused_with_a_message_and_changes_nothing(): void
    {
        $this->inCompany($this->people['owner'], $this->acme)
            ->from(route('controls.members.index'))
            ->put(route('controls.members.update', $this->people['viewer']->id), ['role' => 'accountant'])
            ->assertRedirect(route('controls.members.index'))->assertSessionHasErrors('controls');

        $this->assertSame(FinanceRole::Viewer, LedgerAccess::for($this->people['viewer']->fresh(), $this->ctx())->financeRole());
    }

    public function test_people_who_do_not_manage_members_cannot_change_roles(): void
    {
        foreach (['accountant', 'bookkeeper', 'approver', 'viewer'] as $who) {
            $this->inCompany($this->people[$who], $this->acme)
                ->put(route('controls.members.update', $this->people['viewer']->id), ['role' => 'approver'])->assertForbidden();
        }

        $this->assertSame(FinanceRole::Viewer, LedgerAccess::for($this->people['viewer']->fresh(), $this->ctx())->financeRole());
    }

    public function test_the_role_input_is_validated_and_a_stranger_is_a_404(): void
    {
        $owner = $this->people['owner'];
        $this->inCompany($owner, $this->acme)->put(route('controls.members.update', $this->people['viewer']->id), ['role' => 'god'])->assertSessionHasErrors('role');
        $this->inCompany($owner, $this->acme)->put(route('controls.members.update', $this->user()->id), ['role' => 'viewer'])->assertNotFound();
    }

    public function test_a_manager_of_another_company_cannot_change_roles_here(): void
    {
        $otherOwner = $this->user();
        $other = $this->company($otherOwner, 'Other Ltd');

        // Even naming this company's person while in their own ledger: not a member there, so 404.
        $this->inCompany($otherOwner, $other)->put(route('controls.members.update', $this->people['viewer']->id), ['role' => 'approver'])->assertNotFound();
        // And they cannot switch into Acme at all.
        $this->inCompany($otherOwner, $this->acme)->get(route('controls.members.index'))->assertOk(); // falls back to their personal ledger
        $this->assertSame(0, LedgerMemberRole::query()->forContext($this->ctx())->where('member_user_id', $this->people['viewer']->id)->count());
    }

    // ---- policies ----

    public function test_a_preset_is_one_click_and_a_custom_policy_is_validated(): void
    {
        $owner = $this->people['owner'];

        $this->inCompany($owner, $this->acme)->post(route('controls.policies.preset'), ['preset' => 'owner_bills', 'amount' => '5000.00', 'currency' => 'zar'])
            ->assertRedirect()->assertSessionHas('status');
        $policy = ApprovalPolicy::query()->forContext($this->ctx())->firstOrFail();
        $this->assertSame(500_000, $policy->threshold_cents);
        $this->assertSame('ZAR', $policy->currency);

        $this->inCompany($owner, $this->acme)->post(route('controls.policies.preset'), ['preset' => 'nope'])->assertSessionHasErrors('preset');
        $this->inCompany($owner, $this->acme)->post(route('controls.policies.preset'), ['preset' => 'owner_bills', 'amount' => '12.345'])->assertSessionHasErrors('amount');
        $this->inCompany($owner, $this->acme)->post(route('controls.policies.preset'), ['preset' => 'owner_bills', 'amount' => '1e3'])->assertSessionHasErrors('amount');

        $this->inCompany($owner, $this->acme)->post(route('controls.policies.store'), ['subject_type' => 'payment', 'amount' => '25000', 'currency' => 'ZAR', 'steps_required' => 2])->assertSessionHasNoErrors();
        $this->inCompany($owner, $this->acme)->post(route('controls.policies.store'), ['subject_type' => 'payment', 'steps_required' => 9])->assertSessionHasErrors('steps_required');
        $this->inCompany($owner, $this->acme)->post(route('controls.policies.store'), ['subject_type' => 'payment', 'amount' => '100'])->assertSessionHasErrors('controls'); // an amount needs a currency
        $this->assertSame(2, ApprovalPolicy::query()->forContext($this->ctx())->count());
    }

    public function test_only_those_who_manage_approvals_write_policies_and_limits(): void
    {
        foreach (['accountant', 'bookkeeper', 'approver', 'viewer'] as $who) {
            $c = fn () => $this->inCompany($this->people[$who], $this->acme);
            $c()->post(route('controls.policies.preset'), ['preset' => 'owner_bills'])->assertForbidden();
            $c()->post(route('controls.policies.store'), ['subject_type' => 'bill'])->assertForbidden();
            $c()->post(route('controls.limits.store'), ['who' => 'role:approver', 'currency' => 'ZAR', 'period' => 'daily', 'amount' => '10'])->assertForbidden();
        }

        $this->assertSame(0, ApprovalPolicy::query()->forContext($this->ctx())->count());
        $this->assertSame(0, SpendLimit::query()->forContext($this->ctx())->count());
    }

    public function test_toggle_and_remove_a_policy_and_another_ledgers_policy_is_a_404(): void
    {
        $owner = $this->people['owner'];
        $policy = app(ApprovalPolicies::class)->applyPreset($owner, $this->ctx(), 'owner_bills');

        $this->inCompany($owner, $this->acme)->put(route('controls.policies.update', $policy), ['is_active' => 0])->assertRedirect();
        $this->assertFalse($policy->fresh()->is_active);

        $otherOwner = $this->user();
        $other = $this->company($otherOwner, 'Other Ltd');
        $this->inCompany($otherOwner, $other)->put(route('controls.policies.update', $policy), ['is_active' => 1])->assertNotFound();
        $this->inCompany($otherOwner, $other)->delete(route('controls.policies.destroy', $policy))->assertNotFound();
        $this->assertFalse($policy->fresh()->is_active);

        $this->inCompany($owner, $this->acme)->delete(route('controls.policies.destroy', $policy))->assertRedirect();
        $this->assertNull(ApprovalPolicy::query()->forContext($this->ctx())->find($policy->id));
    }

    // ---- approvals inbox ----

    private function pending(int $cents = 600_000): ApprovalRequest
    {
        if (ApprovalPolicy::query()->forContext($this->ctx())->doesntExist()) {
            app(ApprovalPolicies::class)->applyPreset($this->people['owner'], $this->ctx(), 'approver_expenses', 100, 'ZAR');
            app(ApprovalPolicies::class)->create($this->people['owner'], $this->ctx(), ['subject_type' => 'bill', 'threshold_cents' => 100, 'currency' => 'ZAR']);
        }

        return app(ApprovalService::class)->request($this->people['bookkeeper'], $this->bill($cents));
    }

    public function test_the_inbox_lists_what_waits_for_me_and_an_approver_can_approve_over_http(): void
    {
        $request = $this->pending();

        $this->inCompany($this->people['approver'], $this->acme)->get(route('controls.approvals.index'))->assertOk()->assertSee('Bill B-')->assertSee('ZAR 6,000.00');
        $this->inCompany($this->people['viewer'], $this->acme)->get(route('controls.approvals.index'))->assertOk()->assertDontSee('Bill B-');

        $this->inCompany($this->people['approver'], $this->acme)->post(route('controls.approvals.approve', $request), ['reason' => 'ok'])
            ->assertRedirect()->assertSessionHas('status', 'Approved.');
        $this->assertSame(ApprovalRequest::APPROVED, $request->fresh()->status);

        $this->inCompany($this->people['approver'], $this->acme)->get(route('controls.approvals.index', ['tab' => 'history']))->assertOk()->assertSee('Approved');
    }

    public function test_separation_of_duties_over_http_is_a_message_and_a_viewer_is_forbidden(): void
    {
        $this->pending(); // sets the policies up
        $request = app(ApprovalService::class)->request($this->people['manager'], $this->bill(700_000));

        $this->inCompany($this->people['manager'], $this->acme)->from(route('controls.approvals.index'))
            ->post(route('controls.approvals.approve', $request))->assertSessionHasErrors('controls');
        $this->assertSame(ApprovalRequest::PENDING, $request->fresh()->status);

        $this->inCompany($this->people['viewer'], $this->acme)->post(route('controls.approvals.approve', $request))->assertForbidden();
        $this->inCompany($this->people['bookkeeper'], $this->acme)->post(route('controls.approvals.approve', $request))->assertForbidden();
    }

    public function test_reject_needs_a_reason_and_cancel_is_for_the_requester(): void
    {
        $request = $this->pending();

        $this->inCompany($this->people['approver'], $this->acme)->post(route('controls.approvals.reject', $request), [])->assertSessionHasErrors('reason');
        $this->inCompany($this->people['approver'], $this->acme)->post(route('controls.approvals.cancel', $request))->assertForbidden();
        $this->inCompany($this->people['bookkeeper'], $this->acme)->get(route('controls.approvals.index', ['tab' => 'mine']))->assertOk()->assertSee('Cancel request');
        $this->inCompany($this->people['bookkeeper'], $this->acme)->post(route('controls.approvals.cancel', $request))->assertRedirect();
        $this->assertSame(ApprovalRequest::CANCELLED, $request->fresh()->status);
    }

    public function test_another_companys_request_is_a_404_for_every_action(): void
    {
        $request = $this->pending();
        $otherOwner = $this->user();
        $other = $this->company($otherOwner, 'Other Ltd');

        foreach (['approve', 'reject', 'cancel'] as $action) {
            $this->inCompany($otherOwner, $other)->post(route("controls.approvals.{$action}", $request), ['reason' => 'x'])->assertNotFound();
        }
        $this->assertSame(ApprovalRequest::PENDING, $request->fresh()->status);

        $this->inCompany($otherOwner, $other)->get(route('controls.approvals.index', ['tab' => 'history']))->assertOk()->assertDontSee('Bill B-');
    }

    // ---- limits ----

    public function test_limits_are_set_listed_and_removed_and_ledger_confined(): void
    {
        $owner = $this->people['owner'];
        $this->inCompany($owner, $this->acme)->post(route('controls.limits.store'), ['who' => 'user:'.$this->people['approver']->id, 'currency' => 'zar', 'period' => 'daily', 'amount' => '2500.50'])
            ->assertRedirect()->assertSessionHas('status');
        $limit = SpendLimit::query()->forContext($this->ctx())->firstOrFail();
        $this->assertSame(250_050, $limit->limit_cents);

        $this->inCompany($owner, $this->acme)->get(route('controls.limits.index'))->assertOk()->assertSee('ZAR 2,500.50');

        $this->inCompany($owner, $this->acme)->post(route('controls.limits.store'), ['who' => 'user:'.$this->user()->id, 'currency' => 'ZAR', 'period' => 'daily', 'amount' => '1'])->assertNotFound();
        $this->inCompany($owner, $this->acme)->post(route('controls.limits.store'), ['who' => 'role:nonsense', 'currency' => 'ZAR', 'period' => 'daily', 'amount' => '1'])->assertStatus(422);
        $this->inCompany($owner, $this->acme)->post(route('controls.limits.store'), ['who' => 'role:viewer', 'currency' => 'ZAR', 'period' => 'daily', 'amount' => '0'])->assertSessionHasErrors('amount');

        $otherOwner = $this->user();
        $other = $this->company($otherOwner, 'Other Ltd');
        $this->inCompany($otherOwner, $other)->delete(route('controls.limits.destroy', $limit))->assertNotFound();
        $this->assertNotNull($limit->fresh());

        $this->inCompany($owner, $this->acme)->delete(route('controls.limits.destroy', $limit))->assertRedirect();
        $this->assertNull(SpendLimit::query()->forContext($this->ctx())->first());
    }

    // ---- access review ----

    public function test_the_access_review_lists_everyone_and_records_a_confirmation(): void
    {
        $owner = $this->people['owner'];
        $page = $this->inCompany($owner, $this->acme)->get(route('controls.access-review.index'));
        $page->assertOk()->assertSee('Never confirmed');
        foreach ($this->people as $person) {
            $page->assertSee($person->email);
        }

        $this->inCompany($owner, $this->acme)->post(route('controls.access-review.store'), ['notes' => 'Quarterly check'])->assertRedirect()->assertSessionHas('status');
        $review = AccessReview::query()->forContext($this->ctx())->firstOrFail();
        $this->assertSame($owner->id, $review->reviewed_by);
        $this->assertSame(6, $review->member_count);
        $this->assertSame('Quarterly check', $review->notes);
        $this->assertCount(6, $review->snapshot);
        $this->assertSame('accountant', collect($review->snapshot)->firstWhere('user_id', $this->people['accountant']->id)['role']);

        $this->inCompany($owner, $this->acme)->get(route('controls.access-review.index'))->assertSee('Last confirmed');
    }

    public function test_only_members_managers_confirm_and_a_review_cannot_be_edited(): void
    {
        foreach (['accountant', 'bookkeeper', 'approver', 'viewer'] as $who) {
            $this->inCompany($this->people[$who], $this->acme)->post(route('controls.access-review.store'), ['notes' => 'x'])->assertForbidden();
        }
        $this->assertSame(0, AccessReview::query()->forContext($this->ctx())->count());

        $review = AccessReview::create($this->ctx()->ownerAttributes() + ['reviewed_by' => $this->people['owner']->id, 'snapshot' => [], 'member_count' => 0]);
        $this->expectException(\LogicException::class);
        $review->update(['notes' => 'rewritten']);
    }

    public function test_the_database_refuses_to_update_a_review(): void
    {
        $review = AccessReview::create($this->ctx()->ownerAttributes() + ['reviewed_by' => $this->people['owner']->id, 'snapshot' => [], 'member_count' => 0]);

        $this->expectException(QueryException::class);
        DB::table('access_reviews')->where('id', $review->id)->update(['notes' => 'rewritten']);
    }

    public function test_the_csv_export_is_gated_and_safe_from_formula_injection(): void
    {
        $this->people['bookkeeper']->forceFill(['name' => '=HYPERLINK("http://evil","x")'])->save();

        foreach (['bookkeeper', 'approver', 'viewer'] as $who) {
            $this->inCompany($this->people[$who], $this->acme)->get(route('controls.access-review.export'))->assertForbidden();
        }

        $response = $this->inCompany($this->people['owner'], $this->acme)->get(route('controls.access-review.export'));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="access-review-', $response->headers->get('Content-Disposition'));

        $csv = $response->getContent();
        $lines = array_values(array_filter(explode("\n", $csv)));
        $this->assertCount(7, $lines, 'header + 6 people');
        $this->assertStringContainsString('Role from InfoDot', $lines[0]);
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'a cell starting with = is made literal');
        $this->assertStringNotContainsString(',"=HYPERLINK', $csv);
        $this->assertStringContainsString($this->people['approver']->email, $csv);
        $this->assertStringContainsString('Accountant', $csv);
    }

    public function test_the_csv_export_never_includes_another_ledgers_people(): void
    {
        $otherOwner = $this->user();
        $other = $this->company($otherOwner, 'Other Ltd');
        $stranger = $this->memberOf($other, 'viewer');

        $csv = $this->inCompany($this->people['owner'], $this->acme)->get(route('controls.access-review.export'))->getContent();
        $this->assertStringNotContainsString($stranger->email, $csv);
        $this->assertStringNotContainsString($otherOwner->email, $csv);
    }

    public function test_role_history_appears_on_the_review(): void
    {
        $this->inCompany($this->people['owner'], $this->acme)->put(route('controls.members.update', $this->people['bookkeeper']->id), ['role' => 'accountant']);

        $this->inCompany($this->people['owner'], $this->acme)->get(route('controls.access-review.index'))
            ->assertOk()->assertSee('granted by '.$this->people['owner']->name, false)->assertSee('bookkeeper to accountant');
    }
}
