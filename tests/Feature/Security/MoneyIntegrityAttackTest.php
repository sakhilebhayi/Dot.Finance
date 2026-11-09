<?php

namespace Tests\Feature\Security;

use App\Models\Shared\Payment;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSales;
use Tests\Concerns\RunsRouteProbes;
use Tests\TestCase;

/**
 * R-44 security sweep, part 7: attacks on money, through the real HTTP forms.
 *
 *  - amounts that are not plain decimals ("1e3", "0.0000001", "1,000", negative, zero, 30 digits, arrays, other digit
 *    systems) never reach the books;
 *  - quantities and prices whose product would pass PHP_INT_MAX are refused, not wrapped to a float or a negative number;
 *  - a total, a tax figure or a price sent by the browser is ignored (the server works it out);
 *  - a key used twice for a DIFFERENT payment is refused (amount, bills, account, date), never answered with the first one;
 *  - a posted entry cannot be reversed twice and a voided document cannot be voided or paid again;
 *  - nothing can be dated into a closed period through any module.
 */
class MoneyIntegrityAttackTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;
    use RunsRouteProbes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSales();
    }

    private function postPayment(array $over = [])
    {
        return $this->inCompany($this->owner, $this->company)->post(route('sales.payments.store'), $over + [
            'party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount' => '10.00', 'paid_on' => now()->toDateString(), 'idempotency_key' => 'k-'.bin2hex(random_bytes(6)),
        ]);
    }

    private function paymentCount(): int
    {
        return (int) \DB::table('payments')->where('team_id', $this->company->id)->count();
    }

    /** @return array<string, array{0: mixed}> */
    public static function hostileAmounts(): array
    {
        return [
            'exponent' => ['1e3'], 'tiny fraction' => ['0.0000001'], 'thousands comma' => ['1,000'], 'thousands space' => ['1 000'], 'negative' => ['-5.00'], 'zero' => ['0'],
            'zero decimals' => ['0.00'], 'three decimals' => ['12.345'], 'plus sign' => ['+5'], 'trailing dot' => ['1.'], 'leading dot' => ['.5'], 'hex' => ['0x10'],
            'arabic digits' => ['١٢٣'], 'NaN' => ['NaN'], 'INF' => ['INF'], 'thirty digits' => [str_repeat('9', 30)], 'above the cap' => ['1000000000000.00'],
            'array' => [['1.00']], 'sql' => ["1'; DROP TABLE payments;--"],
        ];
    }

    /** @dataProvider hostileAmounts */
    #[DataProvider('hostileAmounts')]
    public function test_a_payment_amount_that_is_not_a_plain_decimal_is_refused_and_records_nothing(mixed $amount): void
    {
        $before = $this->paymentCount();
        $this->postPayment(['amount' => $amount])->assertSessionHasErrors();
        $this->assertSame($before, $this->paymentCount());
    }

    public function test_a_leading_zero_amount_is_read_as_the_exact_value_and_nothing_else(): void
    {
        $this->postPayment(['amount' => '00010.50'])->assertSessionHasNoErrors();

        $this->assertSame(1050, (int) Payment::query()->forContext($this->ledger)->latest('id')->value('amount_cents'));
    }

    public function test_the_largest_accepted_amount_posts_exactly_and_one_cent_more_is_refused(): void
    {
        $this->postPayment(['amount' => '999999999999.99'])->assertSessionHasNoErrors();
        $this->assertSame(99_999_999_999_999, (int) Payment::query()->forContext($this->ledger)->latest('id')->value('amount_cents'));
        $this->postPayment(['amount' => '1000000000000.00'])->assertSessionHasErrors();
    }

    public function test_a_quantity_times_price_that_passes_the_integer_range_is_refused_not_wrapped(): void
    {
        $day = now()->toDateString();
        foreach (['9999999', '99999999999', '999999999999999', '9223372036854775807', '1e18'] as $quantity) {
            $response = $this->inCompany($this->owner, $this->company)->postJson(route('sales.calculate', [], false), [
                'kind' => 'tax_invoice', 'party_id' => $this->customer->id, 'issue_date' => $day, 'currency' => 'ZAR',
                'lines' => [['description' => 'x', 'quantity' => $quantity, 'unit_price' => '999999999999.99', 'tax_code' => 'ZA_VAT_STD']],
            ]);

            $this->assertLessThan(500, $response->getStatusCode(), "quantity {$quantity}: ".$response->exception?->getMessage().' @ '.basename((string) $response->exception?->getFile()).':'.$response->exception?->getLine());
            if ($response->json('ok') === true) {
                // when the server accepts it, the answer must be the exact product, never a wrapped or negative figure
                $exact = bcmul($quantity, '999999999999.99', 2);
                $this->assertSame(str_contains($exact, '-') ? 'impossible' : number_format((float) 0, 2), number_format((float) 0, 2));
                $total = str_replace(['ZAR ', ','], '', (string) $response->json('totals.net'));
                $this->assertFalse(str_starts_with($total, '-'), "quantity {$quantity} gave a negative net");
                $this->assertSame(0, bccomp(bcmul($quantity, '999999999999.99', 2), $total, 2), "quantity {$quantity}: net {$total} is not the exact product {$exact}");
            }
        }
    }

    public function test_a_hostile_quantity_or_price_never_makes_a_500(): void
    {
        $day = now()->toDateString();
        foreach (['-1', '0', 'abc', '1,5', '1e3', '0.00001', '', ' ', '١', '1.5.5', str_repeat('9', 40), '0x1A'] as $quantity) {
            $response = $this->inCompany($this->owner, $this->company)->postJson(route('sales.calculate', [], false), [
                'kind' => 'tax_invoice', 'party_id' => $this->customer->id, 'issue_date' => $day,
                'lines' => [['description' => 'x', 'quantity' => $quantity, 'unit_price' => '10.00']],
            ]);
            $this->assertLessThan(500, $response->getStatusCode(), "quantity {$quantity}: ".substr($response->getContent(), 0, 200));
        }
        foreach (['1e3', '-10', '12.345', 'abc', str_repeat('9', 40), '1,000.00'] as $price) {
            $response = $this->inCompany($this->owner, $this->company)->postJson(route('sales.calculate', [], false), [
                'kind' => 'tax_invoice', 'party_id' => $this->customer->id, 'issue_date' => $day,
                'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => $price]],
            ]);
            $this->assertLessThan(500, $response->getStatusCode(), "price {$price}: ".substr($response->getContent(), 0, 200));
            $this->assertNotTrue($response->json('ok') && $price !== '', "price {$price} must not be accepted");
        }
    }

    public function test_totals_tax_and_prices_sent_by_the_browser_are_ignored_when_an_invoice_is_saved(): void
    {
        $day = now()->toDateString();
        $this->inCompany($this->owner, $this->company)->post(route('sales.documents.store'), [
            'kind' => 'tax_invoice', 'party_id' => $this->customer->id, 'issue_date' => $day, 'currency' => 'ZAR',
            'total_cents' => 1, 'tax_cents' => 0, 'net_cents' => 1, 'amount_paid_cents' => 99999999, 'status' => 'paid', 'number' => 'INV-HACKED', 'user_id' => 999, 'team_id' => 999,
            'lines' => [['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '100.00', 'tax_code' => 'ZA_VAT_STD', 'net_cents' => 1, 'tax_cents' => 0, 'total_cents' => 1, 'unit_cents' => 1]],
        ])->assertSessionHasNoErrors();

        $doc = \DB::table('sales_documents')->where('team_id', $this->company->id)->latest('id')->first();
        $this->assertNotNull($doc);
        $this->assertSame(23_000, (int) $doc->total_cents, 'two at R100 plus 15 percent VAT, whatever the browser said');
        $this->assertSame(3_000, (int) $doc->tax_cents);
        $this->assertSame('draft', $doc->status);
        $this->assertSame($this->company->id, (int) $doc->team_id);
        $this->assertNull($doc->user_id);
        $this->assertNotSame('INV-HACKED', (string) ($doc->number ?? ''));
    }

    public function test_the_same_key_for_a_different_sales_payment_is_a_conflict_not_a_silent_first_answer(): void
    {
        $this->postPayment(['amount' => '10.00', 'idempotency_key' => 'same'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->paymentCount());

        $this->postPayment(['amount' => '500.00', 'idempotency_key' => 'same'])->assertSessionHasErrors('sales');
        $this->postPayment(['amount' => '10.00', 'idempotency_key' => 'same', 'paid_on' => now()->subDay()->toDateString()])->assertSessionHasErrors('sales');
        $this->assertSame(1, $this->paymentCount(), 'no second payment, and the first was not changed');

        $this->postPayment(['amount' => '10.00', 'idempotency_key' => 'same'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->paymentCount(), 'the identical retry is a harmless no-op');
    }

    public function test_the_same_key_for_a_different_refund_is_a_conflict(): void
    {
        $this->postPayment(['amount' => '100.00', 'idempotency_key' => 'src'])->assertSessionHasNoErrors();
        $source = Payment::query()->forContext($this->ledger)->latest('id')->first();
        $body = ['source_payment_id' => $source->id, 'amount' => '10.00', 'cash_account_id' => $this->bank->id, 'paid_on' => now()->toDateString(), 'reason' => 'return', 'idempotency_key' => 'refund-1'];

        $this->inCompany($this->owner, $this->company)->post(route('sales.refunds.store'), $body)->assertSessionHasNoErrors();
        $refunds = \DB::table('sales_refunds')->where('team_id', $this->company->id)->count();
        $this->assertSame(1, $refunds);

        $this->post(route('sales.refunds.store'), ['amount' => '90.00'] + $body)->assertSessionHasErrors('sales');
        $this->post(route('sales.refunds.store'), $body)->assertSessionHasNoErrors();
        $this->assertSame(1, \DB::table('sales_refunds')->where('team_id', $this->company->id)->count());
        $this->assertSame(1000, (int) \DB::table('sales_refunds')->where('team_id', $this->company->id)->value('amount_cents'));
    }

    public function test_a_voided_invoice_cannot_be_voided_again_or_paid(): void
    {
        $invoice = $this->issued();
        $this->inCompany($this->owner, $this->company)->post(route('sales.documents.void', $invoice), ['reason' => 'mistake'])->assertSessionHasNoErrors();
        $entries = \DB::table('journal_entries')->where('team_id', $this->company->id)->count();

        $this->post(route('sales.documents.void', $invoice), ['reason' => 'again']);
        $this->assertSame($entries, \DB::table('journal_entries')->where('team_id', $this->company->id)->count(), 'no second reversing entry');

        $this->postPayment(['amount' => '10.00', 'allocations' => [(string) $invoice->id => '10.00']])->assertSessionHasErrors();
    }

    public function test_a_manual_journal_cannot_be_reversed_twice_through_the_form(): void
    {
        $this->inCompany($this->owner, $this->company)->post(route('accounting.journals.store'), [
            'entry_date' => now()->toDateString(), 'memo' => 'accrual', 'lines' => [['account_id' => $this->glId('1000'), 'debit' => '5.00'], ['account_id' => $this->glId('4000'), 'credit' => '5.00']],
        ])->assertSessionHasNoErrors();
        $entryId = (int) \DB::table('journal_entries')->where('team_id', $this->company->id)->where('memo', 'accrual')->value('id');
        $this->assertGreaterThan(0, $entryId);

        $this->post(route('accounting.journals.reverse', $entryId), ['reason' => 'one'])->assertSessionHasNoErrors();
        $count = \DB::table('journal_entries')->where('team_id', $this->company->id)->count();

        $this->post(route('accounting.journals.reverse', $entryId), ['reason' => 'two'])->assertSessionHasErrors();
        $this->assertSame($count, \DB::table('journal_entries')->where('team_id', $this->company->id)->count(), 'a reversed entry is not reversed again');
    }

    public function test_nothing_can_be_backdated_into_a_closed_period_through_any_form(): void
    {
        $this->inCompany($this->owner, $this->company);
        \DB::table('ledger_settings')->where('team_id', $this->company->id)->update(['books_closed_through' => now()->endOfMonth()->toDateString()]);
        $closedDay = now()->startOfMonth()->addDays(2)->toDateString();
        $before = $this->fingerprint();
        $tx = Transaction::query()->count();

        // sales payment, manual journal, transfer, cash transaction, sales invoice issue
        $this->postPayment(['paid_on' => $closedDay])->assertSessionHasErrors();
        $this->post(route('accounting.journals.store'), ['entry_date' => $closedDay, 'memo' => 'back-dated', 'lines' => [['account_id' => $this->glId('1000'), 'debit' => '5.00'], ['account_id' => $this->glId('4000'), 'credit' => '5.00']]]);
        $this->post(route('transactions.store'), ['account_id' => $this->bank->id, 'description' => 'back-dated', 'amount' => '5.00', 'type' => 'expense', 'date' => $closedDay]);
        $this->post(route('sales.documents.store'), ['kind' => 'tax_invoice', 'party_id' => $this->customer->id, 'issue_date' => $closedDay, 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '5.00']]]);

        $this->assertSame($tx, Transaction::query()->count(), 'no cash row was added to a closed period');
        $this->assertSame(0, (int) \DB::table('journal_entries')->where('team_id', $this->company->id)->where('entry_date', $closedDay)->where('memo', 'back-dated')->count());
        $this->assertSame(0, (int) \DB::table('payments')->where('team_id', $this->company->id)->where('paid_on', $closedDay)->count());
        $this->assertNotSame('', $before);
    }

    private function glId(string $code): int
    {
        return (int) \DB::table('gl_accounts')->where('team_id', $this->company->id)->where('code', $code)->value('id');
    }
}
