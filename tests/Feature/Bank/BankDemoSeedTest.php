<?php

namespace Tests\Feature\Bank;

use App\Console\Commands\DemoSeed;
use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankStatementLine;
use App\Models\Team;
use App\Models\User;
use App\Services\Bank\ExceptionClassifier;
use App\Services\Bank\MatchingService;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BankDemoSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_the_demo_gets_a_reconciled_month_and_an_open_one_with_exceptions(): void
    {
        $this->assertSame(0, Artisan::call('finance:demo-seed'));
        $this->assertSame(0, Artisan::call('finance:demo-seed-bank'), Artisan::output());

        $user = User::query()->where('email', DemoSeed::EMAIL)->firstOrFail();
        $context = LedgerContext::company(Team::query()->where('user_id', $user->id)->where('name', DemoSeed::COMPANY)->firstOrFail());

        LedgerContext::run($context, function () {
            $done = BankReconciliation::query()->where('status', 'completed')->get();
            $this->assertCount(1, $done);
            $this->assertSame(0, $done->first()->difference_cents);
            $this->assertGreaterThan(10, BankStatementLine::query()->whereNotNull('reconciliation_id')->count());

            $open = BankReconciliation::query()->where('status', 'draft')->firstOrFail();
            $suggest = app(MatchingService::class)->suggest($open);
            $this->assertNotEmpty($suggest['proposals']);

            $proposed = collect($suggest['proposals'])->pluck('line_ids')->flatten()->flip();
            $groups = collect(app(ExceptionClassifier::class)->classify($open, collect($suggest['lines'])->reject(fn ($l, $id) => $proposed->has($id))->values(), collect($suggest['entries'])))->pluck('key')->all();
            $this->assertContains('interest', $groups);
            $this->assertContains('money_in', $groups);
        });

        // running it again, or rebuilding the ledger around bank history, is refused rather than half-done
        $this->assertSame(1, Artisan::call('finance:demo-seed-bank'));
        $this->assertSame(1, Artisan::call('finance:demo-seed', ['--fresh' => true]));
        $this->assertStringContainsString('append-only', Artisan::output());
    }
}
