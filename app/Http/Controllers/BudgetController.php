<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BudgetController extends Controller
{
    /**
     * List the authenticated user's budgets, most recent period first.
     */
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $budgets = Budget::where('user_id', $userId)
            ->with('category')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get();

        $categories = Category::where('user_id', $userId)->orderBy('name')->get();

        return view('budgets.index', compact('budgets', 'categories'));
    }

    /**
     * Persist a new budget for the authenticated user.
     */
    public function store(Request $request)
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('user_id', $userId)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2000,2100'],
        ]);

        $validated['user_id'] = $userId;

        $exists = Budget::where('user_id', $userId)
            ->where('category_id', $validated['category_id'])
            ->where('month', $validated['month'])
            ->where('year', $validated['year'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['category_id' => 'A budget for this category and period already exists.'])->withInput();
        }

        Budget::create($validated);

        return redirect()->route('budgets.index')->with('status', 'Budget added.');
    }

    /**
     * Delete a budget owned by the authenticated user.
     */
    public function destroy(Budget $budget)
    {
        $this->authorize('delete', $budget);

        $budget->delete();

        return redirect()->route('budgets.index')->with('status', 'Budget deleted.');
    }
}
