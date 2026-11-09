<?php

namespace Tests\Feature\Integration;

use App\Contracts\Bank\OpenDocument;
use App\Models\Bank\BankStatementLine;
use App\Models\Shared\Payment;
use App\Services\Bank\DocumentNumbers;
use App\Services\Bank\DocumentSources;
use App\Services\Bank\MatchText;
use App\Services\Purchases\PurchaseOpenDocuments;
use App\Services\Sales\SalesOpenDocuments;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\AllocationRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsBankFixtures;
use Tests\Concerns\RunsAMonth;
use Tests\TestCase;

/** What the bank workspace is offered by Sales and Purchases, and that settling goes through their payment services exactly once. */
class BankDocumentSourcesTest extends TestCase
{
    use BuildsBankFixtures;
    use RefreshDatabase;
    use RunsAMonth;

    /** @return array{id: int, cents: int, currency: string, text: string, docs: list<string>} */
    private function input(int $id, int $cents, string $narrative): array
    {
        $p = MatchText::prepare($narrative);

        return ['id' => $id, 'cents' => $cents, 'currency' => 'ZAR', 'text' => $p['text'], 'docs' => $p['docs']];
    }

    public function test_money_in_is_offered_the_invoices_it_may_be_paying_best_reason_first(): void
    {
        $this->runMonth(bank: false);
        $sources = app(DocumentSources::class);

        $offered = $sources->candidates($this->ledger, [
            $this->input(1, 2_070_000, 'EFT CREDIT BETA STORES INV-00002'),   // names the invoice
            $this->input(2, 900_000, 'EFT CREDIT 77123'),                      // exactly what INV-00001 still owes
            $this->input(3, 100_000, 'credit from acme retail'),               // names the customer, less than is owed
            $this->input(4, 55_555, 'EFT CREDIT 99'),                          // nothing to go on
            $this->input(5, -103_500, 'EFT LUNCH CO'),                         // money out is not offered invoices
        ]);

        $this->assertSame('INV-00002', $offered[1][0]->number);
        $this->assertSame(2_070_000, $offered[1][0]->openCents, '23,000.00 less the 2,300.00 credit note');
        $this->assertStringContainsString('the narrative mentions INV-00002', $offered[1][0]->why);
        $this->assertSame('in', $offered[1][0]->direction);

        $this->assertSame('INV-00001', $offered[2][0]->number);
        $this->assertStringContainsString('exactly what INV-00001 still owes', $offered[2][0]->why);

        $this->assertSame('INV-00001', $offered[3][0]->number);
        $this->assertStringContainsString('names Acme Retail', $offered[3][0]->why);

        $this->assertArrayNotHasKey(4, $offered);

        // money out: the bills, never the invoices
        $this->assertSame('BILL-2026-00002', $offered[5][0]->number);
        $this->assertSame('out', $offered[5][0]->direction);
        $this->assertSame('bill', $offered[5][0]->type);
    }

    public function test_a_bill_number_with_a_year_in_it_is_matched_whole_not_by_its_first_digits(): void
    {
        $this->assertTrue(DocumentNumbers::mentioned('eft paper co bill-2026-00003', 'BILL-2026-00003', MatchText::docs('eft paper co bill-2026-00003')));
        $this->assertFalse(DocumentNumbers::mentioned('eft paper co bill-2026-00003', 'BILL-2026-00002', MatchText::docs('eft paper co bill-2026-00003')), 'another bill of the same year is not mentioned');
        $this->assertTrue(DocumentNumbers::mentioned('inv 42 acme', 'INV-0042', MatchText::docs('inv 42 acme')), 'a one-run number still ignores zeros and punctuation');
        $this->assertFalse(DocumentNumbers::mentioned('anything', '', []));

        $this->runMonth(bank: false);
        $offered = app(DocumentSources::class)->candidates($this->ledger, [$this->input(9, -230_000, 'EFT PAPER CO BILL-2026-00003')]);
        $this->assertSame('BILL-2026-00003', $offered[9][0]->number);
        $this->assertStringContainsString('mentions BILL-2026-00003', $offered[9][0]->why);
        $this->assertCount(1, array_filter($offered[9], fn (OpenDocument $d) => str_contains($d->why, 'mentions')), 'only that bill is "mentioned"');
    }

    public function test_settling_twice_with_the_same_key_pays_once(): void
    {
        $this->runMonth(bank: false);
        $line = $this->bankLine($this->bank, '2026-11-18', 2_070_000, 'EFT CREDIT BETA STORES INV-00002');
        $document = app(SalesOpenDocuments::class)->candidates($this->ledger, [$this->input($line->id, 2_070_000, 'EFT CREDIT BETA STORES INV-00002')])[$line->id][0];
        $user = $this->owner;
        $key = 'bank-line-'.$line->id.'-test';

        $payments = DB::table('payments')->where('team_id', $this->company->id)->count();
        $first = LedgerContext::run($this->ledger, fn () => app(SalesOpenDocuments::class)->settle($this->ledger, $user, $line, $document, 2_070_000, $key));
        $second = LedgerContext::run($this->ledger, fn () => app(SalesOpenDocuments::class)->settle($this->ledger, $user, $line, $document, 2_070_000, $key));

        $this->assertSame($first->id, $second->id);
        $this->assertSame($payments + 1, DB::table('payments')->where('team_id', $this->company->id)->count());
        $this->assertSame(2_070_000, $first->signedCents());
        $this->assertSame($this->bank->id, $first->account_id);
        $this->assertSame('paid', $this->inv2->fresh()->status);
        $this->assertSame('payment:'.Payment::query()->forContext($this->ledger)->where('transaction_id', $first->id)->value('id'), $first->settlement_ref);
        $this->assertBooksVerify();
    }

    public function test_a_document_that_is_not_there_or_not_open_is_refused_and_nothing_is_written(): void
    {
        $this->runMonth(bank: false);
        $line = $this->bankLine($this->bank, '2026-11-18', -103_500, 'EFT LUNCH CO');
        $bogus = new OpenDocument('bill', 999_999, 'BILL-2026-99999', 'Nobody', 103_500, 'ZAR', null, 'out', 'test');
        $before = [DB::table('payments')->count(), DB::table('journal_entries')->count(), DB::table('transactions')->count()];

        try {
            LedgerContext::run($this->ledger, fn () => app(PurchaseOpenDocuments::class)->settle($this->ledger, $this->owner, $line, $bogus, 103_500, 'k-bogus'));
            $this->fail('no such bill');
        } catch (AllocationRejected $e) {
            $this->assertStringContainsString('no longer open', $e->getMessage());
        }

        $this->assertSame($before, [DB::table('payments')->count(), DB::table('journal_entries')->count(), DB::table('transactions')->count()]);
        $this->assertInstanceOf(BankStatementLine::class, $line);
    }
}
