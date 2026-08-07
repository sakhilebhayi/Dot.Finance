<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;


Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])->name('ecosystem.auth');
Route::get('/', function () {
    return view('welcome');
});

// Cookie Policy — Jetstream's termsAndPrivacyPolicy feature covers terms.show/policy.show
// natively (registered at /terms-of-service and /privacy-policy, reading resources/markdown/
// terms.md and policy.md). There's no Jetstream equivalent for a Cookie Policy, so this one is
// wired by hand, following the exact same Markdown-source convention.
Route::get('/cookies', function () {
    return view('cookies', [
        'cookies' => Str::markdown(file_get_contents(Jetstream::localizedMarkdownPath('cookies.md'))),
    ]);
})->name('cookies');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        $userId = auth()->id();

        $accounts = \App\Models\Account::where('user_id', $userId)->get();
        $totalBalance = $accounts->sum('balance');

        $thisMonth = now()->startOfMonth();
        $monthlyIncome = \App\Models\Transaction::where('user_id', $userId)
            ->where('type', 'income')
            ->where('date', '>=', $thisMonth)
            ->sum('amount');
        $monthlyExpense = \App\Models\Transaction::where('user_id', $userId)
            ->where('type', 'expense')
            ->where('date', '>=', $thisMonth)
            ->sum('amount');

        $recentTransactions = \App\Models\Transaction::where('user_id', $userId)
            ->with(['account', 'category'])
            ->latest('date')
            ->limit(10)
            ->get();

        $budgets = \App\Models\Budget::where('user_id', $userId)
            ->with('category')
            ->get();

        // AiInsight model now exists (app/Models/AiInsight.php); this counts
        // real rows for the user. No insight-generation service writes to
        // this table yet (Anthropic wiring is still roadmap — see wiki.md
        // §8), so this will genuinely read 0 until that's built, rather
        // than being a hardcoded stand-in for real data.
        $unreadInsights = \App\Models\AiInsight::where('user_id', $userId)->count();

        return view('dashboard', compact(
            'accounts', 'totalBalance', 'monthlyIncome', 'monthlyExpense',
            'recentTransactions', 'budgets', 'unreadInsights'
        ));
    })->name('dashboard');

    // Finance domain: every route below is bound to a model by id (route
    // model binding) and, for the show/edit/update/destroy actions, gated
    // through the matching Policy so one user can never load, edit, or
    // delete another user's accounts/transactions/categories/budgets by
    // guessing/incrementing the id (IDOR). Index/store are scoped to
    // auth()->id() directly since there's no team-scoping in this app.
    Route::resource('transactions', TransactionController::class)->except(['show']);
    Route::resource('accounts', AccountController::class)->only(['index', 'store', 'destroy']);
    Route::resource('categories', CategoryController::class)->only(['index', 'store', 'destroy']);
    Route::resource('budgets', BudgetController::class)->only(['index', 'store', 'destroy']);
});
