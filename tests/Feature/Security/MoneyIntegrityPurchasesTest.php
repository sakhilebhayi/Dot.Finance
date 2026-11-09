<?php

namespace Tests\Feature\Security;

use App\Models\Purchases\Bill;
use App\Models\Shared\Payment;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPurchases;
use Tests\TestCase;

/**
 * R-44 security sweep, part 7b: the same attacks on the Purchases side: hostile quantities and prices on a bill, a payment
 * key re-used for a different payment (amount, bills), a payment run key re-used for a different run, double-submit, paying a
 * voided bill, voiding twice.
 */
class MoneyIntegrityPurchasesTest extends TestCase
{
    use BuildsPurchases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPurchases();
    }

    private function asOwner()
    {
        return $this->inCompany($this->owner, $this->company);
    }

    private function billBody(array $line): array
    {
        return ['party_id' => $this->supplier->id, 'bill_date' => '2026-11-02', 'currency' => 'ZAR', 'lines' => [$line + ['description' => 'x', 'gl_account_id' => $this->gl('6190')->id, 'tax_code' => 'ZA_VAT_STD']]];
    }

    public function test_a_hostile_quantity_or_price_on_a_bill_is_refused_never_a_server_error_or_a_wrapped_total(): void
    {
        $hostile = [
            ['quantity' => '99999999', 'unit_price' => '999999999999.99'], ['quantity' => '1e3', 'unit_price' => '1.00'], ['quantity' => '-1', 'unit_price' => '1.00'], ['quantity' => '0.00001', 'unit_price' => '1.00'],
            ['quantity' => '1', 'unit_price' => '1e3'], ['quantity' => '1', 'unit_price' => '-1.00'], ['quantity' => '1', 'unit_price' => '1,000.00'], ['quantity' => '1', 'unit_price' => str_repeat('9', 30)],
            ['quantity' => '12345678.9999', 'unit_price' => '999999999999.99'],
        ];

        foreach ($hostile as $line) {
            $before = Bill::query()->forContext($this->ctx)->count();
            $response = $this->asOwner()->post(route('purchases.bills.store'), $this->billBody($line));

            $this->assertLessThan(500, $response->getStatusCode(), json_encode($line).': '.$response->exception?->getMessage());
            $this->assertSame($before, Bill::query()->forContext($this->ctx)->count(), json_encode($line).' must not create a bill');
        }
    }

    public function test_the_largest_legitimate_bill_line_still_saves_with_the_exact_total(): void
    {
        $this->asOwner()->post(route('purchases.bills.store'), $this->billBody(['quantity' => '1', 'unit_price' => '1000000.00']))->assertSessionHasNoErrors();

        $this->assertSame(115_000_000, (int) Bill::query()->forContext($this->ctx)->latest('id')->value('total_cents'), 'R1,000,000.00 plus 15 percent VAT');
    }

    public function test_a_supplier_payment_key_used_again_for_a_different_amount_or_bill_is_a_conflict(): void
    {
        $a = $this->postedBill(['supplier_reference' => 'A-1']);
        $b = $this->postedBill(['supplier_reference' => 'B-2', 'lines' => [$this->line(['unit_price_cents' => 250_000])]]);
        $body = ['party_id' => $this->supplier->id, 'cash_account_id' => $this->bank->id, 'paid_on' => '2026-11-09', 'idempotency_key' => 'pay-1', 'pay' => ['bill-'.$a->id => '100.00']];

        $this->asOwner()->post(route('purchases.payments.store'), $body)->assertSessionHasNoErrors();
        $count = fn () => (int) \DB::table('payments')->where('team_id', $this->company->id)->count();
        $this->assertSame(1, $count());

        // a different amount on the same bill, a different bill, an extra bill, and an on-account top-up under the same key
        $this->post(route('purchases.payments.store'), ['pay' => ['bill-'.$a->id => '900.00']] + $body)->assertSessionHasErrors('purchases');
        $this->post(route('purchases.payments.store'), ['pay' => ['bill-'.$b->id => '100.00']] + $body)->assertSessionHasErrors('purchases');
        $this->post(route('purchases.payments.store'), ['pay' => ['bill-'.$a->id => '100.00', 'bill-'.$b->id => '100.00']] + $body)->assertSessionHasErrors('purchases');
        $this->assertSame(1, $count(), 'nothing new was recorded');

        // the identical retry is a harmless no-op
        $this->post(route('purchases.payments.store'), $body)->assertSessionHasNoErrors();
        $this->assertSame(1, $count());
        $this->assertSame(10_000, (int) Payment::query()->forContext($this->ctx)->value('amount_cents'));
    }

    public function test_a_payment_run_key_used_again_for_a_different_run_is_a_conflict(): void
    {
        $a = $this->postedBill(['due_date' => '2026-11-10', 'supplier_reference' => 'A-1']);
        $b = $this->postedBill(['due_date' => '2026-11-10', 'supplier_reference' => 'B-2', 'lines' => [$this->line(['unit_price_cents' => 250_000])]]);
        $body = ['name' => 'Run', 'due_by' => '2026-11-30', 'paid_on' => '2026-11-30', 'cash_account_id' => $this->bank->id, 'idempotency_key' => 'run-1', 'pick' => ['bill-'.$a->id]];

        $this->asOwner()->post(route('purchases.runs.store'), $body)->assertSessionHasNoErrors();
        $runs = fn () => (int) \DB::table('payment_runs')->where('team_id', $this->company->id)->count();
        $this->assertSame(1, $runs());

        $this->post(route('purchases.runs.store'), ['pick' => ['bill-'.$b->id]] + $body)->assertSessionHasErrors('purchases');
        $this->post(route('purchases.runs.store'), ['pick' => ['bill-'.$a->id, 'bill-'.$b->id]] + $body)->assertSessionHasErrors('purchases');
        $this->post(route('purchases.runs.store'), ['paid_on' => '2026-12-15'] + $body)->assertSessionHasErrors('purchases');
        $this->assertSame(1, $runs());

        $this->post(route('purchases.runs.store'), $body)->assertSessionHasNoErrors();
        $this->assertSame(1, $runs());
    }

    public function test_a_voided_bill_cannot_be_voided_again_or_paid(): void
    {
        $bill = $this->postedBill();
        $this->asOwner()->post(route('purchases.bills.void', $bill), ['reason' => 'wrong supplier'])->assertSessionHasNoErrors();
        $entries = (int) \DB::table('journal_entries')->where('team_id', $this->company->id)->count();

        $this->post(route('purchases.bills.void', $bill), ['reason' => 'again']);
        $this->assertSame($entries, (int) \DB::table('journal_entries')->where('team_id', $this->company->id)->count(), 'no second reversal');

        $this->post(route('purchases.payments.store'), ['party_id' => $this->supplier->id, 'cash_account_id' => $this->bank->id, 'paid_on' => '2026-11-09', 'idempotency_key' => 'pay-void', 'pay' => ['bill-'.$bill->id => '100.00']])->assertSessionHasErrors('purchases');
        $this->assertSame(0, (int) \DB::table('payments')->where('team_id', $this->company->id)->count());
        $this->assertNotNull(LedgerContext::company($this->company));
    }
}
