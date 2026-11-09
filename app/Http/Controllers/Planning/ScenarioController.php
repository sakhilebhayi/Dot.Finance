<?php

namespace App\Http\Controllers\Planning;

use App\Enums\Capability;
use App\Models\Planning\Scenario;
use App\Services\Planning\Assumptions;
use App\Services\Planning\CashProjection;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class ScenarioController extends PlanningController
{
    public function index(): View
    {
        $access = $this->access();

        return view('planning.scenarios.index', ['scenarios' => Scenario::query()->orderBy('name')->get(), 'canManage' => $access->can(Capability::ManageBudgets)]);
    }

    public function create(): View
    {
        $this->require(Capability::ManageBudgets);

        return view('planning.scenarios.form', ['scenario' => null, 'types' => Assumptions::TYPES, 'rows' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->require(Capability::ManageBudgets);

        return $this->save($request, null);
    }

    public function show(Scenario $scenario, CashProjection $projection): View
    {
        $this->access();
        if ($view = $this->unlessEnabled()) {
            return $view;
        }

        $settings = $this->settings();
        $today = CarbonImmutable::today();
        $assumptions = Assumptions::normalise($scenario->assumptions);
        $base = $projection->project($this->ledger(), $settings, $today, $scenario->horizon, []);
        $alt = $projection->project($this->ledger(), $settings, $today, $scenario->horizon, $assumptions);

        return view('planning.scenarios.show', [
            'scenario' => $scenario, 'base' => $base, 'alt' => $alt, 'compare' => $projection->compare($base, $alt),
            'noEffect' => $projection->noEffectReason($assumptions, $base, $alt),
            'described' => array_map(fn ($a) => Assumptions::describe($a, $settings->base_currency), $assumptions),
            'canManage' => $this->access()->can(Capability::ManageBudgets),
        ]);
    }

    public function edit(Scenario $scenario): View
    {
        $this->require(Capability::ManageBudgets);

        return view('planning.scenarios.form', ['scenario' => $scenario, 'types' => Assumptions::TYPES, 'rows' => $scenario->assumptions]);
    }

    public function update(Request $request, Scenario $scenario): RedirectResponse
    {
        $this->require(Capability::ManageBudgets);

        return $this->save($request, $scenario);
    }

    public function destroy(Scenario $scenario): RedirectResponse
    {
        $this->require(Capability::ManageBudgets);
        $scenario->delete();

        return redirect()->route('planning.scenarios.index')->with('status', 'Scenario removed. The books were not touched.');
    }

    private function save(Request $request, ?Scenario $scenario): RedirectResponse
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'horizon' => ['required', 'in:weeks_13,months_12'],
            'assumptions' => ['nullable', 'array', 'max:20'],
        ]);

        $name = trim($v['name']);
        $taken = Scenario::query()->where('name', $name)->when($scenario, fn ($q) => $q->whereKeyNot($scenario->id))->exists();
        if ($taken) {
            return back()->withErrors(['name' => 'A scenario with that name already exists.'])->withInput();
        }

        try {
            $assumptions = Assumptions::normalise((array) $request->input('assumptions', []));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['assumptions' => $e->getMessage()])->withInput();
        }

        if ($assumptions === []) {
            return back()->withErrors(['assumptions' => 'Add at least one assumption to try.'])->withInput();
        }

        $context = $this->ledger();
        $scenario === null
            ? ($scenario = Scenario::create($context->ownerAttributes() + ['name' => $name, 'horizon' => $v['horizon'], 'assumptions' => $assumptions, 'created_by' => $request->user()->id]))
            : $scenario->update(['name' => $name, 'horizon' => $v['horizon'], 'assumptions' => $assumptions]);

        return redirect()->route('planning.scenarios.show', $scenario)->with('status', 'Scenario saved. It is a what-if only: nothing was posted to the books.');
    }
}
