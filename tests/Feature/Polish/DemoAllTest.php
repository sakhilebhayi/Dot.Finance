<?php

namespace Tests\Feature\Polish;

use App\Console\Commands\DemoSeed;
use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalLine;
use App\Models\Accounting\LedgerSetting;
use App\Models\Shared\Party;
use App\Models\Team;
use App\Models\User;
use App\Services\AccountBalances;
use App\Services\Accounting\GlVerifier;
use App\Services\LedgerSummary;
use App\Services\Planning\CashPosition;
use App\Services\Purchases\ApLedger;
use App\Services\Sales\ReceivablesLedger;
use App\Support\Ledger\LedgerContext;
use App\Support\Ui\StateLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** R-44 polish: finance:demo-all builds one coherent demo, in order, and is safe to run again. */
class DemoAllTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_outside_local_and_testing(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $this->app['env'] = $environment;
            $this->artisan('finance:demo-all')->assertExitCode(1);
        }

        $this->assertSame(0, User::query()->where('email', DemoSeed::EMAIL)->count());
    }

    public function test_it_builds_everything_in_order_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('local');

        $this->artisan('finance:demo-all')->assertExitCode(0);

        $company = Team::query()->where('name', DemoSeed::COMPANY)->firstOrFail();
        $ctx = LedgerContext::company($company);
        $snapshot = fn () => LedgerContext::run($ctx, fn () => [
            'parties' => Party::query()->count(), 'accounts' => Account::query()->count(), 'journal_lines' => JournalLine::query()->count(),
        ]);
        $before = $snapshot();
        $this->assertGreaterThan(0, $before['journal_lines']);

        $this->artisan('finance:demo-all')->assertExitCode(0);

        $this->assertSame($before, $snapshot(), 'a second run adds no party, no account and no journal line');
        $this->assertSame(1, Team::query()->where('name', DemoSeed::COMPANY)->count());
    }

    public function test_the_demo_tells_one_coherent_story_with_none_of_the_artefacts_the_jury_found(): void
    {
        Storage::fake('local');
        $this->artisan('finance:demo-all')->assertExitCode(0);

        $company = Team::query()->where('name', DemoSeed::COMPANY)->firstOrFail();
        $ctx = LedgerContext::company($company);

        LedgerContext::run($ctx, function () use ($ctx): void {
            // No customer or supplier appears twice, and the stand-in "Cape Green Coffee Importers" of the old reporting seed is gone.
            $names = Party::query()->pluck('name');
            $this->assertSame($names->count(), $names->unique()->count(), 'no duplicate party: '.$names->sort()->implode(', '));
            $this->assertFalse($names->contains('Cape Green Coffee Importers'));
            $this->assertSame(1, $names->filter(fn ($n) => $n === 'Gardens Pantry')->count());

            // No stray suspense balance (the dollar account's transfers have both legs) and no "Bank: main account" holding money that no cash account has.
            $net = fn (string $code) => (int) JournalLine::query()->whereIn('gl_account_id', GlAccount::query()->where('code', $code)->pluck('id'))->selectRaw('COALESCE(SUM(debit_cents) - SUM(credit_cents), 0) AS n')->value('n');
            $this->assertSame(0, $net('1990'), 'Suspense is empty');
            $this->assertSame(0, $net('1010'), 'the template bank account holds nothing: every receipt and payment went to a real cash account');

            // One Rent account in the chart, not two (the category posts to the chart's own).
            $this->assertSame(1, GlAccount::query()->where('name', 'Rent')->count());

            // The dollar account is positive: it is not the headline's negative USD figure.
            $usd = AccountBalances::class;
            $usdAccount = app($usd)->forContext($ctx)->firstWhere('currency', 'USD');
            $this->assertGreaterThan(0, $usdAccount->balanceCents(), 'the USD account is in credit');

            // The cash figures agree with each other: the hero (liquid ZAR) is the projection's opening cash, and the books' bank accounts hold the same ZAR.
            $summary = app(LedgerSummary::class)->position($ctx)['ZAR'];
            $settings = LedgerSetting::query()->forContext($ctx)->firstOrFail();
            $this->assertSame($summary['cash_cents'], app(CashPosition::class)->now($ctx, $settings)['available_cents']);

            // The cost-of-sales accounts are marked, so the gross margin is a roastery's and not the 89% the jury saw.
            $this->assertSame('cost_of_sales', GlAccount::query()->where('name', 'Green coffee')->value('subtype'));
        });

        foreach ([$company, Team::query()->where('name', 'Fynbos Print Works (Pty) Ltd')->firstOrFail()] as $team) {
            $c = LedgerContext::company($team);
            $report = LedgerContext::run($c, fn () => app(GlVerifier::class)->verify($c));
            $this->assertTrue($report['ok'], $team->name.': '.json_encode(collect($report['checks'])->where('ok', false)->values()));

            // The supplier ledger agrees with the books in both companies: the tax company has no bills and no payables in the books, so
            // the "supplier ledger does not agree" alarm of the Fynbos command centre has nothing to fire on.
            $ap = app(ApLedger::class)->reconcile($c);
            $this->assertTrue($ap['ok'], $team->name.' payables: '.json_encode($ap));
            $ar = LedgerContext::run($c, fn () => app(ReceivablesLedger::class)->reconcile($c));
            $this->assertTrue($ar['ok'], $team->name.' receivables: invoices '.$ar['documents_cents'].' against the books '.$ar['ledger_cents']);
            $keys = collect(LedgerContext::run($c, fn () => StateLine::for($c)))->pluck('key');
            $this->assertFalse($keys->contains('ap-out'), $team->name.' raises no false supplier-ledger alarm');
        }
    }
}
