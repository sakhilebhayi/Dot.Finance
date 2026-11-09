<?php

namespace Tests\Feature\Acceptance;

use App\Models\Account;
use App\Models\Accounting\JournalEntry;
use App\Models\Bank\BankStatement;
use App\Models\Bank\BankStatementLine;
use App\Models\Category;
use App\Models\Purchases\Bill;
use App\Models\RecurringRule;
use App\Models\Sales\SalesDocument;
use App\Models\Shared\Payment;
use App\Models\Shared\PaymentAllocation;
use App\Models\Transaction;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Owner criterion 28: duplicate transactions and postings are prevented. The same request twice (a double click, a retry, a replay) is
 * the same payment, the same draft, the same posting; the same bank statement or CSV file twice adds nothing; overlapping statements
 * add only the new lines; a supplier invoice that looks like one already in the books has to be confirmed in writing. The
 * PostgreSQL-only race tests (SalesConcurrencyTest, PurchasesConcurrencyTest, BankConcurrencyTest, NumberingConcurrencyTest) cover true
 * parallelism; here the retries are serial and exact.
 */
class Acceptance28_DuplicatesTest extends AcceptanceCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpIntegration();
    }

    private function entryCount(): int
    {
        return JournalEntry::query()->forContext($this->ledger)->count();
    }

    public function test_the_same_receipt_sent_twice_with_the_same_key_is_one_payment_one_posting_one_cash_row(): void
    {
        $invoice = $this->issueInvoice($this->acme, [['description' => 'Job', 'quantity' => '1', 'unit_cents' => 200_000, 'tax_code' => 'ZA_VAT_STD']], '2026-11-05');
        $body = ['party_id' => $this->acme->id, 'cash_account_id' => $this->bank->id, 'amount' => '1000.00', 'paid_on' => '2026-11-10', 'idempotency_key' => 'double-click-1', 'allocations' => [$invoice->id => '1000.00']];

        $this->asOwner()->post(route('sales.payments.store'), $body)->assertSessionHasNoErrors();
        $entries = $this->entryCount();
        $this->asOwner()->post(route('sales.payments.store'), $body)->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->asOwner()->post(route('sales.payments.store'), $body);

        $this->assertSame(1, Payment::query()->forContext($this->ledger)->where('idempotency_key', 'double-click-1')->count());
        $this->assertSame($entries, $this->entryCount(), 'no second posting');
        $this->assertSame(1, Transaction::query()->forContext($this->ledger)->where('settlement_ref', 'like', 'payment:%')->count(), 'one cash row');
        $this->assertSame(100_000, (int) PaymentAllocation::query()->forContext($this->ledger)->sum('amount_cents'), 'allocated once');
        $this->assertSame(230_000 - 100_000, $this->balance('ar') - 0);
        $this->assertBooksVerify();
    }

    public function test_the_same_key_for_a_different_payment_is_refused_not_merged(): void
    {
        $invoice = $this->issueInvoice($this->acme, [['description' => 'Job', 'quantity' => '1', 'unit_cents' => 200_000, 'tax_code' => 'ZA_VAT_STD']], '2026-11-05');
        $body = ['party_id' => $this->acme->id, 'cash_account_id' => $this->bank->id, 'amount' => '1000.00', 'paid_on' => '2026-11-10', 'idempotency_key' => 'k-conflict', 'allocations' => [$invoice->id => '1000.00']];
        $this->asOwner()->post(route('sales.payments.store'), $body)->assertSessionHasNoErrors();
        $this->asOwner()->post(route('sales.payments.store'), ['amount' => '1500.00', 'allocations' => [$invoice->id => '1500.00']] + $body)->assertSessionHasErrors('sales');
        $this->assertSame(1, Payment::query()->forContext($this->ledger)->count());
        $this->assertSame(100_000, (int) Payment::query()->forContext($this->ledger)->value('amount_cents'));
    }

    public function test_the_same_supplier_payment_twice_pays_the_bill_once(): void
    {
        $bill = $this->postBill($this->paperCo, '2026-11-04', [$this->billLine()]);
        $body = ['party_id' => $this->paperCo->id, 'cash_account_id' => $this->bank->id, 'paid_on' => '2026-11-12', 'idempotency_key' => 'sup-dc', 'pay' => ['bill-'.$bill->id => '4600.00']];
        $this->asOwner()->post(route('purchases.payments.store'), $body)->assertRedirect();
        $this->asOwner()->post(route('purchases.payments.store'), $body)->assertRedirect();
        $this->assertSame(1, Payment::query()->forContext($this->ledger)->where('direction', 'out')->count());
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(0, $this->balance('ap'));
        $this->assertBooksVerify();
    }

    public function test_a_draft_form_submitted_twice_is_one_draft_and_issuing_twice_is_one_posting(): void
    {
        $body = ['kind' => 'tax_invoice', 'party_id' => $this->acme->id, 'currency' => 'ZAR', 'issue_date' => '2026-11-05', 'client_token' => 'form-1', 'lines' => [['description' => 'Job', 'quantity' => '1', 'unit_price' => '100.00', 'tax_code' => 'ZA_VAT_STD']]];
        $this->asOwner()->post(route('sales.documents.store'), $body)->assertSessionHasNoErrors();
        $this->asOwner()->post(route('sales.documents.store'), $body)->assertSessionHasNoErrors();
        $this->assertSame(1, SalesDocument::query()->forContext($this->ledger)->count());

        $doc = SalesDocument::query()->forContext($this->ledger)->firstOrFail();
        $this->asOwner()->post(route('sales.documents.issue', $doc));
        $this->asOwner()->post(route('sales.documents.issue', $doc));
        $this->assertSame(1, JournalEntry::query()->forContext($this->ledger)->where('source_type', 'invoice')->count());

        $bill = ['party_id' => $this->paperCo->id, 'bill_date' => '2026-11-05', 'supplier_reference' => 'DC-1', 'document_type' => 'tax_invoice', 'client_token' => 'bill-form-1', 'submit_now' => '1',
            'lines' => [['description' => 'x', 'gl_account_id' => $this->gl('6190')->id, 'quantity' => '1', 'unit_price' => '10.00', 'tax_code' => 'ZA_VAT_STD']]];
        $this->asOwner()->post(route('purchases.bills.store'), $bill);
        $this->asOwner()->post(route('purchases.bills.store'), $bill);
        $this->assertSame(1, Bill::query()->forContext($this->ledger)->where('supplier_reference', 'DC-1')->count());
        $this->assertSame(1, JournalEntry::query()->forContext($this->ledger)->where('source_type', 'bill')->count());
    }

    public function test_the_poster_is_idempotent_on_the_source_and_a_new_version_replaces_rather_than_adds(): void
    {
        $lines = fn (int $cents) => [new PostingLine($this->gl('6190')->id, debitCents: $cents), new PostingLine($this->gl('4000')->id, creditCents: $cents)];
        $request = fn (int $version, int $cents) => new PostingRequest(CarbonImmutable::parse('2026-11-05'), 'manual', 'same-source', $version, 'Idempotent', $lines($cents));
        $poster = app(JournalPoster::class);

        $first = LedgerContext::run($this->ledger, fn () => $poster->post($request(1, 1_000)));
        $again = LedgerContext::run($this->ledger, fn () => $poster->post($request(1, 1_000)));
        $this->assertSame($first->id, $again->id, 'the same source and version is the same entry');
        $this->assertSame(1, JournalEntry::query()->forContext($this->ledger)->where('source_id', 'same-source')->count());

        LedgerContext::run($this->ledger, fn () => $poster->repost('manual', 'same-source', $request(2, 2_500)));
        $live = JournalEntry::query()->forContext($this->ledger)->where('source_id', 'same-source')->where('status', 'posted')->get();
        $this->assertCount(1, $live->where('source_type', 'manual'), 'one live entry for the source');
        $this->assertSame(2_500, (int) $live->first()->total_cents);
    }

    public function test_the_same_bank_statement_twice_and_an_overlapping_one_add_only_new_lines(): void
    {
        $csv = "Date,Description,Amount,Balance\n2026-11-03,EFT IN ONE,100.00,100100.00\n2026-11-04,EFT IN TWO,200.00,100300.00\n";
        $import = function (string $content, string $name) {
            $this->asOwner()->post(route('bank.import.upload'), ['account_id' => $this->bank->id, 'file' => UploadedFile::fake()->createWithContent($name, $content)])->assertRedirect();
            $token = basename(collect(Storage::disk('local')->allFiles('finance-imports/bank'))->filter(fn ($f) => str_ends_with($f, '.dat'))->last(), '.dat');

            return $this->asOwner()->post(route('bank.import.store', $token), ['date_format' => 'ymd', 'amount_locale' => 'en']);
        };

        $import($csv, 'one.csv')->assertSessionHasNoErrors();
        $import($csv, 'one-again.csv');
        $this->assertSame(1, BankStatement::query()->forContext($this->ledger)->count(), 'the same file is not a second statement');
        $this->assertSame(2, BankStatementLine::query()->forContext($this->ledger)->count());

        // a later statement that repeats one line and adds one
        $overlap = "Date,Description,Amount,Balance\n2026-11-04,EFT IN TWO,200.00,100300.00\n2026-11-05,EFT IN THREE,300.00,100600.00\n";
        $import($overlap, 'overlap.csv');
        $this->assertSame(3, BankStatementLine::query()->forContext($this->ledger)->count(), 'only the new line was added');
        $this->assertSame(1, BankStatementLine::query()->forContext($this->ledger)->where('description', 'EFT IN TWO')->count());
    }

    /** Defect D-1604: the Add a transaction form had no one-time token, so a double click recorded the entry twice. */
    public function test_a_double_click_on_the_add_transaction_form_records_one_transaction(): void
    {
        $page = $this->asOwner()->get(route('transactions.create'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input type="hidden" name="client_token" value="[0-9a-f-]{36}">/', $page, 'the form carries a one-time token');
        $other = $this->asOwner()->get(route('transactions.create'))->getContent();
        preg_match('/name="client_token" value="([^"]+)"/', $page, $a);
        preg_match('/name="client_token" value="([^"]+)"/', $other, $b);
        $this->assertNotSame($a[1], $b[1], 'a new token for every form');

        $body = ['account_id' => $this->bank->id, 'description' => 'Double click', 'amount' => '75.00', 'type' => 'expense', 'date' => '2026-11-12', 'client_token' => $a[1]];
        $this->asOwner()->post(route('transactions.store'), $body)->assertSessionHasNoErrors();
        $entries = $this->entryCount();
        $this->asOwner()->post(route('transactions.store'), $body)->assertRedirect(route('transactions.index'))->assertSessionHas('status');
        $this->asOwner()->post(route('transactions.store'), $body);

        $this->assertSame(1, Transaction::query()->forContext($this->ledger)->where('description', 'Double click')->count());
        $this->assertSame($entries, $this->entryCount(), 'and one posting');

        // a second, deliberate transaction for the same amount on the same day is a different form and is recorded
        $this->asOwner()->post(route('transactions.store'), ['client_token' => $b[1]] + $body);
        $this->assertSame(2, Transaction::query()->forContext($this->ledger)->where('description', 'Double click')->count());
        // callers that send no token (the API, scripts) still work
        $this->asOwner()->post(route('transactions.store'), array_diff_key($body, ['client_token' => 1]))->assertSessionHasNoErrors();
        $this->assertSame(3, Transaction::query()->forContext($this->ledger)->where('description', 'Double click')->count());

        // another ledger cannot reuse a token it was not given: it is told to reload, nothing is recorded, nothing is suppressed in A
        $other = $this->user();
        $company = $this->company($other, 'Elsewhere');
        $theirs = Account::factory()->forCompany($company)->create(['type' => 'checking']);
        $this->inCompany($other, $company)->post(route('transactions.store'), ['account_id' => $theirs->id, 'description' => 'Theirs', 'amount' => '1.00', 'type' => 'expense', 'date' => '2026-11-12', 'client_token' => $a[1]])->assertSessionHasErrors('client_token');
        $this->assertSame(0, Transaction::query()->forContext(LedgerContext::company($company))->where('description', 'Theirs')->count());
        $this->assertSame(3, Transaction::query()->forContext($this->ledger)->where('description', 'Double click')->count());
        $this->assertBooksVerify();
    }

    public function test_the_same_cash_ledger_csv_imported_twice_adds_each_row_once(): void
    {
        $csv = "date,description,amount\n2026-11-03,Coffee beans,-85.00\n2026-11-04,Client lunch,-310.50\n";
        $run = function () use ($csv) {
            $this->asOwner()->post(route('transactions.import.upload'), ['account_id' => $this->bank->id, 'file' => UploadedFile::fake()->createWithContent('cash.csv', $csv)])->assertSessionHasNoErrors();
            $stored = collect(Storage::disk('local')->allFiles('finance-imports'))->filter(fn ($f) => str_ends_with($f, '.csv'))->last();

            return $this->asOwner()->post(route('transactions.import.store'), ['token' => basename($stored, '.csv'), 'date_format' => 'ymd', 'amount_locale' => 'en']);
        };
        $run()->assertSessionHasNoErrors();
        $this->assertSame(2, Transaction::query()->forContext($this->ledger)->whereIn('description', ['Coffee beans', 'Client lunch'])->count());
        $run();
        $this->assertSame(2, Transaction::query()->forContext($this->ledger)->whereIn('description', ['Coffee beans', 'Client lunch'])->count(), 'importing the same file again adds nothing');
        $this->assertBooksVerify();
    }

    public function test_recurring_entries_generated_twice_for_the_same_date_are_one_entry(): void
    {
        $category = Category::factory()->forCompany($this->company)->create(['name' => 'Rent', 'type' => 'expense']);
        $this->asOwner()->post(route('transactions.store'), ['account_id' => $this->bank->id, 'category_id' => $category->id, 'description' => 'Office rent', 'amount' => '5000.00', 'type' => 'expense', 'date' => '2026-10-15', 'recurrence_type' => 'monthly', 'recurrence_interval' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1, RecurringRule::query()->forContext($this->ledger)->count());

        $this->artisan('finance:generate-recurring-transactions')->assertExitCode(0);
        $once = Transaction::query()->forContext($this->ledger)->where('description', 'Office rent')->count();
        $this->artisan('finance:generate-recurring-transactions')->assertExitCode(0);
        $this->assertSame($once, Transaction::query()->forContext($this->ledger)->where('description', 'Office rent')->count(), 'a second run the same day adds nothing');
        $this->assertSame(2, $once, 'the first entry and the 15 November one');
        $this->assertBooksVerify();
    }

    public function test_a_supplier_invoice_that_looks_like_one_already_booked_must_be_confirmed_in_writing(): void
    {
        $first = $this->postBill($this->paperCo, '2026-11-04', [$this->billLine()], ['supplier_reference' => 'INV-0042']);
        $body = fn (array $more = []) => $more + ['party_id' => $this->paperCo->id, 'bill_date' => '2026-11-05', 'supplier_reference' => 'inv 0042', 'document_type' => 'tax_invoice', 'submit_now' => '1', 'client_token' => 'dup-'.uniqid(),
            'lines' => [['description' => 'Printer paper', 'gl_account_id' => $this->gl('6190')->id, 'quantity' => '1', 'unit_price' => '4000.00', 'tax_code' => 'ZA_VAT_STD']]];

        $this->asOwner()->post(route('purchases.bills.store'), $body());
        $this->assertSame(1, Bill::query()->forContext($this->ledger)->where('status', 'open')->count(), 'the look-alike did not reach the books');
        $second = Bill::query()->forContext($this->ledger)->where('status', 'draft')->first();
        $this->assertNotNull($second, 'it is kept as a draft so nothing typed is lost');
        $this->assertSame(460_000, $this->balance('ap') * -1);

        $this->asOwner()->post(route('purchases.bills.submit', $second), ['confirm_duplicate' => '1'])->assertSessionHasNoErrors();
        $second->refresh();
        $this->assertSame('open', $second->status);
        $this->assertNotEmpty($second->duplicate_ack, 'who confirmed it is kept on the bill');
        $this->assertSame($this->owner->id, (int) $second->duplicate_ack['by']);
        $this->assertSame($first->id, $second->duplicate_ack['matches'][0]['id']);
    }
}
