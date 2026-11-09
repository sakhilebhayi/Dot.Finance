<?php

namespace Tests\Feature\Bank;

use App\Exceptions\Bank\ReconciliationRefused;
use App\Models\Account;
use App\Models\Bank\BankRule;
use App\Models\Bank\BankStatementLine;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Bank\ExceptionClassifier;
use App\Services\Bank\LineActions;
use App\Services\Bank\MatchingService;
use App\Services\Bank\ReconciliationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsBankFixtures;
use Tests\TestCase;

/** Two ledgers with the same shape of data: nothing one does can reach, read or change the other's. */
class BankIsolationTest extends TestCase
{
    use BuildsBankFixtures;
    use RefreshDatabase;

    private User $me;

    private User $them;

    private Account $mine;

    private Account $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = $this->user();
        $this->them = $this->user();
        $this->mine = Account::factory()->create(['user_id' => $this->me->id, 'name' => 'Mine', 'opening_balance_date' => '2026-01-01']);
        $this->theirs = Account::factory()->create(['user_id' => $this->them->id, 'name' => 'Theirs', 'opening_balance_date' => '2026-01-01']);
    }

    public function test_foreign_lines_and_entries_cannot_be_matched_into_my_session(): void
    {
        $myLine = $this->bankLine($this->mine, '2026-10-05', 1_000, 'Mine');
        $theirLine = $this->bankLine($this->theirs, '2026-10-05', 1_000, 'Theirs');
        $theirRow = $this->ledgerRow($this->theirs, '2026-10-05', 1_000, 'Their entry');
        $myRow = $this->ledgerRow($this->mine, '2026-10-05', 1_000, 'My entry');
        $session = $this->openSession($this->me, $this->mine, '2026-10-31', 1_000);

        foreach ([[[$theirLine->id], [$myRow->id]], [[$myLine->id], [$theirRow->id]], [[$theirLine->id], [$theirRow->id]]] as [$lines, $entries]) {
            try {
                $this->inLedger($this->mine, fn () => app(ReconciliationService::class)->matchRows($session, $lines, $entries, $this->me));
                $this->fail('A foreign id must be refused');
            } catch (ReconciliationRefused $e) {
                $this->assertSame('not_in_ledger', $e->reason);
            }
        }

        $this->assertNull(BankStatementLine::query()->forContext($this->theirs->ledgerContext())->findOrFail($theirLine->id)->bank_match_id);
        $this->assertNull(Transaction::query()->forContext($this->theirs->ledgerContext())->findOrFail($theirRow->id)->bank_match_id);
    }

    public function test_a_proposal_naming_foreign_rows_is_skipped_when_accepted(): void
    {
        $theirLine = $this->bankLine($this->theirs, '2026-10-05', 1_000, 'Theirs');
        $theirRow = $this->ledgerRow($this->theirs, '2026-10-05', 1_000, 'Their entry');
        $session = $this->openSession($this->me, $this->mine, '2026-10-31', 0);

        $result = $this->inLedger($this->mine, fn () => app(MatchingService::class)->accept($session, [[
            'line_ids' => [$theirLine->id], 'entry_ids' => [$theirRow->id], 'shape' => 'one_to_one', 'confidence' => 99, 'reason' => 'forged', 'amount_cents' => 1_000,
        ]], $this->me));

        $this->assertSame(['accepted' => 0, 'skipped' => 1], $result);
        $this->assertDatabaseCount('bank_matches', 0);
    }

    public function test_creating_an_entry_from_a_foreign_line_or_with_a_foreign_category_is_refused(): void
    {
        $theirLine = $this->bankLine($this->theirs, '2026-10-05', -1_000, 'Theirs');
        $myLine = $this->bankLine($this->mine, '2026-10-05', -1_000, 'Mine');
        $theirCategory = Category::factory()->create(['user_id' => $this->them->id, 'type' => 'expense']);
        $session = $this->openSession($this->me, $this->mine, '2026-10-31', -1_000);

        try {
            $this->inLedger($this->mine, fn () => app(LineActions::class)->createEntry($session, $this->me, $theirLine, null));
            $this->fail('foreign line');
        } catch (ReconciliationRefused $e) {
            $this->assertSame('not_in_ledger', $e->reason);
        }

        try {
            $this->inLedger($this->mine, fn () => app(LineActions::class)->createEntry($session, $this->me, $myLine, $theirCategory->id));
            $this->fail('foreign category');
        } catch (ReconciliationRefused $e) {
            $this->assertSame('not_in_ledger', $e->reason);
        }

        $this->assertSame(0, Transaction::query()->withoutGlobalScopes()->where('description', 'Theirs')->count());
    }

    public function test_a_session_cannot_be_started_on_a_foreign_account_or_from_a_foreign_statement(): void
    {
        $this->bankLine($this->mine, '2026-10-05', 1, 'm');
        $theirs = $this->statementFor($this->theirs);

        $this->expectException(ReconciliationRefused::class);
        $this->inLedger($this->mine, fn () => app(ReconciliationService::class)->start($this->me, $this->mine, '2026-10-31', 0, null, $theirs));
    }

    public function test_the_state_line_and_the_dashboard_panel_count_only_my_ledger(): void
    {
        $this->bankLine($this->mine, '2026-10-05', 1, 'mine');
        $this->bankLine($this->theirs, '2026-10-05', 1, 'theirs 1');
        $this->bankLine($this->theirs, '2026-10-06', 2, 'theirs 2');

        $this->inPersonal($this->me)->get(route('dashboard'))->assertOk()->assertSee('1 bank statement line needs a decision')->assertDontSee('3 bank statement lines');
        $this->inPersonal($this->them)->get(route('dashboard'))->assertOk()->assertSee('2 bank statement lines need a decision');
    }

    public function test_bank_rules_apply_only_inside_their_own_ledger(): void
    {
        BankRule::create(['user_id' => $this->them->id, 'name' => 'Theirs', 'pattern' => 'coffee']);
        $this->bankLine($this->mine, '2026-10-05', -500, 'COFFEE SHOP');
        $session = $this->openSession($this->me, $this->mine, '2026-10-31', -500);

        $groups = $this->inLedger($this->mine, function () use ($session) {
            $suggest = app(MatchingService::class)->suggest($session);

            return app(ExceptionClassifier::class)->classify($session, collect($suggest['lines'])->values(), collect($suggest['entries']));
        });

        $this->assertSame(['money_out'], array_column($groups, 'key'));
    }

    public function test_history_cannot_be_rewritten_even_around_the_models(): void
    {
        $session = $this->openSession($this->me, $this->mine, '2026-10-31', 0);

        $this->expectException(QueryException::class);
        DB::table('bank_reconciliation_events')->where('bank_reconciliation_id', $session->id)->delete();
    }
}
