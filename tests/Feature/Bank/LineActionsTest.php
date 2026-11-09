<?php

namespace Tests\Feature\Bank;

use App\Exceptions\Bank\ReconciliationRefused;
use App\Models\Account;
use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankRule;
use App\Models\Bank\BankStatement;
use App\Models\Bank\BankStatementLine;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\GlSync;
use App\Services\Accounting\GlVerifier;
use App\Services\Accounting\Periods;
use App\Services\Bank\DocumentSources;
use App\Services\Bank\ExceptionClassifier;
use App\Services\Bank\LineActions;
use App\Services\Bank\MatchingService;
use App\Services\Bank\ReconciliationService;
use App\Services\Controls\ApprovalPolicies;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsBankFixtures;
use Tests\Support\StubInvoiceSource;
use Tests\TestCase;

/** The exceptions inbox and its one-click answers, with accounting ON so every created row has to reach the books and tie out. */
class LineActionsTest extends TestCase
{
    use BuildsBankFixtures;
    use RefreshDatabase;

    private User $user;

    private LedgerContext $ctx;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->user();
        $this->ctx = LedgerContext::personal($this->user);
        $this->account = Account::factory()->create(['user_id' => $this->user->id, 'name' => 'Cheque', 'opening_balance_cents' => 10_000_000, 'opening_balance_date' => '2026-01-01']);
        $this->enableAccounting($this->ctx);
    }

    /** An account opened after accounting was switched on, journalled as AccountController does. */
    private function newAccount(string $name): Account
    {
        $account = Account::factory()->create(['user_id' => $this->user->id, 'name' => $name, 'opening_balance_cents' => 5_000_000, 'opening_balance_date' => '2026-01-01']);
        LedgerContext::run($this->ctx, fn () => app(GlSync::class)->account($account));

        return $account;
    }

    private function actions(): LineActions
    {
        return app(LineActions::class);
    }

    /** @return array<string, array<string, mixed>> groups by key */
    private function inbox(BankReconciliation $session): array
    {
        return LedgerContext::run($this->ctx, function () use ($session) {
            $suggest = app(MatchingService::class)->suggest($session);
            $proposed = collect($suggest['proposals'])->pluck('line_ids')->flatten()->flip();
            $lines = collect($suggest['lines'])->reject(fn ($l, $id) => $proposed->has($id))->values();

            return collect(app(ExceptionClassifier::class)->classify($session, $lines, collect($suggest['entries'])))->keyBy('key')->all();
        });
    }

    private function assertBooksTieOut(): void
    {
        $report = LedgerContext::run($this->ctx, fn () => app(GlVerifier::class)->verify($this->ctx));
        $failed = collect($report['checks'])->where('ok', false)->map(fn ($c) => $c['code'].': '.$c['message'].' '.json_encode($c['details']))->values()->all();
        $this->assertSame([], $failed, 'the books must still tie out');
    }

    public function test_creating_an_entry_from_a_line_reaches_the_books_and_matches_in_one_step(): void
    {
        $groceries = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries', 'type' => 'expense']);
        $line = $this->bankLine($this->account, '2026-10-12', -123_456, 'WOOLWORTHS FOOD 4421');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 10_000_000 - 123_456);

        $this->assertArrayHasKey('money_out', $this->inbox($session));

        $row = LedgerContext::run($this->ctx, fn () => $this->actions()->createEntry($session, $this->user, $line, $groceries->id));

        $this->assertSame('expense', $row->type);
        $this->assertSame(123_456, $row->amount_cents);
        $this->assertNotNull(Transaction::query()->forContext($this->ctx)->findOrFail($row->id)->bank_match_id);
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'transaction', 'source_id' => (string) $row->id, 'total_cents' => 123_456]);
        $this->assertBooksTieOut();

        LedgerContext::run($this->ctx, fn () => app(ReconciliationService::class)->complete($session, $this->user));
        $this->assertSame('completed', $session->fresh()->status);
    }

    public function test_the_kind_of_category_must_fit_the_line(): void
    {
        $income = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Sales', 'type' => 'income']);
        $line = $this->bankLine($this->account, '2026-10-12', -5_000, 'Something');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 10_000_000);

        $this->expectException(ReconciliationRefused::class);
        $this->expectExceptionMessage('income category');
        LedgerContext::run($this->ctx, fn () => $this->actions()->createEntry($session, $this->user, $line, $income->id));
    }

    public function test_bank_charges_and_interest_are_recorded_in_bulk_with_their_own_categories(): void
    {
        $fee = $this->bankLine($this->account, '2026-10-31', -6_900, 'MONTHLY ACCOUNT FEE');
        $sms = $this->bankLine($this->account, '2026-10-31', -250, 'SMS NOTIFICATION FEE');
        $int = $this->bankLine($this->account, '2026-10-31', 1_840, 'CREDIT INTEREST');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 10_000_000 - 6_900 - 250 + 1_840);

        $groups = $this->inbox($session);
        $this->assertCount(2, $groups['bank_charge']['lines']);
        $this->assertCount(1, $groups['interest']['lines']);

        $one = LedgerContext::run($this->ctx, fn () => $this->actions()->createEntries($session, $this->user, [$fee->id, $sms->id], 'bank_charge'));
        $two = LedgerContext::run($this->ctx, fn () => $this->actions()->createEntries($session, $this->user, [$int->id], 'interest'));

        $this->assertSame(2, $one['created']);
        $this->assertSame(1, $two['created']);
        $this->assertSame(1, Category::query()->forContext($this->ctx)->where('name', 'Bank charges')->count());
        $this->assertSame('income', Transaction::query()->forContext($this->ctx)->where('description', 'CREDIT INTEREST')->firstOrFail()->type);
        $this->assertBooksTieOut();
        $this->assertDatabaseCount('bank_adjustments', 3);

        LedgerContext::run($this->ctx, fn () => app(ReconciliationService::class)->complete($session, $this->user));
    }

    public function test_a_bank_charge_must_be_money_out(): void
    {
        $line = $this->bankLine($this->account, '2026-10-31', 500, 'FEE REVERSAL');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 10_000_500);

        $result = LedgerContext::run($this->ctx, fn () => $this->actions()->createEntries($session, $this->user, [$line->id], 'bank_charge'));
        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString('money going out', $result['failed'][0]['message']);
    }

    public function test_a_rule_only_suggests_until_it_is_confirmed(): void
    {
        $groceries = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries', 'type' => 'expense']);
        $rule = BankRule::create($this->ctx->ownerAttributes() + ['name' => 'Woolworths', 'pattern' => 'woolworths', 'category_id' => $groceries->id, 'direction' => 'out']);
        $line = $this->bankLine($this->account, '2026-10-12', -9_900, 'POS WOOLWORTHS 0912');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 10_000_000 - 9_900);

        $groups = $this->inbox($session);
        $this->assertArrayHasKey('rule', $groups);
        $this->assertStringContainsString('categorise as Groceries', $groups['rule']['lines'][0]['hint']);
        $this->assertSame(0, Transaction::query()->forContext($this->ctx)->where('description', 'like', '%WOOLWORTHS%')->count(), 'nothing is created by suggesting');

        $result = LedgerContext::run($this->ctx, fn () => $this->actions()->createEntries($session, $this->user, [$line->id], 'rule'));
        $this->assertSame(1, $result['created']);

        $row = Transaction::query()->forContext($this->ctx)->where('description', 'like', '%WOOLWORTHS%')->firstOrFail();
        $this->assertSame($groceries->id, $row->category_id);
        $this->assertSame(1, $rule->fresh()->times_applied);
        $this->assertDatabaseHas('bank_matches', ['method' => 'rule', 'reason' => 'Created from this statement line because if the description or reference contains "woolworths" then categorise it as Groceries.']);
    }

    public function test_a_transfer_between_own_accounts_matches_both_sides_and_ties_out(): void
    {
        $savings = $this->newAccount('Savings');
        $out = $this->bankLine($this->account, '2026-10-10', -200_000, 'IB TRANSFER TO SAVINGS');
        $in = $this->bankLine($savings, '2026-10-10', 200_000, 'IB TRANSFER FROM CHEQUE');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 10_000_000 - 200_000);
        $other = $this->openSession($this->user, $savings, '2026-10-31', 5_000_000 + 200_000);

        $groups = $this->inbox($session);
        $this->assertSame($in->id, $groups['transfer']['lines'][0]['partner']['line_id']);

        $result = LedgerContext::run($this->ctx, fn () => $this->actions()->createTransfer($session, $this->user, $out, $savings->id, $in->id));
        $this->assertTrue($result['other_matched']);
        $this->assertBooksTieOut();

        LedgerContext::run($this->ctx, function () use ($session, $other) {
            app(ReconciliationService::class)->complete($session, $this->user);
            app(ReconciliationService::class)->complete($other, $this->user);
        });
        $this->assertSame(2, BankReconciliation::query()->forContext($this->ctx)->where('status', 'completed')->count());
    }

    public function test_a_transfer_leaves_the_other_side_to_match_automatically_later(): void
    {
        $savings = $this->newAccount('Savings');
        $out = $this->bankLine($this->account, '2026-10-10', -200_000, 'TRANSFER');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 10_000_000 - 200_000);

        $result = LedgerContext::run($this->ctx, fn () => $this->actions()->createTransfer($session, $this->user, $out, $savings->id));
        $this->assertFalse($result['other_matched']);

        $in = $this->bankLine($savings, '2026-10-11', 200_000, 'RECEIVED');
        $savingsSession = $this->openSession($this->user, $savings, '2026-10-31', 5_200_000);
        $proposals = LedgerContext::run($this->ctx, fn () => app(MatchingService::class)->suggest($savingsSession)['proposals']);
        $this->assertCount(1, $proposals);
        $this->assertSame([$in->id], $proposals[0]['line_ids']);
        $this->assertStringContainsString('transfer leg', $proposals[0]['reason']);
    }

    public function test_a_transfer_between_currencies_is_sent_to_the_transfer_form(): void
    {
        $usd = Account::factory()->currency('USD')->create(['user_id' => $this->user->id]);
        $line = $this->bankLine($this->account, '2026-10-10', -200_000, 'TO USD');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 9_800_000);

        $this->expectException(ReconciliationRefused::class);
        $this->expectExceptionMessage('different currencies');
        LedgerContext::run($this->ctx, fn () => $this->actions()->createTransfer($session, $this->user, $line, $usd->id));
    }

    public function test_an_entry_that_is_close_in_amount_is_matched_with_the_difference_recorded(): void
    {
        $this->ledgerRow($this->account, '2026-10-14', 100_000, 'Payment from Acme INV-0055');
        $line = $this->bankLine($this->account, '2026-10-14', 99_000, 'EFT ACME INV-0055 LESS FEE');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 10_000_000 + 99_000);

        $groups = $this->inbox($session);
        $this->assertArrayHasKey('close_amount', $groups);
        $entryId = $groups['close_amount']['lines'][0]['candidate']['id'];

        LedgerContext::run($this->ctx, fn () => $this->actions()->matchWithDifference($session, $this->user, $line, $entryId, 'Bank deducted a R10 fee'));

        $diff = Transaction::query()->forContext($this->ctx)->where('description', 'like', 'Difference on%')->firstOrFail();
        $this->assertSame('expense', $diff->type);
        $this->assertSame(1_000, $diff->amount_cents);
        $this->assertBooksTieOut();
        LedgerContext::run($this->ctx, fn () => app(ReconciliationService::class)->complete($session, $this->user));
    }

    public function test_the_same_amount_on_a_far_date_is_offered_as_a_timing_match(): void
    {
        $row = $this->ledgerRow($this->account, '2026-09-20', -77_700, 'Insurance premium');
        $line = $this->bankLine($this->account, '2026-10-12', -77_700, 'DEBIT ORDER INSURER');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 10_000_000 - 77_700);

        $groups = $this->inbox($session);
        $this->assertSame($row->id, $groups['timing']['lines'][0]['candidate']['id']);
        $this->assertStringContainsString('days from the bank date', $groups['timing']['lines'][0]['hint']);

        LedgerContext::run($this->ctx, fn () => app(ReconciliationService::class)->matchRows($session, [$line->id], [$row->id], $this->user));
        $this->assertNotNull($row->fresh()->bank_match_id);
    }

    public function test_a_line_that_is_already_held_from_another_statement_is_flagged_as_a_possible_duplicate(): void
    {
        $this->bankLine($this->account, '2026-10-12', -5_500, 'CHECKERS 77');
        $other = BankStatement::create($this->account->ledgerOwner() + ['account_id' => $this->account->id, 'source' => 'csv', 'original_name' => 'again.csv', 'file_sha256' => hash('sha256', 'again'), 'currency' => 'ZAR', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        BankStatementLine::create($this->account->ledgerOwner() + ['bank_statement_id' => $other->id, 'account_id' => $this->account->id, 'line_no' => 1, 'posted_on' => '2026-10-12', 'amount_cents' => -5_500, 'currency' => 'ZAR', 'description' => 'Checkers 77', 'fingerprint' => hash('sha256', 'x')]);
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 10_000_000);

        $this->assertCount(2, $this->inbox($session)['possible_duplicate']['lines']);
    }

    public function test_an_approval_policy_stops_an_expense_being_created_from_the_reconciliation(): void
    {
        app(ApprovalPolicies::class)->create($this->user, $this->ctx, ['subject_type' => 'expense', 'name' => 'Expenses over R1,000', 'threshold_cents' => 100_000, 'currency' => 'ZAR']);
        $line = $this->bankLine($this->account, '2026-10-12', -250_000, 'BIG PURCHASE');
        $small = $this->bankLine($this->account, '2026-10-12', -50_000, 'SMALL PURCHASE');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 10_000_000 - 300_000);

        try {
            LedgerContext::run($this->ctx, fn () => $this->actions()->createEntry($session, $this->user, $line, null));
            $this->fail('A policy covers this amount');
        } catch (ReconciliationRefused $e) {
            $this->assertSame('needs_approval', $e->reason);
        }
        $this->assertSame(0, Transaction::query()->forContext($this->ctx)->where('description', 'BIG PURCHASE')->count());
        $this->assertNull($line->fresh()->bank_match_id);

        // below the threshold nothing is needed
        LedgerContext::run($this->ctx, fn () => $this->actions()->createEntry($session, $this->user, $small, null));
        $this->assertNotNull($small->fresh()->bank_match_id);
    }

    public function test_a_closed_period_refuses_the_entry_and_nothing_is_written(): void
    {
        $line = $this->bankLine($this->account, '2026-10-12', -5_000, 'LATE ENTRY');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 10_000_000 - 5_000);
        LedgerContext::run($this->ctx, fn () => app(Periods::class)->closeThrough($this->ctx, CarbonImmutable::parse('2026-10-31'), $this->user));

        try {
            LedgerContext::run($this->ctx, fn () => $this->actions()->createEntry($session, $this->user, $line, null));
            $this->fail('The period is closed');
        } catch (ReconciliationRefused $e) {
            $this->assertSame('locked', $e->reason);
        }

        $this->assertSame(0, Transaction::query()->forContext($this->ctx)->where('description', 'LATE ENTRY')->count());
        $this->assertNull($line->fresh()->bank_match_id);
    }

    public function test_an_entry_dated_before_the_opening_balance_is_refused_because_it_would_not_count(): void
    {
        $line = $this->bankLine($this->account, '2025-12-30', -5_000, 'BEFORE OPENING');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 10_000_000);

        $this->expectException(ReconciliationRefused::class);
        $this->expectExceptionMessage('opening balance date');
        LedgerContext::run($this->ctx, fn () => $this->actions()->createEntry($session, $this->user, $line, null));
    }

    public function test_an_invoice_can_be_settled_from_a_line_through_the_module_that_owns_it(): void
    {
        $this->app->bind(StubInvoiceSource::class);
        $this->app->tag([StubInvoiceSource::class], 'bank.document-sources');
        $this->app->forgetInstance(DocumentSources::class);
        $this->app->singleton(DocumentSources::class, fn ($app) => new DocumentSources($app->tagged('bank.document-sources')));
        StubInvoiceSource::$settled = 0;

        $line = $this->bankLine($this->account, '2026-10-03', 2_500_000, 'EFT CREDIT ACME INV-0042');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 12_500_000);

        $groups = $this->inbox($session);
        $this->assertArrayHasKey('documents', $groups);
        $document = $groups['documents']['lines'][0]['documents'][0];

        $row = LedgerContext::run($this->ctx, fn () => $this->actions()->settleDocument($session, $this->user, $line, $document));

        $this->assertSame(1, StubInvoiceSource::$settled);
        $this->assertSame($row->id, Transaction::query()->forContext($this->ctx)->where('bank_match_id', $line->fresh()->bank_match_id)->value('id'));
        $this->assertBooksTieOut();
    }

    public function test_settling_is_not_offered_when_nothing_has_registered_a_document_source(): void
    {
        $this->bankLine($this->account, '2026-10-03', 2_500_000, 'EFT CREDIT ACME INV-0042');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 12_500_000);

        $this->assertArrayNotHasKey('documents', $this->inbox($session));
        $this->assertFalse(app(DocumentSources::class)->available());
    }
}
