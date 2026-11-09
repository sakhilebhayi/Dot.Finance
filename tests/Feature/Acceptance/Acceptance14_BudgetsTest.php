<?php

namespace Tests\Feature\Acceptance;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Planning\PlanBudget;
use App\Services\Controls\ApprovalPolicies;
use App\Services\Controls\ApprovalService;
use App\Support\Ledger\LedgerContext;

/**
 * Owner criterion 14: budgets are created and monitored. A plan is created and filled through the forms, approved, and compared with
 * the real month of postings by the budget-against-actual report, in words as well as numbers; the older category budgets still work.
 *
 * Actuals for November 2026 by hand: Sales 30,500.00 (12,500.00 + 18,000.00), stationery and office 6,635.00 (3,600.00 + 2,000.00 +
 * 1,035.00), motor vehicle and fuel 1,000.00, salaries 6,500.00.
 */
class Acceptance14_BudgetsTest extends AcceptanceCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runMonth();
    }

    private function createPlan(): PlanBudget
    {
        $this->asOwner()->post(route('planning.budgets.store'), ['name' => 'November plan', 'starts_on' => '2026-11-01', 'months' => 1])->assertSessionHasNoErrors();
        $plan = PlanBudget::query()->forContext($this->ledger)->where('name', 'November plan')->firstOrFail();
        $this->asOwner()->get(route('planning.budgets.edit', $plan))->assertOk();
        $this->asOwner()->put(route('planning.budgets.update', $plan), ['rows' => [
            ['gl_account_id' => $this->gl('4000')->id, 'annual' => '25000.00'],
            ['gl_account_id' => $this->gl('6190')->id, 'annual' => '6000.00'],
            ['gl_account_id' => $this->gl('6170')->id, 'annual' => '1000.00'],
            ['gl_account_id' => $this->gl('6000')->id, 'annual' => '7000.00'],
        ]])->assertSessionHasNoErrors();

        return $plan->fresh();
    }

    public function test_a_plan_is_created_filled_approved_and_then_frozen(): void
    {
        $plan = $this->createPlan();
        $this->assertSame('draft', $plan->status);
        $this->assertSame(4, $plan->cells()->count());
        $this->asOwner()->get(route('planning.budgets.show', $plan))->assertOk()->assertSee('November plan');

        $this->asOwner()->post(route('planning.budgets.submit', $plan))->assertSessionHasNoErrors();
        $this->assertSame('approved', $plan->fresh()->status, 'no approval policy covers budgets, so the owner approves');

        $this->asOwner()->get(route('planning.budgets.edit', $plan))->assertStatus(409);
        $this->asOwner()->put(route('planning.budgets.update', $plan), ['rows' => []])->assertStatus(409);

        $this->asOwner()->post(route('planning.budgets.version', $plan))->assertRedirect();
        $copy = PlanBudget::query()->forContext($this->ledger)->where('based_on_id', $plan->id)->firstOrFail();
        $this->assertSame([2, 'draft'], [(int) $copy->version, $copy->status]);
        $this->assertSame('approved', $plan->fresh()->status, 'the approved plan is untouched');
        $this->assertCount(2, $this->auditRows(null, 'plan_budget')->filter(fn ($r) => $r->action === 'created'));
    }

    public function test_budget_against_actual_compares_the_plan_with_the_real_books_in_words_and_numbers(): void
    {
        $plan = $this->createPlan();
        $this->asOwner()->post(route('planning.budgets.submit', $plan));

        $r = $this->report('budget-vs-actual', ['from' => '2026-11-01', 'to' => '2026-11-30', 'budget' => (string) $plan->id]);
        $sales = $r->row('4000 Sales')['cells'];
        $this->assertSame([2_500_000, 3_050_000, 550_000, 'Ahead of target'], [$sales['budget'], $sales['actual'], $sales['var'], $sales['verdict']]);
        $stationery = $r->row('6190 Stationery and office costs')['cells'];
        $this->assertSame([600_000, 663_500, 'Over budget'], [$stationery['budget'], $stationery['actual'], $stationery['verdict']]);
        $fuel = $r->row('6170 Motor vehicle and fuel')['cells'];
        $this->assertSame([100_000, 100_000, 'On plan'], [$fuel['budget'], $fuel['actual'], $fuel['verdict']]);
        $salaries = $r->row('6000 Salaries and wages')['cells'];
        $this->assertSame([700_000, 650_000], [$salaries['budget'], $salaries['actual']]);
        $this->assertNotSame('Over budget', $salaries['verdict'], 'spending less than planned is not over budget');

        // an expense account that nobody planned shows as such, with its real amount
        $this->assertSame('Not planned', $r->row('6200 Bank charges')['cells']['verdict']);
        $this->assertSame(12_000, $r->row('6200 Bank charges')['cells']['actual']);
        $this->assertReportChecksPass($r);

        $page = $this->asOwner()->get(route('reports.budget-vs-actual', ['from' => '2026-11-01', 'to' => '2026-11-30', 'budget' => $plan->id]))->assertOk()->getContent();
        $this->assertStringContainsString('Ahead of target', $page);
        $this->assertStringContainsString('Over budget', $page);
        $this->assertStringContainsString('6,635.00', $page);
    }

    public function test_a_plan_that_needs_approval_waits_for_someone_else_and_the_creator_cannot_approve_it(): void
    {
        app(ApprovalPolicies::class)->create($this->owner, $this->ledger, ['subject_type' => 'budget', 'name' => 'Every budget', 'threshold_cents' => 0, 'currency' => 'ZAR', 'steps_required' => 1]);
        $approver = $this->memberAs('approver');
        $plan = $this->createPlan();

        $this->asOwner()->post(route('planning.budgets.submit', $plan))->assertSessionHasNoErrors();
        $this->assertSame('pending', $plan->fresh()->status);
        $request = app(ApprovalService::class)->latestFor($plan);
        $this->asOwner()->post(route('controls.approvals.approve', $request))->assertSessionHasErrors('controls');
        $this->assertSame('pending', $plan->fresh()->status);

        $this->asMember($approver)->post(route('controls.approvals.approve', $request))->assertSessionHasNoErrors();
        $this->assertSame('approved', $plan->fresh()->status);
    }

    public function test_input_is_validated_and_only_budget_managers_can_plan(): void
    {
        $this->asOwner()->post(route('planning.budgets.store'), ['name' => '', 'starts_on' => 'x', 'months' => 99])->assertSessionHasErrors(['name', 'starts_on', 'months']);
        $plan = $this->createPlan();
        $this->asOwner()->put(route('planning.budgets.update', $plan), ['rows' => [['gl_account_id' => $this->gl('6190')->id, 'annual' => '12e3']]])->assertSessionHasErrors('rows.0.annual');
        $this->asOwner()->put(route('planning.budgets.update', $plan), ['rows' => [['gl_account_id' => $this->gl('1100')->id, 'annual' => '100.00']]])->assertSessionHasErrors('rows');

        $viewer = $this->memberAs('viewer');
        $this->asMember($viewer)->get(route('planning.budgets.show', $plan))->assertOk();
        $this->asMember($viewer)->post(route('planning.budgets.store'), ['name' => 'x', 'starts_on' => '2026-12-01', 'months' => 1])->assertForbidden();
        $this->asMember($viewer)->post(route('planning.budgets.submit', $plan))->assertForbidden();
        $this->asMember($this->memberAs('bookkeeper'))->post(route('planning.budgets.submit', $plan))->assertForbidden();
    }

    public function test_the_category_budget_tracks_real_spending_and_a_second_budget_for_the_same_month_is_refused(): void
    {
        $this->asOwner()->post(route('categories.store'), ['name' => 'Fuel', 'type' => 'expense']);
        $fuel = Category::query()->forContext($this->ledger)->where('name', 'Fuel')->firstOrFail();
        $this->asOwner()->post(route('budgets.store'), ['category_id' => $fuel->id, 'amount' => '500.00', 'currency' => 'ZAR', 'month' => 11, 'year' => 2026])->assertSessionHasNoErrors();
        $this->asOwner()->post(route('budgets.store'), ['category_id' => $fuel->id, 'amount' => '900.00', 'currency' => 'ZAR', 'month' => 11, 'year' => 2026])->assertSessionHasErrors('category_id');
        $this->assertSame(1, Budget::query()->forContext($this->ledger)->count());

        $this->asOwner()->post(route('transactions.store'), ['account_id' => $this->bank->id, 'category_id' => $fuel->id, 'description' => 'Diesel', 'amount' => '620.00', 'type' => 'expense', 'date' => '2026-11-25'])->assertSessionHasNoErrors();
        $this->asOwner()->get(route('budgets.index'))->assertOk()->assertSee('Fuel')->assertSee('620.00');
    }

    public function test_another_ledgers_plan_cannot_be_opened_or_compared(): void
    {
        $plan = $this->createPlan();
        $other = $this->user();
        $company = $this->company($other, 'Elsewhere');
        $this->enableAccounting(LedgerContext::company($company), 'ZAR');
        $this->inCompany($other, $company)->get(route('planning.budgets.show', $plan))->assertNotFound();
        $this->inCompany($other, $company)->post(route('planning.budgets.submit', $plan))->assertNotFound();
        $this->inCompany($other, $company)->get(route('reports.budget-vs-actual', ['budget' => $plan->id]))->assertNotFound();
    }
}
