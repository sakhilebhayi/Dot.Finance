<?php

namespace App\Http\Controllers\Reporting;

use App\Enums\Capability;
use App\Models\Accounting\GlAccount;
use App\Models\Planning\PlanBudget;
use App\Models\Reporting\ReportExport;
use App\Models\Reporting\ReportPreset;
use App\Services\Reporting\ReportPeriod;
use App\Services\Reporting\ReportRegistry;
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

        try {
            $built = $runner->request($context, $report, $input);
            $result = $runner->build($report, $built);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('reports.'.$report)->withErrors(['period' => $e->getMessage()]);
        }

        return view($request->boolean('print') ? 'reports.print' : 'reports.show', [
            'result' => $result,
            'definition' => $definition,
            'request' => $built,
            'routeName' => 'reports.'.$report,
            'input' => $built->params(),
            'presets' => ReportPreset::query()->where('report_key', $report)->orderBy('name')->get(),
            'plans' => $report === 'budget-vs-actual' ? PlanBudget::query()->orderByDesc('id')->get() : collect(),
            'accounts' => $report === 'general-ledger' ? GlAccount::query()->orderBy('code')->get() : collect(),
            'canExport' => $access->can(Capability::ExportReports),
            'periodPresets' => ReportPeriod::PRESETS,
            'comparisons' => ReportPeriod::COMPARISONS,
        ]);
    }
}
