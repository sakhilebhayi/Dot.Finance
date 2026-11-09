<?php

namespace Tests\Feature;

use App\Console\Commands\DemoSeed;
use App\Models\Account;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerReconciler;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The local-only demo ledger: refuses outside local/testing, builds a ledger that ties out, and can be rebuilt. */
class DemoSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_run_in_production_and_in_staging(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $this->app['env'] = $environment;

            $this->artisan('finance:demo-seed')->assertExitCode(1);
        }

        $this->assertSame(0, User::query()->where('email', DemoSeed::EMAIL)->count(), 'nothing was created');
    }

    public function test_it_builds_a_fictional_business_ledger_that_ties_out_to_the_cent(): void
    {
        $this->artisan('finance:demo-seed')->assertExitCode(0);

        $user = User::query()->where('email', DemoSeed::EMAIL)->firstOrFail();
        $company = Team::query()->where('name', DemoSeed::COMPANY)->firstOrFail();
        $this->assertTrue($company->isCompany());

        $report = (new LedgerReconciler)->run(LedgerContext::company($company));
        $this->assertCount(5, $report['accounts']);
        $this->assertSame([], LedgerReconciler::problems($report), 'the demo ledger has no drift and no broken invariant');

        LedgerContext::run(LedgerContext::company($company), function (): void {
            $this->assertGreaterThan(300, Transaction::count(), 'about twelve months of entries');
            $this->assertSame(['USD', 'ZAR'], Account::query()->pluck('currency')->unique()->sort()->values()->all(), 'two currencies, never added together');
        });
        LedgerContext::run(LedgerContext::personal($user), fn () => $this->assertSame(2, Account::count()));
    }

    public function test_running_it_again_needs_fresh_and_fresh_rebuilds_without_duplicating(): void
    {
        $this->artisan('finance:demo-seed')->assertExitCode(0);
        $this->artisan('finance:demo-seed')->assertExitCode(1);
        $this->artisan('finance:demo-seed', ['--fresh' => true])->assertExitCode(0);

        $this->assertSame(1, User::query()->where('email', DemoSeed::EMAIL)->count());
        $company = Team::query()->where('name', DemoSeed::COMPANY)->firstOrFail();
        LedgerContext::run(LedgerContext::company($company), fn () => $this->assertSame(5, Account::count()));
    }
}
