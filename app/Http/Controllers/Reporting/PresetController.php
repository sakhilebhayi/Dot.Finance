<?php

namespace App\Http\Controllers\Reporting;

use App\Enums\Capability;
use App\Models\Reporting\ReportPreset;
use App\Services\Reporting\ReportRegistry;
use App\Services\Reporting\ReportRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class PresetController extends ReportingController
{
    public function store(Request $request, ReportRegistry $registry, ReportRunner $runner): RedirectResponse
    {
        $this->access();
        $context = $this->ledger();

        $meta = $request->validate([
            'report' => ['required', 'string', Rule::in($registry->keys())],
            'name' => ['required', 'string', 'max:80'],
        ]);
        $input = $this->reportInput($request);

        try {
            $built = $runner->request($context, $meta['report'], $input);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        ReportPreset::query()->updateOrCreate(
            $context->ownerAttributes() + ['report_key' => $meta['report'], 'name' => trim($meta['name'])],
            ['params' => $built->presetParams(), 'created_by' => $request->user()->id],
        );

        return redirect()->route('reports.'.$meta['report'], $built->presetParams())->with('status', 'Saved as "'.trim($meta['name']).'".');
    }

    public function destroy(Request $request, ReportPreset $reportPreset): RedirectResponse
    {
        $access = $this->access();
        abort_unless($reportPreset->created_by === $request->user()->id || $access->can(Capability::ManageMembers), 403, 'Only the person who saved a view, or an owner, can remove it.');

        $key = $reportPreset->report_key;
        $reportPreset->delete();

        return redirect()->route('reports.'.$key)->with('status', 'Saved view removed.');
    }
}
