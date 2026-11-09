<?php

namespace App\Http\Controllers\Planning;

use App\Models\Planning\Scenario;
use App\Rules\MoneyAmount;
use App\Services\Planning\Assumptions;
use App\Services\Planning\CashProjection;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Cash now (actual) and the 13-week or 12-month projection (never actual), optionally under a saved scenario. */
class CashController extends PlanningController
{
    public function index(Request $request, CashProjection $projection): View
    {
        $this->access();
        if ($view = $this->unlessEnabled()) {
            return $view;
        }

        $v = $request->validate([
            'horizon' => ['nullable', 'in:weeks_13,months_12'],
            'buffer' => ['nullable', new MoneyAmount(allowZero: true)],
            'scenario' => ['nullable', 'integer', 'min:1'],
        ]);

        $settings = $this->settings();
        $horizon = $v['horizon'] ?? 'weeks_13';
        $buffer = isset($v['buffer']) ? Money::parse((string) $v['buffer']) : 0;
        $scenario = isset($v['scenario']) ? (Scenario::query()->find((int) $v['scenario']) ?? abort(404)) : null;
        $today = CarbonImmutable::today();

        $base = $projection->project($this->ledger(), $settings, $today, $horizon, [], $buffer);
        $alt = $scenario ? $projection->project($this->ledger(), $settings, $today, $horizon, Assumptions::normalise($scenario->assumptions), $buffer) : null;

        return view('planning.cash', [
            'base' => $base, 'scenario' => $scenario, 'alt' => $alt, 'compare' => $alt ? $projection->compare($base, $alt) : null,
            'noEffect' => $alt ? $projection->noEffectReason(Assumptions::normalise($scenario->assumptions), $base, $alt) : null,
            'scenarios' => Scenario::query()->orderBy('name')->get(), 'horizon' => $horizon, 'buffer' => $buffer, 'horizons' => CashProjection::HORIZONS,
        ]);
    }
}
