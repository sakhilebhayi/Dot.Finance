<?php

namespace App\Http\Controllers\Reporting;

use App\Enums\Capability;
use App\Models\Accounting\GlAccount;
use App\Models\Planning\PlanBudget;
use App\Models\Reporting\ReportExport;
use App\Models\Reporting\ReportPreset;
use App\Services\Reporting\ReportPeriod;
use App\Services\Reporting\ReportRegistry;
use App\Services\Reporting\ReportResult;
use App\Services\Reporting\ReportRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class ReportController extends ReportingController
{
    public function index(ReportRegistry $registry, ReportRunner $runner): View|RedirectResponse
    {
        $access = $this->access();
        $context = $this->ledger();

        if (! $runner->enabled($context)) {
            return view('reports.not-enabled', ['canSetUp' => $access->canManageChart()]);
        }

        $groups = [];
        foreach ($registry->all() as $key => $report) {
            $groups[$report->group()][] = ['key' => $key, 'title' => $report->title(), 'description' => $report->description(), 'route' => 'reports.'.$key];
        }

        return view('reports.index', [
            'groups' => $groups,
            'presets' => ReportPreset::query()->orderBy('report_key')->orderBy('name')->get(),
            'exports' => ReportExport::query()->latest('id')->limit(5)->get(),
            'canExport' => $access->can(Capability::ExportReports),
        ]);
    }

    /** One page for every report: the report key arrives as a route default, never from the browser. */
    public function show(Request $request, ReportRegistry $registry, ReportRunner $runner, string $report): View|RedirectResponse
    {
        $access = $this->access();
        $context = $this->ledger();

        if (! $runner->enabled($context)) {
            return view('reports.not-enabled', ['canSetUp' => $access->canManageChart()]);
        }

        $input = $this->reportInput($request);
        $definition = $registry->get($report);
        // The page caps the general ledger; exports and saved views never carry the cap.
        if ($report === 'general-ledger' && ! $request->boolean('print')) {
            $input['cap'] = '1';
        }

        try {
            $built = $runner->request($context, $report, $input);
            $result = $runner->build($report, $built);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('reports.'.$report)->withErrors(['period' => $e->getMessage()]);
        }

        if (! $request->boolean('print') && $report !== 'general-ledger') { // the general ledger has its own cap
            $this->capRowsOnScreen($result);
        }

        return view($request->boolean('print') ? 'reports.print' : 'reports.show', [
            'result' => $result,
            'definition' => $definition,
            'request' => $built,
            'routeName' => 'reports.'.$report,
            'input' => array_diff_key($built->params(), ['cap' => 1]),
            'presets' => ReportPreset::query()->where('report_key', $report)->orderBy('name')->get(),
            'plans' => $report === 'budget-vs-actual' ? PlanBudget::query()->orderByDesc('id')->get() : collect(),
            'accounts' => $report === 'general-ledger' ? GlAccount::query()->orderBy('code')->get() : collect(),
            'canExport' => $access->can(Capability::ExportReports),
            'periodPresets' => ReportPeriod::PRESETS,
            'comparisons' => ReportPeriod::COMPARISONS,
        ]);
    }

    /** A report with thousands of rows (ageing for a big customer list) is shown in part on screen; the export and the print view carry every row. */
    private const SCREEN_ROW_LIMIT = 1000;

    private function capRowsOnScreen(ReportResult $result): void
    {
        $total = count($result->rows);
        if ($total <= self::SCREEN_ROW_LIMIT) {
            return;
        }

        // the closing total rows are kept so the page still ends on its figures
        $tail = [];
        for ($i = $total - 1; $i >= 0 && ($result->rows[$i]['type'] ?? null) === 'total'; $i--) {
            array_unshift($tail, $result->rows[$i]);
        }
        $result->rows = array_merge(array_slice($result->rows, 0, self::SCREEN_ROW_LIMIT), $tail);
        $result->notes[] = 'The screen shows the first '.number_format(self::SCREEN_ROW_LIMIT).' of '.number_format($total).' rows. The totals are for all of them. Export the report for every row.';
    }
}
