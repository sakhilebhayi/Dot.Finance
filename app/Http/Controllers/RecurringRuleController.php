<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\RecurringRule;
use App\Rules\MoneyAmount;
use App\Services\TransactionRecurrenceService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RecurringRuleController extends Controller
{
    public function __construct(private readonly TransactionRecurrenceService $schedule) {}

    public function index(): View
    {
        $rules = RecurringRule::with(['account', 'category'])
            ->orderByRaw('CASE WHEN ended_at IS NOT NULL OR next_occurrence_date IS NULL THEN 2 WHEN paused_at IS NOT NULL THEN 1 ELSE 0 END')
            ->orderBy('next_occurrence_date')
            ->orderBy('description')
            ->get();

        return view('recurring.index', compact('rules'));
    }

    public function edit(RecurringRule $rule): View
    {
        $this->authorize('update', $rule);

        return view('recurring.edit', [
            'rule' => $rule,
            'categories' => Category::where('type', $rule->type)->orderBy('name')->get(),
        ]);
    }

    /**
     * Description, amount, category, notes and cadence. Account and type are
     * fixed (a different account or type is a different rule); entries already
     * in the ledger are never rewritten.
     */
    public function update(Request $request, RecurringRule $rule): RedirectResponse
    {
        $this->authorize('update', $rule);

        $userId = $request->user()->id;

        $validated = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', new MoneyAmount],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $userId)->where('type', $rule->type)->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:255'],
            'recurrence_type' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'yearly'])],
            'recurrence_interval' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        // Rule::exists above already rejects a category of the wrong type with a
        // generic message; say why in plain words.
        if (! empty($validated['category_id']) && Category::find($validated['category_id'])?->type !== $rule->type) {
            return back()->withErrors(['category_id' => "This is an {$rule->type} rule, so its category must be an {$rule->type} category."])->withInput();
        }

        $this->schedule->update($rule, [
            'description' => $validated['description'],
            'amount_cents' => Money::parse((string) $validated['amount']),
            'category_id' => $validated['category_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'recurrence_type' => $validated['recurrence_type'],
            'recurrence_interval' => (int) $validated['recurrence_interval'],
        ]);

        return redirect()->route('recurring.index')->with('status', 'Recurring item updated. Entries already in your ledger are unchanged.');
    }

    public function pause(RecurringRule $rule): RedirectResponse
    {
        $this->authorize('update', $rule);
        $this->schedule->pause($rule);

        return redirect()->route('recurring.index')->with('status', 'Paused. Nothing will be generated until you resume it.');
    }

    public function resume(RecurringRule $rule): RedirectResponse
    {
        $this->authorize('update', $rule);
        $this->schedule->resume($rule);

        return redirect()->route('recurring.index')->with('status', 'Resumed. Periods that passed while it was paused are not back-filled.');
    }

    public function stop(Request $request, RecurringRule $rule): RedirectResponse
    {
        $this->authorize('update', $rule);

        $validated = $request->validate(['end_date' => ['nullable', 'date']]);

        $this->schedule->stop($rule, filled($validated['end_date'] ?? null) ? Carbon::parse($validated['end_date']) : null);

        return redirect()->route('recurring.index')->with('status', 'Stopped. It will not generate anything after its end date.');
    }
}
