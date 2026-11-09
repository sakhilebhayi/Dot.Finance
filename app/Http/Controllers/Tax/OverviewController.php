<?php

namespace App\Http\Controllers\Tax;

use App\Models\Tax\TaxCorrespondence;
use App\Models\Tax\TaxDeadline;
use App\Services\Tax\ObligationEngine;
use App\Services\Tax\TaxReminder;
use Carbon\CarbonImmutable;
use Illuminate\View\View;

class OverviewController extends TaxController
{
    public function __invoke(ObligationEngine $engine, TaxReminder $reminders): View
    {
        $this->access();
        $ctx = $this->ctx();
        $profile = $this->profile();
        $today = CarbonImmutable::today();

        $deadlines = TaxDeadline::query()->forContext($ctx)->whereIn('status', ['open', 'prepared'])->orderBy('due_date')->limit(8)->get();

        return view('tax.index', [
            'profile' => $profile,
            'obligations' => $engine->assess($ctx, $profile, $today),
            'deadlines' => $deadlines,
            'today' => $today,
            'correspondence' => TaxCorrespondence::query()->forContext($ctx)->where('status', 'open')->orderBy('response_due_on')->limit(5)->get(),
            'undelivered' => $reminders->undelivered($ctx)->count(),
            'canManage' => $this->can(\App\Enums\Capability::ManageTax),
        ]);
    }
}
