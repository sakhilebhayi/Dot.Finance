<?php

namespace App\Http\Controllers\Planning;

use App\Models\Planning\Scenario;
use App\Rules\MoneyAmount;
use App\Services\Planning\AffordCalculator;
use App\Services\Planning\Assumptions;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AffordController extends PlanningController
{
    public function show(Request $request, AffordCalculator $calculator): View
    {
        $this->access();
        if ($view = $this->unlessEnabled()) {
            return $view;
        }

        $today = CarbonImmutable::today();
        $input = [];
        $result = null;

        if ($request->filled('amount')) {
            $v = $request->validate([
                'amount' => ['required', new MoneyAmount],
                'on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today->toDateString(), 'before_or_equal:'.$today->addMonthsNoOverflow(12)->subDay()->toDateString()],
                'mode' => ['required', 'in:once,monthly'],
                'months' => ['nullable', 'integer', 'between:1,12'],
                'buffer' => ['nullable', new MoneyAmount(allowZero: true)],
                'scenario' => ['nullable', 'integer', 'min:1'],
            ]);
            $scenario = isset($v['scenario']) ? (Scenario::query()->find((int) $v['scenario']) ?? abort(404)) : null;
            $input = $v;

            $result = $calculator->check(
                $this->ledger(), $this->settings(), $today, Money::parse((string) $v['amount']), CarbonImmutable::parse($v['on']), $v['mode'], (int) ($v['months'] ?? 1),
                isset($v['buffer']) ? Money::parse((string) $v['buffer']) : 0, $scenario ? Assumptions::normalise($scenario->assumptions) : [],
            );
        }

        return view('planning.afford', ['result' => $result, 'input' => $input, 'scenarios' => Scenario::query()->orderBy('name')->get(), 'today' => $today]);
    }
}
