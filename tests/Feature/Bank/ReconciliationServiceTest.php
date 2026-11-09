<?php

namespace Tests\Feature\Bank;

use App\Exceptions\Bank\ReconciledTransactionLocked;
use App\Exceptions\Bank\ReconciliationRefused;
use App\Models\Account;
use App\Models\Bank\BankMatch;
use App\Models\Bank\BankReconciliationEvent;
use App\Models\Bank\BankStatementLine;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Bank\MatchingService;
use App\Services\Bank\ReconciliationBalances;
use App\Services\Bank\ReconciliationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsBankFixtures;
use Tests\TestCase;

class ReconciliationServiceTest extends TestCase
{
    use BuildsBankFixtures;
    use RefreshDatabase;

    private User $user;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->user();
        $this->account = Account::factory()->create(['user_id' => $this->user->id, 'name' => 'Cheque', 'opening_balance_cents' => 100_000, 'opening_balance_date' => '2026-01-01']);
    }

    private function service(): ReconciliationService
    {
        return app(ReconciliationService::class);
    }

    public function test_the_happy_path_matches_everything_and_completes_at_zero_difference(): void
    {
        $this->ledgerRow($this->account, '2026-10-03', 25_000, 'Customer deposit INV-0042');
        $this->ledgerRow($this->account, '2026-10-05', -4_500, 'Checkers groceries');
        $this->bankLine($this->account, '2026-10-03', 25_000, 'EFT CREDIT INV-0042 ACME');
        $this->bankLine($this->account, '2026-10-06', -4_500, 'POS CHECKERS');

        // opening 100,000 + 25,000 - 4,500
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 120_500);

        $this->inLedger($this->account, function () use ($session) {
            $suggest = app(MatchingService::class)->suggest($session);
            $this->assertCount(2, $suggest['proposals']);
            $this->assertStringContainsString('Matched because', $suggest['proposals'][0]['reason']);

            $result = app(MatchingService::class)->accept($session, $suggest['proposals'], $this->user);
            $this->assertSame(2, $result['accepted']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', (string) DB::table('transactions')->whereNotNull('bank_match_id')->value('cleared_at'));

            $done = $this->service()->complete($session, $this->user);
            $this->assertSame('completed', $done->status);
            $this->assertSame(0, $done->difference_cents);
            $this->assertSame(120_500, $done->ledger_cleared_cents);
        });

        $this->assertSame(2, Transaction::query()->forContext($this->account->ledgerContext())->whereNotNull('reconciliation_id')->count());
        $this->assertSame(2, BankStatementLine::query()->forContext($this->account->ledgerContext())->whereNotNull('reconciliation_id')->count());
    }

    public function test_a_difference_blocks_completion_and_nothing_changes(): void
    {
        $this->ledgerRow($this->account, '2026-10-03', 25_000, 'Deposit');
        $this->bankLine($this->account, '2026-10-03', 25_000, 'Deposit');
        $this->bankLine($this->account, '2026-10-04', -999, 'Unknown debit');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 124_001);

        $this->inLedger($this->account, function () use ($session) {
            $matching = app(MatchingService::class);
            $matching->accept($session, $matching->suggest($session)['proposals'], $this->user);

            try {
                $this->service()->complete($session, $this->user);
                $this->fail('A difference must block completion');
            } catch (ReconciliationRefused $e) {
                $this->assertSame('difference', $e->reason);
                $this->assertStringContainsString('difference is', $e->getMessage());
            }
        });

        $this->assertSame('draft', $session->fresh()->status);
        $this->assertSame(0, Transaction::query()->forContext($this->account->ledgerContext())->whereNotNull('reconciliation_id')->count());
    }

    public function test_the_database_refuses_a_completed_session_with_a_difference(): void
    {
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 100_000);

        $this->expectException(QueryException::class);
        DB::table('bank_reconciliations')->where('id', $session->id)->update(['status' => 'completed', 'difference_cents' => 5, 'ledger_cleared_cents' => 100_000, 'completed_at' => now()]);
    }

    public function test_an_accepted_variance_needs_a_reason_and_an_authorised_person_and_posts_a_real_entry(): void
    {
        $this->bankLine($this->account, '2026-10-04', -999, 'Unknown debit');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 99_001);

        $this->inLedger($this->account, function () use ($session) {
            try {
                $this->service()->complete($session, $this->user, true, '');
                $this->fail('A reason is required');
            } catch (ReconciliationRefused $e) {
                $this->assertSame('no_reason', $e->reason);
            }

            $done = $this->service()->complete($session, $this->user, true, 'Bank fee we could not trace, accepted by the owner');
            $this->assertSame(-999, $done->variance_cents);
            $this->assertSame(1, $done->unmatched_accepted);
            $this->assertNotNull($done->variance_transaction_id);
        });

        $variance = Transaction::query()->forContext($this->account->ledgerContext())->findOrFail($session->fresh()->variance_transaction_id);
        $this->assertSame('expense', $variance->type);
        $this->assertSame(999, $variance->amount_cents);
        $this->assertDatabaseHas('bank_adjustments', ['bank_reconciliation_id' => $session->id, 'kind' => 'variance', 'amount_cents' => 999]);
    }

    public function test_a_matched_row_cannot_be_edited_or_deleted_and_the_message_says_why(): void
    {
        $row = $this->ledgerRow($this->account, '2026-10-03', 25_000, 'Deposit');
        $this->bankLine($this->account, '2026-10-03', 25_000, 'Deposit');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 125_000);

        $this->inLedger($this->account, function () use ($session, $row) {
            $matching = app(MatchingService::class);
            $matching->accept($session, $matching->suggest($session)['proposals'], $this->user);

            $fresh = Transaction::findOrFail($row->id);
            try {
                $fresh->update(['amount_cents' => 1]);
                $this->fail('A cleared row must not change its amount');
            } catch (ReconciledTransactionLocked $e) {
                $this->assertStringContainsString('matched to a bank statement line', $e->getMessage());
            }

            // what does not move a balance can still be corrected
            Transaction::findOrFail($row->id)->update(['notes' => 'checked']);

            $this->service()->complete($session, $this->user);

            try {
                Transaction::findOrFail($row->id)->delete();
                $this->fail('A reconciled row must not be deleted');
            } catch (ReconciledTransactionLocked $e) {
                $this->assertStringContainsString('reconciled', $e->getMessage());
            }
        });

        // and around the model too: the database refuses it
        $this->expectException(QueryException::class);
        DB::table('transactions')->where('id', $row->id)->update(['amount_cents' => 5]);
    }

    public function test_unreconcile_reopens_the_newest_session_with_a_reason_and_keeps_the_history(): void
    {
        $row = $this->ledgerRow($this->account, '2026-10-03', 25_000, 'Deposit');
        $this->bankLine($this->account, '2026-10-03', 25_000, 'Deposit');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 125_000);

        $this->inLedger($this->account, function () use ($session, $row) {
            $matching = app(MatchingService::class);
            $matching->accept($session, $matching->suggest($session)['proposals'], $this->user);
            $this->service()->complete($session, $this->user);

            try {
                $this->service()->reopen($session, $this->user, '');
                $this->fail('A reason is required');
            } catch (ReconciliationRefused $e) {
                $this->assertSame('no_reason', $e->reason);
            }

            $reopened = $this->service()->reopen($session, $this->user, 'Wrong statement balance typed');
            $this->assertSame('draft', $reopened->status);
            $this->assertSame(1, $reopened->reopened_count);
            $this->assertNull(Transaction::findOrFail($row->id)->reconciliation_id);
            // still matched, so still protected until un-matched
            $this->assertNotNull(Transaction::findOrFail($row->id)->bank_match_id);
        });

        $events = BankReconciliationEvent::query()->forContext($this->account->ledgerContext())->where('bank_reconciliation_id', $session->id)->orderBy('id')->pluck('event')->all();
        $this->assertSame(['started', 'matched_in_bulk', 'completed', 'reopened'], $events);
        $this->assertDatabaseHas('finance_audit_log', ['subject_type' => 'bank_reconciliation', 'subject_id' => $session->id, 'action' => 'reopened']);
    }

    public function test_only_the_newest_completed_session_can_be_reopened(): void
    {
        $this->ledgerRow($this->account, '2026-10-03', 25_000, 'Deposit');
        $this->bankLine($this->account, '2026-10-03', 25_000, 'Deposit');
        $first = $this->openSession($this->user, $this->account, '2026-10-31', 125_000);

        $this->inLedger($this->account, function () use ($first) {
            $matching = app(MatchingService::class);
            $matching->accept($first, $matching->suggest($first)['proposals'], $this->user);
            $this->service()->complete($first, $this->user);

            $second = $this->service()->start($this->user, $this->account, '2026-11-09', 125_000);
            $this->assertSame(125_000, $second->statement_opening_cents, 'the next session opens at the last closing balance');
            $this->service()->complete($second, $this->user);

            try {
                $this->service()->reopen($first, $this->user, 'Trying the older one first');
                $this->fail('Only the newest can be reopened');
            } catch (ReconciliationRefused $e) {
                $this->assertSame('not_newest', $e->reason);
            }
        });
    }

    public function test_one_open_session_per_account_and_the_date_must_move_forward(): void
    {
        $this->openSession($this->user, $this->account, '2026-10-31', 100_000);

        $this->expectException(ReconciliationRefused::class);
        $this->openSession($this->user, $this->account, '2026-11-05', 100_000);
    }

    public function test_split_matches_one_line_for_two_rows_and_two_lines_for_one_row(): void
    {
        $this->ledgerRow($this->account, '2026-10-10', 6_000, 'Invoice A part');
        $this->ledgerRow($this->account, '2026-10-10', 4_000, 'Invoice B part');
        $this->bankLine($this->account, '2026-10-11', 10_000, 'BULK EFT');
        $this->ledgerRow($this->account, '2026-10-15', -9_000, 'Rent');
        $this->bankLine($this->account, '2026-10-15', -5_000, 'RENT PART 1');
        $this->bankLine($this->account, '2026-10-16', -4_000, 'RENT PART 2');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 100_000 + 10_000 - 9_000);

        $this->inLedger($this->account, function () use ($session) {
            $matching = app(MatchingService::class);
            $suggest = $matching->suggest($session);
            $shapes = array_column($suggest['proposals'], 'shape');
            sort($shapes);
            $this->assertSame(['many_to_one', 'one_to_many'], $shapes);
            $this->assertSame(0, count($suggest['auto']), 'a split is never automatic');

            $this->assertSame(2, $matching->accept($session, $suggest['proposals'], $this->user, 'manual')['accepted']);
            $this->service()->complete($session, $this->user);
        });

        $this->assertSame(2, BankMatch::query()->forContext($this->account->ledgerContext())->whereIn('shape', ['one_to_many', 'many_to_one'])->count());
    }

    public function test_a_manual_match_whose_totals_differ_is_refused_with_the_amounts(): void
    {
        $row = $this->ledgerRow($this->account, '2026-10-03', 25_000, 'Deposit');
        $line = $this->bankLine($this->account, '2026-10-03', 24_000, 'Deposit less fee');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 124_000);

        $this->inLedger($this->account, function () use ($session, $row, $line) {
            try {
                $this->service()->matchRows($session, [$line->id], [$row->id], $this->user);
                $this->fail('Totals differ');
            } catch (ReconciliationRefused $e) {
                $this->assertSame('totals_differ', $e->reason);
                $this->assertStringContainsString('10.00', $e->getMessage());
            }
        });
    }

    public function test_unmatching_releases_the_match_keeps_it_in_history_and_unlocks_the_row(): void
    {
        $row = $this->ledgerRow($this->account, '2026-10-03', 25_000, 'Deposit');
        $line = $this->bankLine($this->account, '2026-10-03', 25_000, 'Deposit');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 125_000);

        $match = $this->inLedger($this->account, fn () => $this->service()->matchRows($session, [$line->id], [$row->id], $this->user));
        $this->inLedger($this->account, fn () => $this->service()->unmatch($match, $this->user, 'Wrong line'));

        $this->assertSame('released', $match->fresh()->state);
        $this->assertSame([$line->id], $match->fresh()->snapshot['line_ids']);
        $this->inLedger($this->account, function () use ($row, $line) {
            $this->assertNull(Transaction::findOrFail($row->id)->bank_match_id);
            $this->assertNull(BankStatementLine::findOrFail($line->id)->bank_match_id);
            Transaction::findOrFail($row->id)->update(['amount_cents' => 26_000]); // editable again
        });
        $this->assertSame(2, BankReconciliationEvent::query()->forContext($this->account->ledgerContext())->whereIn('event', ['matched', 'unmatched'])->count());
    }

    public function test_events_and_adjustments_are_append_only(): void
    {
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 100_000);
        $event = BankReconciliationEvent::query()->forContext($this->account->ledgerContext())->where('bank_reconciliation_id', $session->id)->firstOrFail();

        $this->expectException(\LogicException::class);
        $event->update(['event' => 'edited']);
    }

    public function test_the_first_reconciliation_takes_older_entries_as_agreed_and_the_opening_balance_check_says_so(): void
    {
        // history before the statement: 100,000 opening + 7,000 + 3,000 in September
        $old1 = $this->ledgerRow($this->account, '2026-09-10', 7_000, 'September sale');
        $old2 = $this->ledgerRow($this->account, '2026-09-20', 3_000, 'September sale 2');
        $this->ledgerRow($this->account, '2026-10-03', 25_000, 'Deposit');
        $this->bankLine($this->account, '2026-10-03', 25_000, 'Deposit');

        // the bank says it held 110,000 on 1 October
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 135_000, 110_000);
        $this->assertSame('2026-10-03', $session->baseline_date->toDateString());

        $this->inLedger($this->account, function () use ($session, $old1, $old2) {
            $balances = app(ReconciliationBalances::class);
            $account = Account::findOrFail($this->account->id);
            $this->assertSame(110_000, $balances->broughtForward($account, '2026-10-03', null), 'the books agree with the bank\'s opening balance');
            $this->assertSame(['Deposit'], array_map(fn ($o) => $o->description, $balances->outstanding($account, '2026-10-31', null, '2026-10-03')), 'older entries are not listed as outstanding');

            $matching = app(MatchingService::class);
            $matching->accept($session, $matching->suggest($session)['proposals'], $this->user);
            $done = $this->service()->complete($session, $this->user);

            $this->assertSame(135_000, $done->ledger_cleared_cents);
            $this->assertSame($session->id, Transaction::findOrFail($old1->id)->reconciliation_id, 'brought-forward rows are reconciled and locked with the session');

            try {
                Transaction::findOrFail($old2->id)->update(['amount_cents' => 1]);
                $this->fail('A brought-forward row is locked');
            } catch (ReconciledTransactionLocked) {
            }

            // the next session starts from what this one cleared: the old rows do not come back as outstanding
            $this->ledgerRow($this->account, '2026-11-05', -2_000, 'Rent');
            $this->bankLine($this->account, '2026-11-05', -2_000, 'Rent');
            $second = $this->service()->start($this->user, $this->account, '2026-11-09', 133_000);
            $this->assertNull($second->baseline_date);
            $this->assertSame(135_000, $second->statement_opening_cents);
            $matching->accept($second, $matching->suggest($second)['proposals'], $this->user);
            $this->assertSame('completed', $this->service()->complete($second, $this->user)->status);

            // reopening the first (after the second is reopened) releases the brought-forward rows too
            $this->service()->reopen($second, $this->user, 'Reopen the newest first');
        });
    }

    public function test_an_opening_balance_that_does_not_agree_shows_up_as_the_difference_not_as_a_guess(): void
    {
        $this->ledgerRow($this->account, '2026-09-10', 7_000, 'September sale');
        $this->ledgerRow($this->account, '2026-10-03', 25_000, 'Deposit');
        $this->bankLine($this->account, '2026-10-03', 25_000, 'Deposit');
        // the bank held 105,000 on 1 October but the books say 107,000
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 130_000, 105_000);

        $this->inLedger($this->account, function () use ($session) {
            $matching = app(MatchingService::class);
            $matching->accept($session, $matching->suggest($session)['proposals'], $this->user);

            try {
                $this->service()->complete($session, $this->user);
                $this->fail('The books are 2,000 away from the bank before the statement starts');
            } catch (ReconciliationRefused $e) {
                $this->assertSame('difference', $e->reason);
                $this->assertStringContainsString('20.00', $e->getMessage());
            }
        });
    }
}
