<?php

namespace App\Http\Controllers;

use App\Services\CashFlowReport;
use Illuminate\Http\Request;

class CashFlowController extends Controller
{
    private const RANGES = [3, 6, 12, 24];

    public function __invoke(Request $request, CashFlowReport $report)
    {
        $months = (int) $request->query('months', 12);
        $months = in_array($months, self::RANGES, true) ? $months : 12;

        return view('reports.cash-flow', [
            'report' => $report->monthly($request->user()->id, $months),
            'months' => $months,
            'ranges' => self::RANGES,
        ]);
    }
}
