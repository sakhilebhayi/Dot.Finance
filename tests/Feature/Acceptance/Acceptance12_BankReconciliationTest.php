<?php

namespace Tests\Feature\Acceptance;

use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankStatement;
use App\Models\Bank\BankStatementLine;
use App\Models\Transaction;
use App\Services\Bank\DocumentSources;
use App\Services\Bank\MatchText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Owner criterion 12: bank reconciliation works. A statement file is imported through the form (no live bank feed exists), the engine
 * matches what it can, the rest are exceptions a person settles against an invoice or bill or books as bank charges, and the
 * reconciliation completes only when the difference is zero. Real routes, real services, a real month of postings.
 */
class Acceptance12_BankReconciliationTest extends AcceptanceCase
{
    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMonth(false);
    }

    private function upload(): void
    {
        $file = UploadedFile::fake()->createWithContent('november.csv', $this->statementCsv());
        $this->asOwner()->post(route('bank.import.upload'), ['account_id' => $this->bank->id, 'file' => $file])->assertRedirect();
        $this->token = basename(collect(Storage::disk('local')->allFiles('finance-imports/bank'))->filter(fn ($f) => str_ends_with($f, '.dat'))->last(), '.dat');
    }

    private function importStatement(): BankStatement
    {
        $this->upload();
        $this->asOwner()->get(route('bank.import.preview', $this->token))->assertOk()->assertSee('Review the statement');
        $this->asOwner()->post(route('bank.import.store', $this->token), ['date_format' => 'ymd', 'amount_locale' => 'en'])->assertSessionHasNoErrors();

        return BankStatement::query()->forContext($this->ledger)->firstOrFail();
    }

    private function line(string $description): BankStatementLine
    {
        return BankStatementLine::query()->forContext($this->ledger)->where('description', $description)->firstOrFail();
    }

    public function test_import_match_resolve_exceptions_and_complete_at_zero_difference(): void
    {
        $statement = $this->importStatement();
        $this->assertSame(7, $statement->line_count);
        $this->assertSame(11_929_000, (int) $statement->closing_balance_cents, 'the running balance in the file proves its closing balance');

        // start from the statement: its date and closing balance come from the file
        $this->asOwner()->post(route('bank.reconciliation.start'), ['account_id' => $this->bank->id, 'bank_statement_id' => $statement->id])->assertRedirect();
        $session = BankReconciliation::query()->forContext($this->ledger)->firstOrFail();
        $this->assertSame(11_929_000, (int) $session->statement_closing_cents);

        // the engine proposes the three it can see (receipt, supplier payment, claim payment); the rest are exceptions with reasons
        $page = $this->asOwner()->get(route('bank.reconciliation.show', $session))->assertOk()->assertSee('Matched for you')->getContent();
        $this->assertStringContainsString('MONTHLY ACCOUNT FEE', $page);
        $this->asOwner()->post(route('bank.reconciliation.accept', $session), ['mode' => 'auto'])->assertSessionHasNoErrors();

        // completing with exceptions open is refused: the difference is not zero
        $this->asOwner()->post(route('bank.reconciliation.complete', $session))->assertSessionHasErrors('bank');
        $this->assertSame('draft', $session->fresh()->status);

        // two lines settle a customer invoice / supplier bill straight from the line
        $sources = app(DocumentSources::class);
        foreach (['EFT CREDIT BETA STORES INV-00002' => 'in', 'EFT LUNCH CO' => 'out'] as $description => $direction) {
            $line = $this->line($description);
            $prepared = MatchText::prepare($line->description, $line->reference);
            $found = $sources->candidates($this->ledger, [['id' => $line->id, 'cents' => $line->amount_cents, 'currency' => 'ZAR', 'text' => $prepared['text'], 'docs' => $prepared['docs']]])[$line->id];
            $this->assertNotEmpty($found, "{$description} has an open document to settle");
            $this->asOwner()->post(route('bank.reconciliation.settle', $session), ['line_id' => $line->id, 'document_type' => $found[0]->type, 'document_id' => $found[0]->id])->assertSessionHasNoErrors();
        }
        $this->assertSame('paid', $this->bill2->fresh()->status);
        $this->assertSame('paid', $this->inv2->fresh()->status);

        // the bank's own fee and interest are booked as entries and matched
        $this->asOwner()->post(route('bank.reconciliation.entries', $session), ['mode' => 'bank_charge', 'line_ids' => [$this->line('MONTHLY ACCOUNT FEE')->id]])->assertSessionHasNoErrors();
        $this->asOwner()->post(route('bank.reconciliation.entries', $session), ['mode' => 'interest', 'line_ids' => [$this->line('CREDIT INTEREST')->id]])->assertSessionHasNoErrors();

        $this->asOwner()->get(route('bank.reconciliation.show', $session))->assertOk()->assertSee('Nothing is waiting for a decision');
        $this->asOwner()->post(route('bank.reconciliation.complete', $session))->assertRedirect(route('bank.reconciliation.show', $session));

        $done = $session->fresh();
        $this->assertSame('completed', $done->status);
        $this->assertSame(11_929_000, (int) $done->ledger_cleared_cents, 'the cleared book balance is the statement balance: difference zero');
        $this->assertSame(0, BankStatementLine::query()->forContext($this->ledger)->whereNull('reconciliation_id')->count(), 'every statement line is part of the completed reconciliation');
        $this->assertBooksVerify();     // includes the cash tie-out of the bank account

        // the report proves it, and a reconciled row can no longer be edited
        $this->asOwner()->get(route('bank.reconciliation.report', $session))->assertOk()->assertSee('119,290.00');
        $csv = $this->asOwner()->get(route('bank.reconciliation.csv', $session))->assertOk()->streamedContent();
        $this->assertStringContainsString('119290.00', $csv);
        $charge = Transaction::query()->forContext($this->ledger)->where('description', 'MONTHLY ACCOUNT FEE')->firstOrFail();
        $this->assertSame('Bank charges', $charge->category->name);
        $this->asOwner()->delete(route('transactions.destroy', $charge))->assertSessionHasErrors('transaction');
        $this->assertNotNull($charge->fresh());
    }

    public function test_the_same_statement_file_imported_twice_makes_no_second_statement_and_no_duplicate_lines(): void
    {
        $this->importStatement();
        $this->upload();
        $this->asOwner()->get(route('bank.import.preview', $this->token))->assertSee('already imported');
        $this->asOwner()->post(route('bank.import.store', $this->token), ['date_format' => 'ymd', 'amount_locale' => 'en']);

        $this->assertSame(1, BankStatement::query()->forContext($this->ledger)->count());
        $this->assertSame(7, BankStatementLine::query()->forContext($this->ledger)->count());
    }

    public function test_a_typed_closing_balance_that_contradicts_the_statement_is_flagged_before_anything_is_imported(): void
    {
        $this->upload();
        $this->asOwner()->get(route('bank.import.preview', [$this->token, 'closing' => '119290.00', 'date_format' => 'ymd']))->assertOk()->assertSee('Adds up');
        $this->asOwner()->get(route('bank.import.preview', [$this->token, 'closing' => '100.00', 'date_format' => 'ymd']))->assertOk()->assertSee('Does not add up');
        $this->assertSame(0, BankStatementLine::query()->forContext($this->ledger)->count(), 'a preview imports nothing');
    }

    public function test_a_reconciliation_can_be_completed_with_a_recorded_variance_only_when_a_reason_is_given_and_never_silently(): void
    {
        $statement = $this->importStatement();
        $this->asOwner()->post(route('bank.reconciliation.start'), ['account_id' => $this->bank->id, 'bank_statement_id' => $statement->id]);
        $session = BankReconciliation::query()->forContext($this->ledger)->firstOrFail();

        $this->asOwner()->post(route('bank.reconciliation.complete', $session), ['accept_variance' => '1'])->assertSessionHasErrors();
        $this->assertSame('draft', $session->fresh()->status, 'a variance cannot be accepted without a reason');
    }

    public function test_only_people_who_may_reconcile_can_do_it_and_another_ledger_cannot_see_the_statement(): void
    {
        $statement = $this->importStatement();
        $viewer = $this->memberAs('viewer');
        $this->asMember($viewer)->post(route('bank.reconciliation.start'), ['account_id' => $this->bank->id, 'bank_statement_id' => $statement->id])->assertForbidden();
        $this->asMember($viewer)->get(route('bank.statements.show', $statement))->assertOk();

        $other = $this->user();
        $theirs = $this->company($other, 'Elsewhere');
        $this->inCompany($other, $theirs)->get(route('bank.statements.show', $statement))->assertNotFound();
        $this->inCompany($other, $theirs)->get(route('bank.statements.index'))->assertOk()->assertDontSee('november.csv');
    }
}
