<?php

namespace Tests\Feature\Purchases;

use App\Console\Commands\DemoSeed;
use App\Models\Purchases\Bill;
use App\Models\Team;
use App\Models\User;
use App\Services\Accounting\GlVerifier;
use App\Services\Purchases\ApLedger;
use App\Services\Purchases\MissingDocuments;
use App\Support\Ledger\LedgerContext;
use App\Support\Ledger\LedgerContextManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoSeedPurchasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_data_is_built_through_the_services_and_the_books_tie_out(): void
    {
        Storage::fake('local');
        $this->artisan('finance:demo-seed')->assertExitCode(0);
        $this->artisan('finance:demo-seed-purchases')->assertExitCode(0);

        $company = Team::query()->where('name', DemoSeed::COMPANY)->firstOrFail();
        $ctx = LedgerContext::company($company);

        $report = LedgerContext::run($ctx, fn () => app(GlVerifier::class)->verify($ctx));
        $this->assertTrue($report['ok'], json_encode(collect($report['checks'])->where('ok', false)->values()));

        $ap = app(ApLedger::class)->reconcile($ctx);
        $this->assertTrue($ap['ok'], json_encode($ap));
        $this->assertGreaterThan(0, $ap['books_cents']);

        LedgerContext::run($ctx, function () {
            $statuses = Bill::query()->pluck('status')->unique()->sort()->values()->all();
            $this->assertContains('draft', $statuses);
            $this->assertContains('partially_paid', $statuses);
            $this->assertContains('paid', $statuses);
            $this->assertContains('open', $statuses);
        });
        $this->assertGreaterThan(0, app(MissingDocuments::class)->countForLedger($ctx));

        // and it signs in and every page opens
        $user = User::query()->where('email', DemoSeed::EMAIL)->firstOrFail();
        $this->actingAs($user)->withSession([LedgerContextManager::SESSION_KEY => $company->id]);
        foreach (['purchases.index', 'purchases.bills.index', 'purchases.suppliers.index', 'purchases.claims.index', 'purchases.payments.index', 'purchases.runs.index', 'purchases.ap.index', 'purchases.receipts.index', 'purchases.documents.index', 'purchases.recurring.index', 'dashboard'] as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    public function test_it_refuses_without_the_base_demo_and_outside_local(): void
    {
        $this->artisan('finance:demo-seed-purchases')->assertExitCode(1);

        $this->app['env'] = 'production';
        $this->artisan('finance:demo-seed-purchases')->assertExitCode(1);
    }
}
