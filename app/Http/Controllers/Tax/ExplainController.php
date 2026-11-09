<?php

namespace App\Http\Controllers\Tax;

use App\Enums\Capability;
use App\Models\Tax\TaxDeadline;
use App\Services\Tax\AccountantPack;
use App\Services\Tax\TaxExplainer;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExplainController extends TaxController
{
    public function index(Request $request, TaxExplainer $explainer): View|RedirectResponse
    {
        $this->access();
        $profile = $this->profileOrWizard();
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }
        $request->validate([
            'entry' => ['nullable', 'integer'], 'mode' => ['nullable', 'in:entry,change,periods,missing'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'before' => ['nullable', 'date'], 'after' => ['nullable', 'date'],
            'a_from' => ['nullable', 'date_format:Y-m-d'], 'a_to' => ['nullable', 'date_format:Y-m-d'], 'b_from' => ['nullable', 'date_format:Y-m-d'], 'b_to' => ['nullable', 'date_format:Y-m-d'],
            'deadline' => ['nullable', 'integer'],
        ]);

        $result = null;
        $mode = $request->query('mode');

        if ($mode === 'entry' && $request->filled('entry')) {
            $result = ['type' => 'entry'] + $explainer->transaction($this->ctx(), (int) $request->query('entry'));
        } elseif ($mode === 'change' && $request->filled('from') && $request->filled('to') && $request->filled('before') && $request->filled('after')) {
            $result = ['type' => 'change'] + $explainer->change($this->ctx(), CarbonImmutable::parse($request->query('from')), CarbonImmutable::parse($request->query('to')), CarbonImmutable::parse($request->query('before')), CarbonImmutable::parse($request->query('after')));
        } elseif ($mode === 'periods' && $request->filled('a_from') && $request->filled('a_to') && $request->filled('b_from') && $request->filled('b_to')) {
            $result = ['type' => 'periods'] + $explainer->comparePeriods($this->ctx(), CarbonImmutable::parse($request->query('a_from')), CarbonImmutable::parse($request->query('a_to')), CarbonImmutable::parse($request->query('b_from')), CarbonImmutable::parse($request->query('b_to')));
        } elseif ($mode === 'missing' && $request->filled('deadline')) {
            $deadline = TaxDeadline::query()->find((int) $request->query('deadline'));
            abort_if($deadline === null, 404);
            $result = ['type' => 'missing', 'deadline' => $deadline] + $explainer->missing($this->ctx(), $deadline, $profile);
        }

        return view('tax.explain.index', [
            'profile' => $profile, 'result' => $result, 'mode' => $mode,
            'deadlines' => TaxDeadline::query()->forContext($this->ctx())->whereIn('status', ['open', 'prepared'])->orderBy('due_date')->limit(30)->get(),
            'accountant' => $explainer->giveAccountant(),
            'today' => CarbonImmutable::today(),
        ]);
    }

    public function pack(): View
    {
        $this->access();
        $today = CarbonImmutable::today();

        return view('tax.explain.pack', [
            'defaultFrom' => $today->month >= 3 ? $today->setMonth(3)->startOfMonth()->subYear()->toDateString() : $today->subYears(2)->setMonth(3)->startOfMonth()->toDateString(),
            'defaultTo' => $today->toDateString(),
            'canExport' => $this->can(Capability::ExportReports),
            'canDetail' => $this->can(Capability::ViewPayrollDetail),
        ]);
    }

    public function download(Request $request, AccountantPack $pack): BinaryFileResponse
    {
        $this->require(Capability::ExportReports);
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'payroll_detail' => ['nullable', 'boolean']]);
        $withDetail = $request->boolean('payroll_detail') && $this->can(Capability::ViewPayrollDetail);

        $path = $pack->build($this->ctx(), CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to']), $request->user(), $withDetail);

        return response()->download($path, 'accountant-pack-'.$data['from'].'-to-'.$data['to'].'.zip', ['Content-Type' => 'application/zip', 'X-Content-Type-Options' => 'nosniff'])->deleteFileAfterSend(true);
    }
}
