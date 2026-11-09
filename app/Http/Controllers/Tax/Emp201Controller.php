<?php

namespace App\Http\Controllers\Tax;

use App\Enums\Capability;
use App\Models\Tax\TaxReturn;
use App\Services\Tax\Exceptions\TaxException;
use App\Services\Tax\TaxReturnService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class Emp201Controller extends TaxController
{
    public function index(): View|RedirectResponse
    {
        $this->access();
        $profile = $this->profileOrWizard();
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }

        $today = CarbonImmutable::today();
        $months = [];
        for ($i = 1; $i <= 14; $i++) {
            $m = $today->startOfMonth()->subMonths($i);
            $months[] = [
                'month' => $m,
                'return' => TaxReturn::query()->forContext($this->ctx())->where('kind', 'emp201')->where('period_start', $m->toDateString())->orderByDesc('revision')->first(),
            ];
        }

        return view('tax.emp201.index', ['profile' => $profile, 'months' => $months, 'canPrepare' => $this->can(Capability::PrepareTaxReturn)]);
    }

    public function start(Request $request, TaxReturnService $returns): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);
        $profile = $this->profileOrWizard();
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        $month = CarbonImmutable::createFromFormat('!Y-m', $data['month'])->startOfMonth();

        try {
            if (! $profile->employs_staff) {
                throw new TaxException('The profile says the business has no employees, so there is no EMP201. Change the profile if that is wrong.');
            }
            $return = $returns->start($this->ctx(), 'emp201', $month, $month->endOfMonth()->startOfDay(), $month->format('F Y'), $profile, $request->user());
        } catch (TaxException $e) {
            return back()->withErrors(['month' => $e->getMessage()]);
        }

        return redirect()->route('tax.returns.show', $return);
    }
}
