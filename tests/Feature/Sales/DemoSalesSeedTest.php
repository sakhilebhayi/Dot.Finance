<?php

namespace Tests\Feature\Sales;

use App\Console\Commands\DemoSeed;
use App\Models\Sales\SalesDocument;
use App\Models\Team;
use App\Services\Accounting\GlVerifier;
use App\Services\Sales\ReceivablesLedger;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The demo sales history is made by the real services, so the books it leaves must verify and receivables must agree with them. */
class DemoSalesSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_sales_history_leaves_books_that_verify_and_receivables_that_agree(): void
    {
        $this->artisan('finance:demo-seed', ['--sales' => true])->assertExitCode(0);
        $company = Team::query()->where('name', DemoSeed::COMPANY)->firstOrFail();
        $ctx = LedgerContext::company($company);

        LedgerContext::run($ctx, function () use ($ctx) {
            $this->assertGreaterThan(12, SalesDocument::query()->count());
            $this->assertTrue(SalesDocument::query()->where('kind', 'credit_note')->exists());
            $this->assertTrue(SalesDocument::query()->where('currency', 'USD')->where('status', 'paid')->exists());

            $verify = app(GlVerifier::class)->verify($ctx);
            $this->assertTrue($verify['ok'], json_encode(collect($verify['checks'])->where('ok', false)->all()));

            $ledger = app(ReceivablesLedger::class);
            $this->assertTrue($ledger->reconcile($ctx)['ok']);
            $summary = $ledger->summary($ctx);
            $this->assertGreaterThan(0, $summary['overdue_count']);
            $this->assertGreaterThan(0, $summary['owed_base_cents']);
        });
    }

    public function test_running_the_sales_seed_twice_adds_nothing_and_it_refuses_outside_local(): void
    {
        $this->artisan('finance:demo-seed', ['--sales' => true])->assertExitCode(0);
        $count = SalesDocument::query()->withoutGlobalScopes()->count();
        $this->artisan('finance:demo-seed-sales')->assertExitCode(0);
        $this->assertSame($count, SalesDocument::query()->withoutGlobalScopes()->count());

        $this->app['env'] = 'production';
        $this->artisan('finance:demo-seed-sales')->assertExitCode(1);
    }
}
