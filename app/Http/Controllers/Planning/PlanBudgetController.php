<?php

namespace App\Http\Controllers\Planning;

use App\Enums\Capability;
use App\Models\Accounting\GlAccount;
use App\Models\Planning\Dimension;
use App\Models\Planning\PlanBudget;
use App\Rules\MoneyAmount;
use App\Services\Controls\ApprovalService;
use App\Services\Planning\PlanBudgets;
use App\Services\Reporting\ReportRunner;
use App\Support\Ledger\LedgerRule;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class PlanBudgetController extends PlanningController
{
    public function index(PlanBudgets $plans): View
    {
        $access = $this->access();
        if ($view = $this->unlessEnabled()) {
            return $view;
        }

        $budgets = PlanBudget::query()->orderByDesc('fiscal_year')->orderByDesc('id')->get();
        $totals = [];
        foreach ($budgets as $b) {
            $totals[$b->id] = $plans->totals($b);
        }

        return view('planning.budgets.index', ['budgets' => $budgets, 'totals' => $totals, 'canManage' => $access->can(Capability::ManageBudgets)]);
    }

    public function create(): View
    {
        $this->require(Capability::ManageBudgets);
        if ($view = $this->unlessEnabled()) {
            return $view;
        }

        return view('planning.budgets.create', ['settings' => $this->settings()]);
    }

    public function store(Request $request, PlanBudgets $plans): RedirectResponse
    {
        $this->require(Capability::ManageBudgets);
        $context = $this->ledger();
        $settings = $this->settings() ?? abort(404);

        $v = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'months' => ['required', 'integer', 'between:1,'.PlanBudgets::MAX_MONTHS],
        ]);

        $start = CarbonImmutable::parse($v['starts_on'])->startOfMonth();
        $budget = $plans->create($context, $request->user(), [
            'name' => trim($v['name']), 'starts_on' => $start->toDateString(), 'ends_on' => $start->addMonthsNoOverflow((int) $v['months'] - 1)->endOfMonth()->toDateString(),
            'currency' => $settings->base_currency,
        ]);

        return redirect()->route('planning.budgets.edit', $budget)->with('status', 'Plan created. Add the amounts you intend to earn and spend.');
    }

    public function show(PlanBudget $planBudget, PlanBudgets $plans, ApprovalService $approvals): View
    {
        $access = $this->access();
        $lines = $planBudget->cells()->with('glAccount')->get();
        $names = Dimension::query()->pluck('name', 'ref');
        $months = $plans->months($planBudget);

        $grid = [];
        foreach ($lines as $line) {
            $key = $line->gl_account_id.'|'.($line->dimension ?? '');
            $grid[$key]['account'] = $line->glAccount;
            $grid[$key]['dimension'] = $line->dimension;
            $grid[$key]['months'][$line->month->toDateString()] = $line->amount_cents;
        }
        uasort($grid, fn ($a, $b) => [$a['account']->class, $a['account']->code, $a['dimension']] <=> [$b['account']->class, $b['account']->code, $b['dimension']]);

        return view('planning.budgets.show', [
            'budget' => $planBudget, 'grid' => $grid, 'months' => $months, 'totals' => $plans->totals($planBudget), 'dimensions' => $names,
            'approval' => $approvals->latestFor($planBudget), 'canManage' => $access->can(Capability::ManageBudgets), 'canApprove' => $access->can(Capability::ApproveBudget),
            'needsApproval' => ApprovalService::requiresApproval('budget', $planBudget->approvalAmountCents(), $planBudget->approvalCurrency(), $this->ledger()),
        ]);
    }

    public function edit(PlanBudget $planBudget, PlanBudgets $plans): View
    {
        $this->require(Capability::ManageBudgets);
        abort_unless($planBudget->isEditable(), 409, 'Only a draft or rejected plan can be edited. Make a new version.');

        $existing = [];
        foreach ($planBudget->cells()->get() as $line) {
            $key = $line->gl_account_id.'|'.($line->dimension ?? '');
            $existing[$key]['account_id'] = $line->gl_account_id;
            $existing[$key]['dimension'] = $line->dimension;
            $existing[$key]['months'][$line->month->toDateString()] = $line->amount_cents;
        }

        return view('planning.budgets.edit', [
            'budget' => $planBudget, 'months' => $plans->months($planBudget), 'rows' => array_values($existing),
            'accounts' => GlAccount::query()->whereIn('class', ['income', 'expense'])->where('is_active', true)->where('is_postable', true)->orderBy('code')->get(),
            'dimensions' => Dimension::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, PlanBudget $planBudget, PlanBudgets $plans): RedirectResponse
    {
        $this->require(Capability::ManageBudgets);
        abort_unless($planBudget->isEditable(), 409, 'Only a draft or rejected plan can be edited. Make a new version.');
        $context = $this->ledger();
        $months = $plans->months($planBudget);

        $request->validate([
            'rows' => ['nullable', 'array', 'max:200'],
            'rows.*.gl_account_id' => ['nullable', 'integer', LedgerRule::exists('gl_accounts', $context)],
            'rows.*.dimension' => ['nullable', 'string', 'max:80', 'regex:/^[a-z_]{2,20}:[A-Za-z0-9_.-]{1,50}$/'],
            'rows.*.annual' => ['nullable', new MoneyAmount(allowZero: true)],
            'rows.*.months' => ['nullable', 'array'],
            'rows.*.months.*' => ['nullable', new MoneyAmount(allowZero: true)],
        ]);

        $rows = [];
        foreach ((array) $request->input('rows', []) as $row) {
            if (empty($row['gl_account_id'])) {
                continue;
            }
            $cells = [];
            foreach ((array) ($row['months'] ?? []) as $m => $amount) {
                if (in_array($m, $months, true) && $amount !== null && $amount !== '') {
                    $cells[$m] = Money::parse((string) $amount);
                }
            }
            $rows[] = [
                'gl_account_id' => (int) $row['gl_account_id'], 'dimension' => $row['dimension'] ?? null,
                'months' => $cells, 'annual' => ($row['annual'] ?? '') !== '' ? Money::parse((string) $row['annual']) : null,
            ];
        }

        try {
            $count = $plans->setLines($planBudget, $rows);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['rows' => $e->getMessage()])->withInput();
        }

        return redirect()->route('planning.budgets.show', $planBudget)->with('status', $count.' amount'.($count === 1 ? '' : 's').' saved.');
    }

    public function submit(Request $request, PlanBudget $planBudget, PlanBudgets $plans): RedirectResponse
    {
        $access = $this->require(Capability::ManageBudgets);
        $needs = ApprovalService::requiresApproval('budget', $planBudget->approvalAmountCents(), $planBudget->approvalCurrency(), $this->ledger());
        abort_unless($needs || $access->can(Capability::ApproveBudget), 403, 'No approval policy covers budgets, so a plan is approved by someone who can approve budgets (an owner, finance manager or approver). Ask them to submit it.');

        try {
            $budget = $plans->submit($planBudget, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['plan' => $e->getMessage()]);
        }

        return redirect()->route('planning.budgets.show', $budget)->with('status', $budget->status === 'approved' ? 'Plan approved.' : 'Plan sent for approval.');
    }

    public function version(Request $request, PlanBudget $planBudget, PlanBudgets $plans): RedirectResponse
    {
        $this->require(Capability::ManageBudgets);

        return redirect()->route('planning.budgets.edit', $plans->newVersion($planBudget, $request->user()))->with('status', 'A new version was made from this plan. The original is unchanged.');
    }

    public function reforecast(Request $request, PlanBudget $planBudget, PlanBudgets $plans, ReportRunner $runner): RedirectResponse
    {
        $this->require(Capability::ManageBudgets);
        $v = $request->validate(['as_of' => ['required', 'date_format:Y-m-d'], 'method' => ['required', 'in:hold,run_rate']]);

        try {
            $forecast = $plans->reforecast($planBudget, CarbonImmutable::parse($v['as_of']), $v['method'], $request->user(),
                $runner->request($this->ledger(), 'profit-loss', ['from' => $planBudget->starts_on->toDateString(), 'to' => $planBudget->ends_on->toDateString()]));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['as_of' => $e->getMessage()]);
        }

        return redirect()->route('planning.budgets.show', $forecast)->with('status', 'Re-forecast made. Months already ended are the books\' actuals; later months are projected.');
    }
}
