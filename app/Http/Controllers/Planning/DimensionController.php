<?php

namespace App\Http\Controllers\Planning;

use App\Enums\Capability;
use App\Models\Planning\Dimension;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Names for the department and project references journal lines carry. */
class DimensionController extends PlanningController
{
    public function index(): View
    {
        $access = $this->access();

        return view('planning.dimensions', ['dimensions' => Dimension::query()->orderBy('kind')->orderBy('name')->get(), 'kinds' => Dimension::KINDS, 'canManage' => $access->can(Capability::ManageBudgets)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->require(Capability::ManageBudgets);
        $v = $request->validate([
            'kind' => ['required', 'in:'.implode(',', array_keys(Dimension::KINDS))],
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $ref = $v['kind'].':'.$v['code'];
        if (Dimension::query()->where('ref', $ref)->exists()) {
            return back()->withErrors(['code' => "{$ref} is already named."])->withInput();
        }

        Dimension::create($this->ledger()->ownerAttributes() + ['ref' => $ref, 'kind' => $v['kind'], 'name' => trim($v['name']), 'is_active' => true]);

        return redirect()->route('planning.dimensions.index')->with('status', "{$ref} named.");
    }

    public function update(Request $request, Dimension $dimension): RedirectResponse
    {
        $this->require(Capability::ManageBudgets);
        $v = $request->validate(['name' => ['required', 'string', 'max:120'], 'is_active' => ['nullable', 'in:0,1']]);
        $dimension->update(['name' => trim($v['name']), 'is_active' => ($v['is_active'] ?? '1') === '1']);

        return redirect()->route('planning.dimensions.index')->with('status', 'Saved.');
    }
}
