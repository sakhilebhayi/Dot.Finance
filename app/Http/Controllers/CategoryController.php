<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    /**
     * List the authenticated user's categories.
     */
    public function index(Request $request)
    {
        $categories = Category::where('user_id', $request->user()->id)
            ->withCount('transactions')
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

    /**
     * Delete a category owned by the authenticated user.
     */
    public function destroy(Category $category)
    {
        $this->authorize('delete', $category);

        $category->delete();

        return redirect()->route('categories.index')->with('status', 'Category deleted.');
    }
}
