<?php

namespace App\Http\Controllers\Planning;

use App\Services\Planning\Forecaster;
use App\Services\Reporting\ReportRunner;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ForecastController extends PlanningController
{
    public function index(Request $request, Forecaster $forecaster, ReportRunner $runner): View
    {
        $this->access();
        if ($view = $this->unlessEnabled()) {
            return $view;
        }

        $v = $request->validate([
            'method' => ['nullable', 'in:'.implode(',', array_keys(Forecaster::METHODS))],
            'months' => ['nullable', 'integer', 'between:3,12'],
        ]);

        $today = CarbonImmutable::today();
        $built = $runner->request($this->ledger(), 'profit-loss', []);
        $months = (int) ($v['months'] ?? 6);

        $out = [];
        foreach (['income' => 'Revenue', 'expense' => 'Expenses'] as $class => $label) {
            $out[$class] = ['label' => $label] + $forecaster->forecast($forecaster->history($built, $class, $today), $months, $v['method'] ?? null);
            $out[$class]['history'] = $forecaster->history($built, $class, $today);
        }

        return view('planning.forecasts', ['forecasts' => $out, 'months' => $months, 'method' => $v['method'] ?? null, 'methods' => Forecaster::METHODS, 'currency' => $built->currency(), 'today' => $today]);
    }
}
