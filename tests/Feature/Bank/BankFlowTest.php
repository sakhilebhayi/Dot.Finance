<?php

namespace Tests\Feature\Bank;

use App\Models\Account;
use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankStatement;
use App\Models\Bank\BankStatementLine;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsBankFixtures;
use Tests\TestCase;

/** The whole job through the real routes: import a file, start, accept, complete, report, reopen. */
class BankFlowTest extends TestCase
{
    use BuildsBankFixtures;
    use RefreshDatabase;

    private User $user;

    private Account $account;

    private LedgerContext $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->user = $this->user();
        $this->ctx = LedgerContext::personal($this->user);
        $this->account = Account::factory()->create(['user_id' => $this->user->id, 'name' => 'Cheque', 'opening_balance_cents' => 10_000_000, 'opening_balance_date' => '2026-01-01']);
    }

    public function test_import_a_statement_then_reconcile_it_to_zero_and_read_the_report(): void
    {
        $this->ledgerRow($this->account, '2026-10-03', 2_500_000, 'Acme Ltd payment INV-0042');
        $this->ledgerRow($this->account, '2026-10-06', -450_050, 'Checkers groceries');

        // 1. upload, preview, import
        $this->inPersonal($this->user)->post(route('bank.import.upload'), [
            'account_id' => $this->account->id,
            'file' => UploadedFile::fake()->createWithContent('october.ofx', file_get_contents(base_path('tests/Fixtures/bank/statement-sgml.ofx'))),
        ])->assertRedirect();
        $token = collect(Storage::disk('local')->allFiles('finance-imports/bank'))->first(fn ($f) => str_ends_with($f, '.dat'));
        $token = basename($token, '.dat');

        $this->get(route('bank.import.preview', $token))->assertOk()->assertSee('Review the statement')->assertSee('Will be imported')->assertSee('120430.50', false);
        $this->post(route('bank.import.store', $token), ['date_format' => 'ymd', 'amount_locale' => 'en', 'closing' => '120430.50'])->assertRedirect();

        $statement = BankStatement::query()->forContext($this->ctx)->firstOrFail();
        $this->assertSame(3, $statement->line_count);

        // 2. start from the statement (its date and closing balance come from the file)
        $this->post(route('bank.reconciliation.start'), ['account_id' => $this->account->id, 'bank_statement_id' => $statement->id])->assertRedirect();
        $session = BankReconciliation::query()->forContext($this->ctx)->firstOrFail();
        $this->assertSame('2026-10-31', $session->statement_date->toDateString());

        // 3. the fee line is an exception; the other two are matched for you
        $page = $this->get(route('bank.reconciliation.show', $session))->assertOk();
        $page->assertSee('Matched for you')->assertSee('Bank charges')->assertSee('MONTHLY ACCOUNT FEE')->assertSee('Matched because');
        // the reason for the difference is said beside the difference, at the same reading level, with the counts behind it
        $page->assertSee('bk-why', false)->assertSee('statement lines are still to match')->assertSee('Why the statement and your books are');

        $this->post(route('bank.reconciliation.accept', $session), ['mode' => 'auto'])->assertSessionHasNoErrors();

        // 4. completing now is refused: the fee is not in the books yet
        $this->post(route('bank.reconciliation.complete', $session))->assertSessionHasErrors('bank');
        $this->assertSame('draft', $session->fresh()->status);

        // 5. record the fee as a bank charge in one step, then complete
        $fee = BankStatementLine::query()->forContext($this->ctx)->where('amount_cents', -6_900)->firstOrFail();
        $this->post(route('bank.reconciliation.entries', $session), ['mode' => 'bank_charge', 'line_ids' => [$fee->id]])->assertSessionHasNoErrors();
        $this->post(route('bank.reconciliation.complete', $session))->assertRedirect(route('bank.reconciliation.show', $session));

        $done = $session->fresh();
        $this->assertSame('completed', $done->status);
        $this->assertSame(12_043_050, $done->ledger_cleared_cents);

        $charge = Transaction::query()->forContext($this->ctx)->where('description', 'MONTHLY ACCOUNT FEE')->firstOrFail();
        $this->assertSame('expense', $charge->type);
        $this->assertSame('Bank charges', $charge->category->name);
        $this->assertNotNull($charge->reconciliation_id);

        // 6. the report proves it two ways
        $this->get(route('bank.reconciliation.report', $session))->assertOk()
            ->assertSee('Closing balance on the bank statement')->assertSee('120,430.50')->assertSee('Checked a second way');
        $csv = $this->get(route('bank.reconciliation.csv', $session))->assertOk();
        $this->assertStringContainsString('120430.50', $csv->streamedContent());

        // 7. a reconciled row cannot be edited from the transactions screen
        $row = Transaction::query()->forContext($this->ctx)->where('description', 'Checkers groceries')->firstOrFail();
        $this->put(route('transactions.update', $row), ['account_id' => $this->account->id, 'description' => 'Checkers', 'amount' => '1.00', 'type' => 'expense', 'date' => '2026-10-06'])
            ->assertSessionHasErrors('transaction');
        $this->delete(route('transactions.destroy', $row))->assertSessionHasErrors('transaction');
        $this->assertSame(450_050, $row->fresh()->amount_cents);

        // 8. reopen it with a reason; the rows stay protected until un-matched
        $this->post(route('bank.reconciliation.reopen', $session), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('bank.reconciliation.reopen', $session), ['reason' => 'Statement closing balance was mistyped'])->assertSessionHasNoErrors();
        $this->assertSame('draft', $session->fresh()->status);
        $this->assertSame(1, $session->fresh()->reopened_count);
        $this->get(route('bank.reconciliation.show', $session))->assertOk()->assertSee('Reopened');
    }

    public function test_the_same_file_uploaded_twice_is_recognised_in_the_preview(): void
    {
        $this->inPersonal($this->user);
        foreach ([1, 2] as $round) {
            $this->post(route('bank.import.upload'), ['account_id' => $this->account->id, 'file' => UploadedFile::fake()->createWithContent('o.ofx', file_get_contents(base_path('tests/Fixtures/bank/statement-sgml.ofx')))])->assertRedirect();
            $token = basename(collect(Storage::disk('local')->allFiles('finance-imports/bank'))->filter(fn ($f) => str_ends_with($f, '.dat'))->last(), '.dat');

            if ($round === 2) {
                $this->get(route('bank.import.preview', $token))->assertSee('already imported');
            }
            $this->post(route('bank.import.store', $token), ['date_format' => 'ymd', 'amount_locale' => 'en']);
        }

        $this->assertSame(1, BankStatement::query()->forContext($this->ctx)->count());
        $this->assertSame(3, BankStatementLine::query()->forContext($this->ctx)->count());
    }

    public function test_the_workspace_and_every_bank_page_render_with_nothing_in_them(): void
    {
        $this->inPersonal($this->user);

        foreach (['bank.statements.index', 'bank.reconciliation.index', 'bank.rules.index', 'bank.import.create'] as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    public function test_the_state_line_counts_open_statement_lines_for_the_ledger_only(): void
    {
        $this->bankLine($this->account, '2026-10-03', 100, 'Open line');
        $this->inPersonal($this->user)->get(route('dashboard'))->assertOk()->assertSee('1 bank statement line needs a decision')->assertSee('Bank accounts against their statements');
    }

    public function test_no_bank_page_carries_an_inline_style_handler_or_un_nonced_script(): void
    {
        $this->ledgerRow($this->account, '2026-10-03', 25_000, 'Deposit INV-0042');
        $this->bankLine($this->account, '2026-10-03', 25_000, 'EFT INV-0042');
        $this->bankLine($this->account, '2026-10-04', -3_300, 'MONTHLY ACCOUNT FEE');
        $this->bankLine($this->account, '2026-10-05', 99, 'MYSTERY CREDIT');
        $session = $this->openSession($this->user, $this->account, '2026-10-31', 1);
        $statement = BankStatement::query()->forContext($this->ctx)->firstOrFail();
        $this->inPersonal($this->user);

        $urls = [
            route('bank.statements.index'), route('bank.statements.show', $statement), route('bank.import.create'), route('bank.reconciliation.index'),
            route('bank.reconciliation.show', $session), route('bank.reconciliation.report', $session), route('bank.rules.index'),
        ];

        foreach ($urls as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString(' style=', $html, "{$url} has an inline style");
            $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|load)=/i', $html, "{$url} has an inline handler");
            $this->assertSame(0, preg_match_all('/<script(?![^>]*\bnonce=)(?![^>]*\bsrc=)[^>]*>/i', $html), "{$url} has an un-nonced inline script");
            $this->assertDoesNotMatchRegularExpression('#https?://(?!127\.0\.0\.1|localhost|finance\.infodot)#', preg_replace('#<(a|link)[^>]+href="[^"]*"#', '', $html) ?? '', "{$url} loads something from another host");
        }
    }
}
