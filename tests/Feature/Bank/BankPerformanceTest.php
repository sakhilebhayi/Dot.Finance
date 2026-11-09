<?php

namespace Tests\Feature\Bank;

use App\Exceptions\Bank\ReconciliationRefused;
use App\Models\Account;
use App\Models\Bank\BankMatch;
use App\Models\Bank\BankStatement;
use App\Services\Bank\ExceptionClassifier;
use App\Services\Bank\MatchingService;
use App\Services\Bank\ReconciliationService;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsBankFixtures;
use Tests\TestCase;

/** A big statement must cost a bounded number of queries and a bounded time, not one query per line. */
class BankPerformanceTest extends TestCase
{
    use BuildsBankFixtures;
    use RefreshDatabase;

    private const LINES = 5000;

    public function test_five_thousand_lines_are_suggested_accepted_and_completed_in_bounded_queries(): void
    {
        $user = $this->user();
        $ctx = LedgerContext::personal($user);
        $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Busy', 'opening_balance_cents' => 0, 'opening_balance_date' => '2025-01-01']);
        $statement = BankStatement::create($account->ledgerOwner() + ['account_id' => $account->id, 'source' => 'csv', 'original_name' => 'big.csv', 'file_sha256' => hash('sha256', 'big'), 'currency' => 'ZAR', 'date_from' => '2025-01-01', 'date_to' => '2025-12-31']);

        // 5,000 lines and, for 4,500 of them, an entry of the same amount a day apart; 500 lines have no entry.
        $lines = [];
        $entries = [];
        $net = 0;
        $now = now()->toDateTimeString();
        for ($i = 0; $i < self::LINES; $i++) {
            $day = sprintf('2025-%02d-%02d', ($i % 12) + 1, ($i % 27) + 1);
            $cents = ($i % 2 === 0 ? -1 : 1) * (10_000 + $i * 7); // every amount is different
            $net += $cents;
            $lines[] = $account->ledgerOwner() + ['bank_statement_id' => $statement->id, 'account_id' => $account->id, 'line_no' => $i + 1, 'posted_on' => $day, 'amount_cents' => $cents, 'currency' => 'ZAR', 'description' => "PAYMENT REF {$i}", 'fingerprint' => hash('sha256', "perf{$i}"), 'created_at' => $now];
            if ($i < 4500) {
                $entries[] = $account->ledgerOwner() + ['account_id' => $account->id, 'description' => "Payment ref {$i}", 'amount' => number_format(abs($cents) / 100, 2, '.', ''), 'amount_cents' => abs($cents), 'currency' => 'ZAR', 'type' => $cents < 0 ? 'expense' : 'income', 'date' => $day, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach (array_chunk($lines, 500) as $chunk) {
            DB::table('bank_statement_lines')->insert($chunk);
        }
        foreach (array_chunk($entries, 500) as $chunk) {
            DB::table('transactions')->insert($chunk);
        }

        $matchedNet = 0;
        for ($i = 0; $i < 4500; $i++) {
            $matchedNet += ($i % 2 === 0 ? -1 : 1) * (10_000 + $i * 7);
        }

        $session = $this->openSession($user, $account, '2025-12-31', $matchedNet);

        $service = app(MatchingService::class);
        DB::enableQueryLog();
        $started = microtime(true);
        $suggest = LedgerContext::run($ctx, fn () => $service->suggest($session));
        $suggestQueries = count(DB::getQueryLog());
        $suggestSeconds = microtime(true) - $started;

        $this->assertCount(4500, $suggest['proposals']);
        $this->assertCount(4500, $suggest['auto']);
        $this->assertLessThan(15, $suggestQueries, "suggesting over {$this->lineCount()} lines took {$suggestQueries} queries");
        $this->assertLessThan(20.0, $suggestSeconds);

        DB::flushQueryLog();
        $started = microtime(true);
        $result = LedgerContext::run($ctx, fn () => $service->accept($session, $suggest['auto'], $user));
        $acceptQueries = count(DB::getQueryLog());
        $acceptSeconds = microtime(true) - $started;

        $this->assertSame(4500, $result['accepted']);
        $this->assertLessThan(150, $acceptQueries, "accepting 4,500 matches took {$acceptQueries} queries");
        $this->assertLessThan(20.0, $acceptSeconds);
        $this->assertSame(4500, BankMatch::query()->forContext($ctx)->where('state', 'active')->count());

        // the 500 unexplained lines are classified in a bounded number of queries too
        DB::flushQueryLog();
        $open = LedgerContext::run($ctx, fn () => collect($service->suggest($session)['lines']));
        $groups = LedgerContext::run($ctx, fn () => app(ExceptionClassifier::class)->classify($session, $open, collect()));
        $classifyQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(500, collect($groups)->sum('count'));
        $this->assertLessThan(40, $classifyQueries);

        // 500 lines remain unmatched: completing is refused, with the difference named, in a bounded number of queries
        $this->expectException(ReconciliationRefused::class);
        LedgerContext::run($ctx, fn () => app(ReconciliationService::class)->complete($session, $user));
    }

    private function lineCount(): int
    {
        return self::LINES;
    }
}
