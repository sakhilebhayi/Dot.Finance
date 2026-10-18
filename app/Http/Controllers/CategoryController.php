<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoryController extends Controller
{
    /**
     * List the authenticated user's categories.
     *
     * No explicit user_id filter needed: Category's HasUserScope trait
     * applies it automatically to every query against this model.
     */
    public function index(Request $request)
    {
        $categories = Category::withCount('transactions')
            ->orderBy('name')
            ->get();

        return view('categories.index', compact('categories'));
    }

    /**
     * Persist a new category for the authenticated user.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:10'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'type' => ['required', Rule::in(['income', 'expense'])],
        ]);

        $validated['user_id'] = $request->user()->id;
        $validated['color'] = $validated['color'] ?? '#6366f1';

        Category::create($validated);

        return redirect()->route('categories.index')->with('status', 'Category added.');
    }

    public function edit(Category $category): View
    {
        $this->authorize('update', $category);

        return view('categories.edit', [
            'category' => $category,
            'typeLocked' => $this->typeLocked($category),
        ]);
    }

    /**
     * A category's type decides which transactions may use it, so it cannot
     * change underneath transactions or budgets that already rely on it.
     */
    public function update(Request $request, Category $category): RedirectResponse
    {
        $this->authorize('update', $category);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:10'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'type' => ['required', Rule::in(['income', 'expense'])],
        ]);

        if ($validated['type'] !== $category->type && $this->typeLocked($category)) {
            return back()->withErrors(['type' => 'This category already has transactions or budgets, so its type cannot change. Create a new category instead.'])->withInput();
        }

        $category->update([
            'name' => $validated['name'],
            'icon' => $validated['icon'] ?? null,
            'color' => $validated['color'] ?? $category->color,
            'type' => $validated['type'],
        ]);

        return redirect()->route('categories.index')->with('status', 'Category updated.');
    }

    /**
     * Soft-delete a category together with its budgets (a budget for a category
     * that no longer exists means nothing). Transactions keep pointing at it, so
     * history still names it.
     */
    public function destroy(Category $category)
    {
        $this->authorize('delete', $category);

        DB::transaction(function () use ($category) {
            Budget::where('category_id', $category->id)->get()->each->delete();
            $category->delete();
        });

        return redirect()->route('categories.index')->with('status', 'Category deleted.');
    }

    private function typeLocked(Category $category): bool
    {
        return Transaction::where('category_id', $category->id)->exists()
            || Budget::where('category_id', $category->id)->exists();
    }
}
