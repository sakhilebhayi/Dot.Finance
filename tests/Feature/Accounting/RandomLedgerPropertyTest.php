<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Accounting\JournalEntry;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\ChartTemplates;
use App\Services\Accounting\GlBackfill;
use App\Services\Accounting\GlQuery;
use App\Services\Accounting\GlSweeper;
use App\Services\Accounting\GlSync;
use App\Services\Accounting\GlVerifier;
use App\Services\Accounting\TrialBalance;
use App\Services\TransferService;
use App\Support\Ledger\LedgerContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsAccounting;
use Tests\TestCase;

/**
 * Randomised ledgers (a fixed list of seeds, so a failure names the seed and replays): whatever sequence of
 * income, expense, transfers (including ZAR/USD), edits, deletions and opening-balance changes a ledger has,
 * the journals balance, every cash account ties out to the cent, the trial balance agrees, and the
 * backfill is idempotent.
 */
class RandomLedgerPropertyTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const SEEDS = [1, 2, 3, 5, 8, 13, 21, 34, 55, 89, 144, 233, 377, 610, 987, 1597, 2584, 4181, 6765, 10946];

    /** The fixed seeds, or seeds 1..N when GL_PROPERTY_SEEDS=N is set (a longer hunt, run by hand). */
    private function seeds(): array
    {
        if (($only = (int) getenv('GL_PROPERTY_SEED')) > 0) {
            return [$only];
        }

        $n = (int) getenv('GL_PROPERTY_SEEDS');

        return $n > 0 ? range(1, $n) : self::SEEDS;
    }

    private User $user;

    private LedgerContext $ctx;

    /** @var list<Account> */
    private array $accounts = [];

    private ?Account $usd = null;

    /** @var list<Category> */
    private array $categories = [];

    private function date(): string
    {
        return Carbon::parse('2026-01-01')->addDays(mt_rand(0, 180))->toDateString();
    }

    private function build(int $seed): void
    {
        mt_srand($seed);
        $this->user = User::factory()->create();
        $this->ctx = LedgerContext::personal($this->user);
        $this->accounts = [];

        foreach (['checking', 'savings', 'credit', 'investment', 'cash'] as $i => $type) {
            if (mt_rand(0, 4) === 0 && $i > 1) {
                continue; // not every ledger has every kind of account
            }
            $this->accounts[] = Account::factory()->create([
                'user_id' => $this->user->id, 'type' => $type, 'name' => "{$type} {$seed}",
                'opening_balance_cents' => mt_rand(-50_000, 400_000), 'opening_balance_date' => mt_rand(0, 3) === 0 ? null : Carbon::parse('2026-01-01')->addDays(mt_rand(0, 20))->toDateString(),
            ]);
        }
        $this->usd = mt_rand(0, 1) ? Account::factory()->currency('USD')->create(['user_id' => $this->user->id, 'name' => "usd {$seed}", 'opening_balance_cents' => 0, 'opening_balance_date' => '2026-01-01']) : null;

        $this->categories = [];
        foreach (['income', 'expense', 'expense', 'income'] as $type) {
            $this->categories[] = Category::factory()->create(['user_id' => $this->user->id, 'type' => $type]);
        }
    }

    private function category(string $type): ?Category
    {
        $matching = array_values(array_filter($this->categories, fn (Category $c) => $c->type === $type));

        return mt_rand(0, 3) === 0 ? null : $matching[array_rand($matching)];
    }

    /** A row chosen with the seeded generator (the database's own RANDOM() would make a failing seed unrepeatable). */
    private function pick(Builder $query): ?Transaction
    {
        $ids = $query->orderBy('id')->pluck('id')->all();

        return $ids === [] ? null : Transaction::query()->forContext($this->ctx)->find($ids[mt_rand(0, count($ids) - 1)]);
    }

    private function randomAccount(): Account
    {
        return $this->accounts[array_rand($this->accounts)];
    }

    /** One random operation, written the way the app writes it, followed by the posting call the app makes. */
    private function operate(bool $post): string
    {
        $sync = app(GlSync::class);
        $roll = mt_rand(1, 100);

        if ($roll <= 40) { // income or expense
            $type = mt_rand(0, 1) ? 'income' : 'expense';
            $t = Transaction::factory()->create([
                'account_id' => $this->randomAccount()->id, 'category_id' => $this->category($type)?->id, 'type' => $type,
                'amount_cents' => mt_rand(1, 250_000), 'date' => $this->date(),
            ]);
            $post && LedgerContext::run($this->ctx, fn () => $sync->transaction($t));

            return "create {$type} #{$t->id} {$t->amount_cents} on {$t->date->toDateString()} acct {$t->account_id}";
        } elseif ($roll <= 55 && count($this->accounts) > 1) { // same-currency transfer
            $pair = array_rand($this->accounts, 2);
            LedgerContext::run($this->ctx, fn () => app(TransferService::class)->create($this->user, $this->accounts[$pair[0]], $this->accounts[$pair[1]], mt_rand(1, 90_000), null, Carbon::parse($this->date()), 'Move', null));
            // TransferService journals the pair itself when accounting is on; with it off nothing posts.

            return 'transfer';
        } elseif ($roll <= 62 && $this->usd !== null) { // ZAR <-> USD transfer with an implied rate
            $zar = $this->randomAccount();
            [$from, $to] = mt_rand(0, 1) ? [$zar, $this->usd] : [$this->usd, $zar];
            [$out, $in] = $from->is($this->usd) ? [mt_rand(1, 5_000), mt_rand(1, 90_000)] : [mt_rand(1, 90_000), mt_rand(1, 5_000)];
            LedgerContext::run($this->ctx, fn () => app(TransferService::class)->create($this->user, $from, $to, $out, $in, Carbon::parse($this->date()), 'Dollars', null));

            return 'usd transfer';
        } elseif ($roll <= 78) { // edit
            $t = $this->pick(Transaction::query()->forContext($this->ctx)->whereIn('type', ['income', 'expense']));
            if ($t === null) {
                return 'nothing to edit';
            }
            $t->update(['amount_cents' => mt_rand(1, 250_000), 'date' => $this->date(), 'category_id' => $this->category($t->type)?->id]);
            $post && LedgerContext::run($this->ctx, fn () => $sync->transaction($t));

            return "edit #{$t->id} to {$t->amount_cents} on {$t->date->toDateString()} acct {$t->account_id}";
        } elseif ($roll <= 90) { // delete (a transfer leg takes its peer)
            $t = $this->pick(Transaction::query()->forContext($this->ctx));
            if ($t === null) {
                return 'nothing to delete';
            }
            $t->delete();
            $post && LedgerContext::run($this->ctx, fn () => $t->transfer_group !== null ? $sync->transferGroup($this->ctx, $t->transfer_group) : $sync->transaction($t));

            return "delete #{$t->id} ({$t->type}) acct {$t->account_id}";
        } else { // change an opening balance and date
            $account = $this->randomAccount();
            $account->update(['opening_balance_cents' => mt_rand(-50_000, 400_000), 'opening_balance_date' => Carbon::parse('2026-01-01')->addDays(mt_rand(0, 40))->toDateString()]);
            $post && LedgerContext::run($this->ctx, fn () => $sync->account($account));

            return "opening of {$account->id} to {$account->opening_balance_cents} on {$account->opening_balance_date->toDateString()}";
        }
    }

    private function assertHealthy(string $when, int $seed): void
    {
        $report = LedgerContext::run($this->ctx, fn () => app(GlVerifier::class)->verify($this->ctx));
        $this->assertTrue($report['ok'], "seed {$seed}, {$when}: ".json_encode(collect($report['checks'])->where('ok', false)->values()));

        foreach (LedgerContext::run($this->ctx, fn () => app(GlQuery::class)->cashTieOut($this->ctx)) as $row) {
            $this->assertTrue($row['ok'], "seed {$seed}, {$when}: {$row['name']} cash {$row['cash_cents']} vs journals {$row['gl_cents']}");
        }

        $tb = LedgerContext::run($this->ctx, fn () => app(TrialBalance::class)->asOf($this->ctx));
        $this->assertTrue($tb['balanced'] && $tb['equation']['holds'], "seed {$seed}, {$when}: trial balance");

        // Every entry balances line by line, straight from the table.
        $unbalanced = DB::table('journal_lines')->selectRaw('journal_entry_id, SUM(debit_cents) - SUM(credit_cents) AS d')->groupBy('journal_entry_id')->havingRaw('SUM(debit_cents) <> SUM(credit_cents)')->count();
        $this->assertSame(0, $unbalanced, "seed {$seed}, {$when}: unbalanced entries");
    }

    public function test_history_written_first_and_backfilled_balances_ties_out_and_replays_to_nothing(): void
    {
        foreach ($this->seeds() as $seed) {
            DB::table('journal_lines')->count(); // (each seed gets its own user, so ledgers never mix)
            $this->build($seed);
            for ($i = 0; $i < 45; $i++) {
                $this->operate(post: false);
            }

            $backfill = app(GlBackfill::class);
            LedgerContext::run($this->ctx, fn () => $backfill->prepare($this->ctx, 'ZAR', mt_rand(1, 12), ChartTemplates::PERSONAL));
            $run = $backfill->apply($this->ctx);
            $this->assertSame('verified', $run->status, "seed {$seed}: ".json_encode(collect($run->report['verify']['checks'])->where('ok', false)->values()));
            $this->assertHealthy('after backfill', $seed);

            $count = JournalEntry::withoutGlobalScopes()->where('user_id', $this->user->id)->count();
            $hashes = JournalEntry::withoutGlobalScopes()->where('user_id', $this->user->id)->orderBy('id')->pluck('entry_hash')->all();
            $backfill->apply($this->ctx);
            $backfill->apply($this->ctx);
            $this->assertSame($count, JournalEntry::withoutGlobalScopes()->where('user_id', $this->user->id)->count(), "seed {$seed}: replaying the backfill changed the books");
            $this->assertSame($hashes, JournalEntry::withoutGlobalScopes()->where('user_id', $this->user->id)->orderBy('id')->pluck('entry_hash')->all(), "seed {$seed}: replay changed the hash chain");
            $this->assertSame([], app(GlSweeper::class)->pending($this->ctx) === ['transactions' => [], 'transfers' => [], 'openings' => []] ? [] : ['pending'], "seed {$seed}: the sweeper found work after a clean backfill");
        }
    }

    public function test_the_same_operations_written_while_accounting_is_on_also_tie_out_after_every_step(): void
    {
        foreach (array_slice($this->seeds(), 0, (int) getenv('GL_PROPERTY_SEEDS') > 0 ? null : 10) as $seed) {
            $this->build($seed);
            $this->enableAccounting($this->ctx, 'ZAR', ChartTemplates::PERSONAL, mt_rand(1, 12));

            $log = [];
            for ($i = 0; $i < 40; $i++) {
                $log[] = $this->operate(post: true);

                // Checked after every step, so a failure names the operation that broke it.
                $this->assertHealthy("after step {$i} (".end($log).")\n".implode("\n", array_slice($log, -6)), $seed);
            }
            $this->assertHealthy('at the end', $seed);

            // The books built live agree with a from-scratch backfill of the same cash state: nothing is pending or stale.
            $pending = app(GlSweeper::class)->pending($this->ctx);
            $this->assertSame(['transactions' => [], 'transfers' => [], 'openings' => []], $pending, "seed {$seed}: dual-write left work for the sweeper");

            $before = JournalEntry::withoutGlobalScopes()->where('user_id', $this->user->id)->count();
            $maxId = (int) JournalEntry::withoutGlobalScopes()->where('user_id', $this->user->id)->max('id');
            $run = app(GlBackfill::class)->apply($this->ctx);
            $extra = JournalEntry::withoutGlobalScopes()->where('user_id', $this->user->id)->where('id', '>', $maxId)->get(['source_type', 'source_id', 'source_version', 'entry_date', 'total_cents'])->toJson();
            fwrite(STDERR, $extra === '[]' ? '' : "seed {$seed} extra: {$extra}\n".implode("\n", $log)."\n");
            $this->assertSame($before, JournalEntry::withoutGlobalScopes()->where('user_id', $this->user->id)->count(), "seed {$seed}: a backfill over live-written books posted more");
            $this->assertSame('verified', $run->status);
        }
    }

    public function test_the_sweeper_alone_rebuilds_the_books_after_arbitrary_unposted_writes(): void
    {
        foreach (array_slice($this->seeds(), (int) getenv('GL_PROPERTY_SEEDS') > 0 ? 0 : 10) as $seed) {
            $this->build($seed);
            $this->enableAccounting($this->ctx, 'ZAR', ChartTemplates::PERSONAL);

            for ($i = 0; $i < 40; $i++) {
                $this->operate(post: false); // accounting is on, but these writes forget to post
            }

            $result = app(GlSweeper::class)->run($this->ctx);
            $this->assertSame([], $result['deferred'], "seed {$seed}");
            $this->assertHealthy('after the sweeper', $seed);
        }
    }
}
