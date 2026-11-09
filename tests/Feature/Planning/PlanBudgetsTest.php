<?php

namespace Tests\Feature\Planning;

use App\Enums\FinanceRole;
use App\Exceptions\Controls\ControlsViolation;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Controls\ApprovalPolicy;
use App\Models\Controls\ApprovalRequest;
use App\Models\Controls\LedgerMemberRole;
use App\Models\Planning\PlanBudget;
use App\Models\Planning\PlanBudgetLine;
use App\Services\Controls\ApprovalService;
use App\Services\Planning\PlanBudgets;
use App\Services\Reporting\ReportRunner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\BuildsReportingLedger;
use Tests\TestCase;

class PlanBudgetsTest extends TestCase
{
    use BuildsReportingLedger;
    use RefreshDatabase;

    private PlanBudgets $plans;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCompanyBooks();
        $this->plans = app(PlanBudgets::class);
    }

    private function plan(string $name = 'Plan 2026', string $from = '2026-01-01', string $to = '2026-12-31'): PlanBudget
    {
        return $this->plans->create($this->ctx, $this->owner, ['name' => $name, 'starts_on' => $from, 'ends_on' => $to, 'currency' => 'ZAR']);
    }

    private function filled(): PlanBudget
    {
        $plan = $this->plan();
        $this->plans->setLines($plan, [
            ['gl_account_id' => $this->glCode('4100')->id, 'annual' => 120_000_000],
            ['gl_account_id' => $this->glCode('6500')->id, 'annual' => 36_000_000],
        ]);

        return $plan;
    }

    private function as_(string $role = 'owner'): static
    {
        return $this->inCompany($role === 'owner' ? $this->owner : $this->memberOf($this->company, $role), $this->company);
    }

    public function test_a_plan_covers_whole_months_and_a_yearly_total_spreads_with_the_remainder_to_the_earliest_months(): void
    {
        $plan = $this->plan('Odd', '2026-03-17', '2026-08-02');
        $this->assertSame(['2026-03-01', '2026-08-31'], [$plan->starts_on->toDateString(), $plan->ends_on->toDateString()]);
        $this->assertCount(6, $this->plans->months($plan));

        $this->plans->setLines($plan, [['gl_account_id' => $this->glCode('6500')->id, 'annual' => 1_000]]);
        $cells = $plan->cells()->orderBy('month')->pluck('amount_cents', 'month')->map(fn ($c) => (int) $c)->all();
        $this->assertSame([167, 167, 167, 167, 166, 166], array_values($cells), '1,000 over six months: the first four get the extra cent');
        $this->assertSame(1_000, array_sum($cells));
    }

    public function test_only_income_and_expense_accounts_of_this_ledger_and_sane_amounts_are_accepted(): void
    {
        $plan = $this->plan();
        $bad = fn (array $row) => $this->plans->setLines($plan, [$row]);

        foreach ([
            ['gl_account_id' => $this->glCode('1100')->id, 'annual' => 100],                               // an asset
            ['gl_account_id' => 999_999, 'annual' => 100],                                                    // nobody's
            ['gl_account_id' => $this->glCode('4100')->id, 'dimension' => 'drop table', 'annual' => 100],     // not a reference
            ['gl_account_id' => $this->glCode('4100')->id, 'months' => ['2025-01-01' => 100]],                // outside the plan
            ['gl_account_id' => $this->glCode('4100')->id, 'months' => ['2026-01-01' => -5]],                 // negative
        ] as $row) {
            try {
                $bad($row);
                $this->fail('expected a refusal for '.json_encode($row));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, $plan->cells()->count(), 'a refused save changes nothing');

        // Another ledger's account is a refusal too.
        $other = $this->company($this->user(), 'Other Co');
        $otherCtx = $this->companyContext($other);
        $this->enableAccounting($otherCtx, 'ZAR');
        $foreign = GlAccount::query()->forContext($otherCtx)->where('code', '4000')->firstOrFail();
        $this->expectException(InvalidArgumentException::class);
        $this->plans->setLines($plan, [['gl_account_id' => $foreign->id, 'annual' => 100]]);
    }

    public function test_without_an_approval_policy_submitting_approves_and_an_approved_plan_cannot_be_edited(): void
    {
        $plan = $this->filled();
        $this->assertSame('draft', $plan->status);

        $approved = $this->plans->submit($plan, $this->owner);
        $this->assertSame('approved', $approved->status);
        $this->assertNotNull($approved->decided_at);
        $this->assertSame(0, ApprovalRequest::query()->forContext($this->ctx)->count());

        $this->expectException(InvalidArgumentException::class);
        $this->plans->setLines($approved, [['gl_account_id' => $this->glCode('4100')->id, 'annual' => 1]]);
    }

    public function test_an_empty_plan_cannot_be_submitted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->plans->submit($this->plan(), $this->owner);
    }

    public function test_with_an_approval_policy_the_approvers_decide_and_the_creator_cannot_approve_their_own_plan(): void
    {
        ApprovalPolicy::create(['team_id' => $this->company->id, 'subject_type' => 'budget', 'name' => 'Every budget', 'approver_capability' => 'approve-budget', 'steps_required' => 1, 'allow_self_approval' => false, 'is_active' => true]);
        $approver = $this->memberOf($this->company, 'viewer');
        LedgerMemberRole::create(['team_id' => $this->company->id, 'member_user_id' => $approver->id, 'role' => FinanceRole::Approver->value, 'granted_by' => $this->owner->id, 'granted_at' => now()]);

        $plan = $this->filled();
        $pending = $this->plans->submit($plan, $this->owner);
        $this->assertSame('pending', $pending->status);
        $request = ApprovalRequest::query()->forContext($this->ctx)->firstOrFail();
        $this->assertSame(36_000_000, $request->amount_cents, 'the amount asked about is the spending the plan commits to');
        $this->assertSame('budget', $request->subject_kind->value);

        $service = app(ApprovalService::class);
        try {
            $service->approve($this->owner, $request);
            $this->fail('the person who submitted must not approve');
        } catch (ControlsViolation) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('pending', $plan->refresh()->status);

        $service->approve($approver, $request);
        $this->assertSame('approved', $plan->refresh()->status);

        // And a rejection sends it back to be edited.
        $second = $this->filled();
        $this->plans->submit($second, $this->owner);
        $service->reject($approver, ApprovalRequest::query()->forContext($this->ctx)->latest('id')->firstOrFail(), 'Too ambitious');
        $this->assertSame('rejected', $second->refresh()->status);
        $this->assertTrue($second->isEditable());
    }

    public function test_a_new_version_copies_the_cells_and_leaves_the_original_alone(): void
    {
        $plan = $this->plans->submit($this->filled(), $this->owner);
        $copy = $this->plans->newVersion($plan, $this->owner);

        $this->assertSame(2, $copy->version);
        $this->assertSame('draft', $copy->status);
        $this->assertSame($plan->id, $copy->based_on_id);
        $this->assertSame($plan->cells()->count(), $copy->cells()->count());
        $this->assertSame('approved', $plan->refresh()->status);
        $this->assertSame(['income' => 120_000_000, 'expense' => 36_000_000], $this->plans->totals($copy));
    }

    public function test_a_rolling_reforecast_takes_actuals_for_ended_months_and_projects_the_rest(): void
    {
        $plan = $this->plans->submit($this->filled(), $this->owner);
        $req = app(ReportRunner::class)->request($this->ctx, 'profit-loss', ['from' => '2026-01-01', 'to' => '2026-12-31']);

        $hold = $this->plans->reforecast($plan, CarbonImmutable::parse('2026-06-30'), 'hold', $this->owner, $req);
        $this->assertSame('reforecast', $hold->kind);
        $this->assertSame($plan->id, $hold->based_on_id);
        $incomeLine = fn (PlanBudget $p, string $month) => (int) $p->cells()->where('gl_account_id', $this->glCode('4100')->id)->where('month', $month)->value('amount_cents');
        $this->assertSame(10_000_000, $incomeLine($hold, '2026-01-01'), 'January is what happened');
        $this->assertSame(15_000_000, $incomeLine($hold, '2026-06-01'), 'June is what happened');
        $this->assertSame(10_000_000, $incomeLine($hold, '2026-07-01'), 'July onwards keeps the plan');
        $this->assertSame(75_025_000 + 6 * 10_000_000, $this->plans->totals($hold)['income']);

        $run = $this->plans->reforecast($plan, CarbonImmutable::parse('2026-06-30'), 'run_rate', $this->owner, $req);
        $this->assertSame(14_000_000, $incomeLine($run, '2026-07-01'), 'the average of April, May and June');
        $this->assertSame(75_025_000 + 6 * 14_000_000, $this->plans->totals($run)['income']);

        // Nothing about the original changed, and nothing was posted.
        $this->assertSame(['income' => 120_000_000, 'expense' => 36_000_000], $this->plans->totals($plan));
        $this->assertSame(0, JournalEntry::query()->forContext($this->ctx)->where('source_type', 'like', '%forecast%')->count());

        $this->expectException(InvalidArgumentException::class);
        $this->plans->reforecast($this->plan('Draft'), CarbonImmutable::parse('2026-06-30'), 'hold', $this->owner, $req);
    }

    public function test_budget_against_actual_says_ahead_behind_over_and_not_planned_in_words(): void
    {
        $this->plans->submit($this->filled(), $this->owner);
        $run = fn (array $in) => app(ReportRunner::class)->run($this->ctx, 'budget-vs-actual', $in + ['from' => '2026-01-01', 'to' => '2026-03-31'], CarbonImmutable::parse('2026-11-09'));

        $r = $run([]);
        $rev = $r->row('4100 Consulting')['cells'];
        $this->assertSame([30_000_000, 33_000_000, 3_000_000, 1000, 'Ahead of target'], [$rev['budget'], $rev['actual'], $rev['var'], $rev['var_pct'], $rev['verdict']]);
        $rent = $r->row('6500 Rent')['cells'];
        $this->assertSame([9_000_000, 9_000_000, 0, 'On plan'], [$rent['budget'], $rent['actual'], $rent['var'], $rent['verdict']]);
        $fuel = $r->row('6501 Fuel')['cells'];
        $this->assertSame([0, 1_500_000, 'Not planned'], [$fuel['budget'], $fuel['actual'], $fuel['verdict']]);
        $this->assertSame([9_000_000, 10_500_000], [$r->row('Total expenses')['cells']['budget'], $r->row('Total expenses')['cells']['actual']]);
        $this->assertSame('Over budget', $r->row('Total expenses')['cells']['verdict']);
        $this->assertSame([], $r->failedChecks());

        // Only the plan's months inside the period are compared.
        $one = $run(['from' => '2026-02-01', 'to' => '2026-02-28']);
        $this->assertSame(10_000_000, $one->row('4100 Consulting')['cells']['budget']);
        $this->assertSame(11_000_000, $one->row('4100 Consulting')['cells']['actual']);

        // A draft plan is flagged as provisional.
        $draft = $this->filled();
        $page = $run(['budget' => (string) $draft->id]);
        $this->assertStringContainsString('not approved', implode(' ', $page->notes));
    }

    public function test_budget_against_actual_by_department_uses_the_dimension_on_plan_and_journal_lines(): void
    {
        $plan = $this->plan();
        $this->plans->setLines($plan, [
            ['gl_account_id' => $this->glCode('6100')->id, 'dimension' => 'department:3', 'months' => ['2026-04-01' => 500_000]],
            ['gl_account_id' => $this->glCode('4000')->id, 'dimension' => 'project:12', 'months' => ['2026-04-01' => 1_000_000]],
        ]);
        $this->plans->submit($plan, $this->owner);
        $acme = $this->party('Acme');
        $this->invoice('2026-04-10', 1_200_000, $acme, 'project:12', 1);
        $this->bill('2026-04-12', 650_000, $this->party('Landlord', 'supplier'), 'Rent', 'department:3', 2);

        $r = app(ReportRunner::class)->run($this->ctx, 'budget-vs-actual', ['from' => '2026-04-01', 'to' => '2026-04-30', 'view' => 'dimension'], CarbonImmutable::parse('2026-11-09'));
        $this->assertSame([1_000_000, 1_200_000, 'Ahead of target'], [$r->cell('project:12', 'budget'), $r->cell('project:12', 'actual'), $r->cell('project:12', 'verdict')]);
        $this->assertSame([500_000, 650_000, 'Over budget'], [$r->cell('department:3', 'budget'), $r->cell('department:3', 'actual'), $r->cell('department:3', 'verdict')]);
    }

    public function test_the_pages_and_the_gates(): void
    {
        $plan = $this->filled();

        foreach (['owner', 'admin', 'editor', 'viewer'] as $role) {
            $this->as_($role)->get(route('planning.budgets.index'))->assertOk()->assertSee('Plan 2026');
            $this->as_($role)->get(route('planning.budgets.show', $plan))->assertOk()->assertSee('Revenue target');
        }

        $this->as_('viewer')->get(route('planning.budgets.create'))->assertForbidden();
        $this->as_('viewer')->get(route('planning.budgets.edit', $plan))->assertForbidden();
        $this->as_('viewer')->put(route('planning.budgets.update', $plan), ['rows' => []])->assertForbidden();
        $this->as_('viewer')->post(route('planning.budgets.submit', $plan))->assertForbidden();
        $this->as_('viewer')->post(route('planning.budgets.version', $plan))->assertForbidden();

        $this->as_()->get(route('planning.budgets.edit', $plan))->assertOk()->assertSee('4100 Consulting');
        $this->as_()->post(route('planning.budgets.store'), ['name' => 'Next year', 'starts_on' => '2027-01-01', 'months' => 12])->assertRedirect();
        $this->assertSame(2, PlanBudget::withoutGlobalScopes()->count());
        $this->as_()->post(route('planning.budgets.store'), ['name' => '', 'starts_on' => 'x', 'months' => 99])->assertSessionHasErrors(['name', 'starts_on', 'months']);

        // A bookkeeper (editor) manages budgets but, with no approval policy, cannot approve by submitting.
        $this->as_('editor')->post(route('planning.budgets.submit', $plan))->assertForbidden();
        $this->as_()->post(route('planning.budgets.submit', $plan))->assertRedirect();
        $this->assertSame('approved', $plan->refresh()->status);
        $this->as_()->get(route('planning.budgets.edit', $plan))->assertStatus(409);

        $this->as_()->put(route('planning.budgets.update', $this->filled()), ['rows' => [['gl_account_id' => $this->glCode('1100')->id, 'annual' => '100.00']]])->assertSessionHasErrors('rows');
        $this->as_()->put(route('planning.budgets.update', $this->filled()), ['rows' => [['gl_account_id' => $this->glCode('4100')->id, 'annual' => '12e3']]])->assertSessionHasErrors('rows.0.annual');
    }

    public function test_another_ledgers_plan_cannot_be_reached(): void
    {
        $plan = $this->filled();
        $other = $this->user();
        $company2 = $this->company($other, 'Other Co');
        $this->enableAccounting($this->companyContext($company2), 'ZAR');

        $this->inCompany($other, $company2)->get(route('planning.budgets.show', $plan))->assertNotFound();
        $this->inCompany($other, $company2)->get(route('planning.budgets.edit', $plan))->assertNotFound();
        $this->inCompany($other, $company2)->post(route('planning.budgets.submit', $plan))->assertNotFound();
        $this->inCompany($other, $company2)->get(route('reports.budget-vs-actual', ['budget' => $plan->id]))->assertNotFound();
        $this->assertSame(PlanBudgetLine::withoutGlobalScopes()->where('plan_budget_id', $plan->id)->count(), $plan->cells()->count());
    }
}
