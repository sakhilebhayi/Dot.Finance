<?php

namespace App\Http\Controllers\Tax;

use App\Enums\Capability;
use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxHoliday;
use App\Services\Tax\DeadlineGenerator;
use App\Services\Tax\TaxChecklists;
use App\Services\Tax\TaxExplainer;
use App\Services\Tax\TaxReminder;
use App\Support\FinanceAudit;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CalendarController extends TaxController
{
    public function index(Request $request): View|RedirectResponse
    {
        $this->access();
        $profile = $this->profileOrWizard();
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }

        $filter = $request->query('show', 'open');
        $query = TaxDeadline::query()->forContext($this->ctx())->orderBy('due_date')->orderBy('obligation');
        match ($filter) {
            'history' => $query->whereIn('status', ['submitted', 'paid', 'waived']),
            'all' => null,
            default => $query->whereIn('status', ['open', 'prepared']),
        };

        $today = CarbonImmutable::today();

        return view('tax.calendar.index', [
            'profile' => $profile,
            'deadlines' => $query->limit(200)->get(),
            'filter' => in_array($filter, ['open', 'history', 'all'], true) ? $filter : 'open',
            'today' => $today,
            'holidays' => TaxHoliday::query()->where('jurisdiction', $profile->jurisdiction)->whereBetween('holiday_date', [$today->toDateString(), $today->addMonths(14)->toDateString()])->orderBy('holiday_date')->get(),
            'holidaysCovered' => TaxHoliday::query()->where('jurisdiction', $profile->jurisdiction)->whereBetween('holiday_date', [$today->startOfYear()->toDateString(), $today->addYear()->endOfYear()->toDateString()])->exists(),
            'canManage' => $this->can(Capability::PrepareTaxReturn),
        ]);
    }

    public function generate(DeadlineGenerator $generator, TaxReminder $reminders): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);
        $profile = $this->profileOrWizard();
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }

        $result = $generator->generate($this->ctx(), $profile);
        $reminders->refresh($this->ctx());
        $message = "Calendar refreshed from the rules: {$result['created']} added, {$result['updated']} updated, {$result['unchanged']} left as they were.";
        if ($result['skipped'] !== []) {
            $message .= ' '.implode(' ', $result['skipped']);
        }

        return redirect()->route('tax.calendar.index')->with('status', $message);
    }

    public function show(TaxDeadline $taxDeadline, TaxChecklists $checklists, TaxExplainer $explainer): View|RedirectResponse
    {
        $this->access();
        $profile = $this->profileOrWizard();
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }

        return view('tax.calendar.show', [
            'deadline' => $taxDeadline->load('taxReturn'),
            'profile' => $profile,
            'checklist' => $checklists->for($this->ctx(), $taxDeadline, $profile),
            'missing' => $explainer->missing($this->ctx(), $taxDeadline, $profile)['items'],
            'canManage' => $this->can(Capability::PrepareTaxReturn),
            'today' => CarbonImmutable::today(),
        ]);
    }

    public function tick(Request $request, TaxDeadline $taxDeadline, TaxChecklists $checklists): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);
        $data = $request->validate(['item' => ['required', 'string', 'max:60'], 'note' => ['nullable', 'string', 'max:255'], 'undo' => ['nullable', 'boolean']]);

        try {
            if ($request->boolean('undo')) {
                $checklists->untick($this->ctx(), $taxDeadline, $data['item']);
            } else {
                $checklists->tick($this->ctx(), $taxDeadline, $data['item'], $data['note'] ?? null, $request->user());
            }
        } catch (\App\Services\Tax\Exceptions\TaxException $e) {
            return back()->withErrors(['item' => $e->getMessage()]);
        }

        return back()->with('status', 'Checklist updated.');
    }

    public function waive(Request $request, TaxDeadline $taxDeadline): RedirectResponse
    {
        $this->require(Capability::RecordTaxSubmission);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);

        if ($taxDeadline->status !== 'open') {
            return back()->withErrors(['reason' => 'Only an open deadline can be set aside.']);
        }

        $taxDeadline->forceFill(['status' => 'waived', 'waived_reason' => $data['reason']])->save();
        FinanceAudit::record($this->ctx()->ownerAttributes(), 'waived', 'tax_deadline', (int) $taxDeadline->id, $taxDeadline->label().' '.$taxDeadline->period_label, ['status' => ['after' => 'waived']], $data['reason'], null, $request->user()->id);

        return redirect()->route('tax.calendar.index')->with('status', 'Set aside, with your reason on record. Use this for something already filed before you started here.');
    }
}
