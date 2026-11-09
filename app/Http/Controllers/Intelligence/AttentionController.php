<?php

namespace App\Http\Controllers\Intelligence;

use App\Http\Controllers\Controller;
use App\Models\Intelligence\FinanceInsight;
use App\Services\Intelligence\InsightEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** What needs attention: the findings the detectors made, with their evidence, and dismiss / snooze for those who may. */
class AttentionController extends Controller
{
    public function index(Request $request): View
    {
        $showAll = $request->query('show') === 'all';
        $this->ledger();

        $query = FinanceInsight::query();
        $findings = ($showAll ? $query : $query->visible())->get()
            ->sortByDesc(fn (FinanceInsight $f) => [$f->severityRank(), $f->last_seen_at?->timestamp])->values();

        return view('intel.attention', [
            'findings' => $findings,
            'showAll' => $showAll,
            'hiddenCount' => $showAll ? 0 : FinanceInsight::query()->whereIn('status', ['dismissed', 'snoozed', 'resolved'])->count(),
        ]);
    }

    public function dismiss(Request $request, FinanceInsight $insight, InsightEngine $engine): RedirectResponse
    {
        $engine->dismiss($insight, $request->user());

        return back()->with('status', 'Dismissed. It will not come back for this month\'s figures.');
    }

    public function snooze(Request $request, FinanceInsight $insight, InsightEngine $engine): RedirectResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'in:7,30,90']]);
        $engine->snooze($insight, $request->user(), (int) $data['days']);

        return back()->with('status', "Snoozed for {$data['days']} days.");
    }

    public function scan(InsightEngine $engine): RedirectResponse
    {
        $result = $engine->scan($this->ledger());

        return back()->with('status', sprintf('Checked the books: %d new, %d refreshed, %d cleared.', count($result['created']), $result['updated'], $result['resolved']));
    }
}
