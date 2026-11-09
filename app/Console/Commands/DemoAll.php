<?php

namespace App\Console\Commands;

use App\Console\Commands\Tax\DemoSeed as TaxDemoSeed;
use App\Models\Accounting\GlAccount;
use App\Models\Bank\BankStatement;
use App\Models\Planning\Dimension;
use App\Models\Sales\SalesDocument;
use App\Models\Shared\Party;
use App\Models\Team;
use App\Models\User;
use App\Services\Accounting\Chart;
use App\Services\Accounting\GlVerifier;
use App\Support\Ledger\LedgerContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * LOCAL DEMO DATA ONLY. One command that builds the whole fictional demo in the order that works, and says what it did:
 *
 *   1. finance:demo-seed --sales      the cash ledger (twelve months, budgets, recurring items, intelligence) and the Sales history
 *   2. finance:demo-seed-purchases    suppliers, bills, payments, a claim, a recurring bill, a receipt in the inbox, a payment run
 *   3. finance:demo-seed-reporting    departments, a project, an approved plan and two scenarios (--no-documents: no stand-in invoices)
 *   4. finance:demo-seed-bank         last month reconciled, this month open with exceptions
 *   5. finance:tax:demo-seed          the second company (Fynbos Print Works): VAT entries, payroll, calendar, one filed VAT201
 *   6. finance:demo-controls          members, approval rules, spend limits, an access review, closed periods: LAST, because it closes
 *                                     ten months and any seed that posts into a closed month would stop with PeriodClosed
 *   +  a tidy step: the cost-of-sales accounts are marked as such, so the gross margin is the roastery's, not 89%
 *
 * It is idempotent: each step runs only when its own data is not there yet, so a second run changes nothing. Bank history and the
 * books are append-only, so a rebuild means a fresh local database: --fresh runs migrate:fresh first (asks unless --force).
 * It refuses outside a local or testing environment: the demo person has a published password.
 */
#[Signature('finance:demo-all {--fresh : Drop every table and migrate again first (local database only)} {--force : Do not ask before --fresh}')]
#[Description('Local only: build the whole fictional demo (both companies, every module) in the right order; safe to run again')]
class DemoAll extends Command
{
    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('finance:demo-all only runs in a local or testing environment, never in production or staging.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            if (! $this->option('force') && ! $this->confirm('This drops every table of the database "'.config('database.connections.'.config('database.default').'.database').'" and rebuilds it. Continue?')) {
                return self::FAILURE;
            }
            $this->call('migrate:fresh', ['--force' => true]);
        }

        $done = [];
        $step = function (string $label, bool $already, callable $run) use (&$done): bool {
            if ($already) {
                $this->line("  skip   {$label} (already there)");
                $done[$label] = 'already there';

                return true;
            }
            $this->line("  build  {$label}");
            $code = $run();
            $done[$label] = $code === self::SUCCESS ? 'built' : 'FAILED';

            return $code === self::SUCCESS;
        };

        $this->info('Building the demo.');

        $user = fn () => User::query()->where('email', DemoSeed::EMAIL)->first();
        $company = fn () => ($u = $user()) ? Team::query()->where('user_id', $u->id)->where('name', DemoSeed::COMPANY)->first() : null;
        $inKaroo = function (callable $check) use ($company): bool {
            $team = $company();

            return $team !== null && LedgerContext::run(LedgerContext::company($team), $check);
        };

        $ok = $step('1  cash ledger and sales history', $inKaroo(fn () => SalesDocument::query()->exists()), function () use ($user, $company) {
            // A base ledger without sales (an older run of finance:demo-seed alone) cannot take the Sales demo afterwards in a way that the
            // rest depends on, so build both together; if the base exists already, add just the sales.
            return $user() && $company() ? $this->call('finance:demo-seed-sales') : $this->call('finance:demo-seed', ['--sales' => true]);
        })
            && $step('2  purchases', $inKaroo(fn () => Party::query()->where('name', 'Cape Green Importers')->exists()), fn () => $this->call('finance:demo-seed-purchases'))
            && $step('3  reporting, planning, departments', $inKaroo(fn () => Dimension::query()->exists()), fn () => $this->call('finance:demo-seed-reporting', ['--no-documents' => true]))
            && $step('4  bank statements and reconciliation', $inKaroo(fn () => BankStatement::query()->exists()), fn () => $this->call('finance:demo-seed-bank'))
            && $step('5  tax company (Fynbos Print Works)', Team::query()->where('name', TaxDemoSeed::COMPANY)->exists(), fn () => $this->call('finance:tax:demo-seed'))
            && $step('6  controls, members, closed periods', false, fn () => $this->call('finance:demo-controls'));

        if (! $ok) {
            $this->error('A step failed; see its message above. Nothing after it was built.');

            return self::FAILURE;
        }

        $this->tidy($company());
        $this->report($company());

        $this->newLine();
        $this->info('Demo ready. Sign in as '.DemoSeed::EMAIL.' (password: the PASSWORD constant of finance:demo-seed) and choose a ledger in the switcher: '.DemoSeed::COMPANY.', '.TaxDemoSeed::COMPANY.', or the personal ledger.');

        return self::SUCCESS;
    }

    /** Mark the cost-of-sales categories as cost of sales, so the profit and loss shows a gross margin a roastery could have. */
    private function tidy(?Team $company): void
    {
        if ($company === null) {
            return;
        }
        $ctx = LedgerContext::company($company);
        LedgerContext::run($ctx, function () use ($ctx): void {
            foreach (GlAccount::query()->forContext($ctx)->whereIn('name', ['Green coffee', 'Packaging'])->where('class', 'expense')->get() as $account) {
                if ($account->subtype !== 'cost_of_sales') {
                    app(Chart::class)->update($account, ['subtype' => 'cost_of_sales']);
                    $this->line("  tidy   {$account->code} {$account->name} is now cost of sales");
                }
            }
        });
    }

    /** One line per demo company: do the books agree with themselves? */
    private function report(?Team $company): void
    {
        $this->newLine();
        foreach (array_filter([$company, Team::query()->where('name', TaxDemoSeed::COMPANY)->first()]) as $team) {
            $ctx = LedgerContext::company($team);
            $result = LedgerContext::run($ctx, fn () => app(GlVerifier::class)->verify($ctx));
            $bad = collect($result['checks'] ?? [])->where('ok', false)->pluck('key')->all();
            $this->line(sprintf('  books  %-34s %s', $team->name, $result['ok'] ? 'every check passes' : 'FAILS: '.implode(', ', $bad)));
        }
    }
}
