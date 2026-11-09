<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\Accounting\ChartTemplates;
use App\Services\Accounting\GlBackfill;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * Signs in and opens every page that takes no id, in the personal ledger and in a company, with accounting off and
 * on. Nothing may answer with a server error. CI runs it again with the routes cached (php artisan route:cache),
 * which is how production serves them: it proves the route files register only routes.
 */
class SignedInSmokeTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    /** @return list<LaravelRoute> */
    private function pages(): array
    {
        return collect(Route::getRoutes()->getRoutes())->filter(fn (LaravelRoute $r) => in_array('GET', $r->methods(), true)
            && str_starts_with($r->getActionName(), 'App\\Http\\Controllers\\')
            && $r->parameterNames() === []
            && in_array('auth:sanctum', $r->gatherMiddleware(), true)
            && ! str_starts_with($r->uri(), 'auth/'))->values()->all();
    }

    public function test_the_page_list_is_not_empty_and_includes_the_accounting_pages(): void
    {
        $uris = array_map(fn (LaravelRoute $r) => $r->uri(), $this->pages());

        $this->assertGreaterThan(15, count($uris));
        $this->assertContains('accounting/trial-balance', $uris);
        $this->assertContains('dashboard', $uris);
    }

    public function test_every_page_answers_without_a_server_error_in_both_ledgers_with_accounting_off_and_on(): void
    {
        $user = $this->user();
        $company = $this->company($user);
        $personal = Account::factory()->create(['user_id' => $user->id, 'opening_balance_cents' => 10_000, 'opening_balance_date' => '2026-01-01']);
        $business = $this->companyAccount($company, ['opening_balance_cents' => 90_000, 'opening_balance_date' => '2026-01-01']);
        foreach ([$personal, $business] as $account) {
            $category = Category::factory()->create($account->ledgerOwner() + ['type' => 'expense']);
            Transaction::factory()->create(['account_id' => $account->id, 'category_id' => $category->id, 'type' => 'expense', 'amount_cents' => 1_500, 'date' => '2026-02-01']);
        }

        $seen = 0;
        foreach (['off', 'on'] as $accounting) {
            if ($accounting === 'on') {
                foreach ([[LedgerContext::personal($user), ChartTemplates::PERSONAL], [LedgerContext::company($company), ChartTemplates::COMPANY]] as [$ctx, $template]) {
                    LedgerContext::run($ctx, function () use ($ctx, $template) {
                        $backfill = app(GlBackfill::class);
                        $backfill->prepare($ctx, 'ZAR', 1, $template);
                        $backfill->apply($ctx);
                        $backfill->enable($ctx);
                    });
                }
            }

            foreach (['personal' => fn () => $this->inPersonal($user), 'company' => fn () => $this->inCompany($user, $company)] as $label => $enter) {
                foreach ($this->pages() as $route) {
                    $response = $enter()->get('/'.$route->uri());
                    $seen++;

                    $this->assertLessThan(500, $response->getStatusCode(), "{$label} ledger, accounting {$accounting}: GET /{$route->uri()} answered ".$response->getStatusCode());
                }
            }
        }

        $this->assertGreaterThan(60, $seen);
    }
}
