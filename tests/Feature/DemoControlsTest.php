<?php

namespace Tests\Feature;

use App\Console\Commands\DemoSeed;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Controls\ApprovalPolicy;
use App\Models\Controls\ApprovalRequest;
use App\Models\Controls\LedgerMemberRole;
use App\Models\Controls\SpendLimit;
use App\Models\Shared\Party;
use App\Models\Team;
use App\Services\Accounting\GlSync;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The second local-only demo layer: accounting on, parties, members, policies and limits for looking at the Ledger, Controls and Parties screens. */
class DemoControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_outside_local_and_needs_the_base_demo_first(): void
    {
        $this->artisan('finance:demo-controls')->assertExitCode(1);

        $this->app['env'] = 'production';
        $this->artisan('finance:demo-controls')->assertExitCode(1);
    }

    public function test_it_switches_accounting_on_through_the_real_backfill_and_adds_the_people_and_rules(): void
    {
        $this->artisan('finance:demo-seed')->assertExitCode(0);
        $this->artisan('finance:demo-controls')->assertExitCode(0);

        $company = Team::query()->where('name', DemoSeed::COMPANY)->firstOrFail();
        $context = LedgerContext::company($company);

        $this->assertTrue(app(GlSync::class)->isEnabled($context), 'the whole history verified, so accounting is on');

        LedgerContext::run($context, function () use ($context): void {
            $this->assertGreaterThanOrEqual(7, Party::query()->count());
            $this->assertSame(1, Party::onlyTrashed()->count(), 'one archived party to show the archived state');
            $this->assertGreaterThan(0, AccountingPeriod::query()->whereNotNull('closed_at')->count(), 'closed months show the locked state');
            $this->assertGreaterThanOrEqual(2, ApprovalPolicy::query()->forContext($context)->count());
            $this->assertSame(2, SpendLimit::query()->forContext($context)->count());
            $this->assertSame(2, ApprovalRequest::query()->forContext($context)->where('status', 'pending')->count());
            $this->assertSame(3, LedgerMemberRole::query()->forContext($context)->count(), 'three of the four members hold a local role');
        });
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->artisan('finance:demo-seed')->assertExitCode(0);
        $this->artisan('finance:demo-controls')->assertExitCode(0);
        $context = LedgerContext::company(Team::query()->where('name', DemoSeed::COMPANY)->firstOrFail());

        $before = LedgerContext::run($context, fn () => [Party::withTrashed()->count(), SpendLimit::query()->forContext($context)->count(), ApprovalRequest::query()->forContext($context)->count()]);
        $this->artisan('finance:demo-controls')->assertExitCode(0);
        $after = LedgerContext::run($context, fn () => [Party::withTrashed()->count(), SpendLimit::query()->forContext($context)->count(), ApprovalRequest::query()->forContext($context)->count()]);

        $this->assertSame($before, $after);
    }
}
