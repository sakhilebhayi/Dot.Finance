<?php

namespace Tests\Feature\Acceptance;

use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Purchases\Bill;
use App\Models\Sales\SalesDocument;
use App\Models\Shared\FxRate;
use App\Models\Shared\Payment;
use App\Models\Shared\PaymentAllocation;
use App\Services\Reporting\ReportRunner;
use App\Services\Shared\FxMath;
use App\Services\Shared\FxService;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * Owner criterion 29: currency calculations are consistent. A USD invoice and a USD bill are booked at the stored rate of their date,
 * keep that rate for ever, are settled in USD at the rate of the payment day, and the difference is booked as realised exchange gain
 * or loss; every total in the books and reports is in the base currency (ZAR), each foreign bank account keeps its own USD balance,
 * rounding is half away from zero on the total and the last payment clears the document to the cent, and a rate is never edited.
 *
 * By hand: USD 1,000.00 invoiced at 18.00 = R18,000.00, received at 19.50 = R19,500.00, gain R1,500.00.
 *          USD 500.00 bill at 18.00 = R9,000.00, paid at 19.00 = R9,500.00, loss R500.00.
 */
class Acceptance29_CurrencyTest extends AcceptanceCase
{
    private Account $usd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntegration();
        $this->rate('USD', 'ZAR', '18.00', '2026-11-02');
        $this->rate('USD', 'ZAR', '19.00', '2026-11-06');
        $this->asOwner()->post(route('accounts.store'), ['name' => 'Dollar account', 'type' => 'checking', 'currency' => 'USD', 'opening_balance' => '0.00', 'opening_balance_date' => '2026-10-01'])->assertSessionHasNoErrors();
        $this->usd = Account::query()->forContext($this->ledger)->where('name', 'Dollar account')->firstOrFail();
        $this->acme->update(['currency' => 'USD']);
    }

    private function rate(string $from, string $to, string $rate, string $on): FxRate
    {
        return LedgerContext::run($this->ledger, fn () => app(FxService::class)->record($from, $to, FxMath::parseRate($rate), $on, 'manual', $this->owner, null, false, $this->ledger));
    }

    private function usdInvoice(string $date = '2026-11-05'): SalesDocument
    {
        $this->asOwner()->post(route('sales.documents.store'), ['kind' => 'tax_invoice', 'party_id' => $this->acme->id, 'currency' => 'USD', 'issue_date' => $date, 'client_token' => 'usd-'.uniqid(),
            'lines' => [['description' => 'Licence', 'quantity' => '1', 'unit_price' => '1000.00', 'tax_code' => 'ZA_VAT_ZERO_EXPORT']]])->assertSessionHasNoErrors();
        $doc = SalesDocument::query()->forContext($this->ledger)->latest('id')->firstOrFail();
        $this->asOwner()->post(route('sales.documents.issue', $doc))->assertSessionHasNoErrors();

        return $doc->fresh();
    }

    private function gain(): int
    {
        return -$this->balance('fx_realised');
    }

    public function test_a_foreign_invoice_is_booked_at_the_stored_rate_of_its_date_and_keeps_it_when_the_rate_moves_on(): void
    {
        $doc = $this->usdInvoice('2026-11-05');
        $this->assertSame(['USD', 100_000, 1_800_000_000, 1_800_000], [$doc->currency, (int) $doc->total_cents, (int) $doc->fx_rate_e8, (int) $doc->base_total_cents], 'USD 1,000.00 at 18.00 (the 2 November rate)');
        $this->assertSame(1_800_000, $this->balance('ar'));

        $this->rate('USD', 'ZAR', '25.00', '2026-11-07');                       // the market moves; the issued invoice does not
        $this->assertSame([1_800_000_000, 1_800_000], [(int) $doc->fresh()->fx_rate_e8, (int) $doc->fresh()->base_total_cents]);
        $this->assertSame(1_800_000, $this->balance('ar'));
        $this->asOwner()->put(route('sales.documents.update', $doc), ['kind' => 'tax_invoice', 'party_id' => $this->acme->id, 'currency' => 'USD', 'issue_date' => '2026-11-05', 'fx_rate' => '25.00',
            'lines' => [['description' => 'Licence', 'quantity' => '1', 'unit_price' => '1000.00', 'tax_code' => 'ZA_VAT_ZERO_EXPORT']]]);
        $this->assertSame(1_800_000_000, (int) $doc->fresh()->fx_rate_e8, 'an issued document cannot be repriced');

        // a typed rate wins on a draft and is the one stored
        $this->asOwner()->post(route('sales.documents.store'), ['kind' => 'tax_invoice', 'party_id' => $this->acme->id, 'currency' => 'USD', 'issue_date' => '2026-11-08', 'fx_rate' => '18.37', 'client_token' => 'typed',
            'lines' => [['description' => 'Licence', 'quantity' => '1', 'unit_price' => '1000.00', 'tax_code' => 'ZA_VAT_ZERO_EXPORT']]])->assertSessionHasNoErrors();
        $this->assertSame([1_837_000_000, 1_837_000], [(int) SalesDocument::query()->forContext($this->ledger)->where('client_token', 'typed')->value('fx_rate_e8'), (int) SalesDocument::query()->forContext($this->ledger)->where('client_token', 'typed')->value('base_total_cents')]);
    }

    public function test_a_foreign_receipt_realises_the_difference_between_the_invoice_rate_and_the_payment_rate(): void
    {
        $doc = $this->usdInvoice();
        $this->asOwner()->post(route('sales.payments.store'), ['party_id' => $this->acme->id, 'cash_account_id' => $this->usd->id, 'amount' => '1000.00', 'paid_on' => '2026-11-10', 'fx_rate' => '19.50',
            'idempotency_key' => 'usd-receipt', 'allocations' => [$doc->id => '1000.00']])->assertSessionHasNoErrors();

        $this->assertSame('paid', $doc->fresh()->status);
        $this->assertSame(0, $this->balance('ar'), 'the receivable is cleared at what it was carried at');
        $this->assertSame(150_000, $this->gain(), 'USD 1,000.00 x (19.50 - 18.00) = R1,500.00 realised gain');
        $this->assertSame(1_950_000, $this->balance($this->glOfAccount($this->usd)), 'the dollar account is carried at the rate it came in at');
        $this->assertSame(100_000, (int) Account::query()->forContext($this->ledger)->withBalance()->findOrFail($this->usd->id)->balanceCents(), 'and its own balance is USD 1,000.00');
        $this->asOwner()->get(route('accounts.index'))->assertOk()->assertSee('1,000.00');
        $this->assertBooksVerify();
    }

    public function test_a_foreign_bill_paid_at_a_different_rate_books_a_loss_and_the_ledgers_agree(): void
    {
        $this->paperCo->update(['currency' => 'USD']);
        $this->asOwner()->post(route('purchases.bills.store'), ['party_id' => $this->paperCo->id, 'bill_date' => '2026-11-04', 'supplier_reference' => 'USD-1', 'document_type' => 'tax_invoice', 'currency' => 'USD', 'client_token' => 'usd-bill', 'submit_now' => '1',
            'lines' => [['description' => 'Hosting', 'gl_account_id' => $this->gl('6190')->id, 'quantity' => '1', 'unit_price' => '500.00', 'tax_code' => 'ZA_VAT_ZERO']]])->assertSessionHasNoErrors();
        $bill = Bill::query()->forContext($this->ledger)->where('supplier_reference', 'USD-1')->firstOrFail();
        $this->assertSame([50_000, 900_000, 1_800_000_000], [(int) $bill->total_cents, (int) $bill->base_total_cents, (int) $bill->fx_rate_e8], 'USD 500.00 at the 2 November rate of 18.00');
        $this->assertSame(900_000, -$this->balance('ap'));

        // funds for the payment: USD 600.00 received on account first so the dollar account is not overdrawn
        $this->asOwner()->post(route('purchases.payments.store'), ['party_id' => $this->paperCo->id, 'cash_account_id' => $this->usd->id, 'paid_on' => '2026-11-12', 'idempotency_key' => 'usd-pay', 'pay' => ['bill-'.$bill->id => '500.00']])->assertSessionHasNoErrors();
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(0, $this->balance('ap'));
        $this->assertSame(-50_000, $this->gain(), 'carried at R9,000.00, paid at the 6 November rate of 19.00 = R9,500.00: a loss of R500.00');
        $this->assertSame(-50_000, (int) PaymentAllocation::query()->forContext($this->ledger)->value('fx_diff_cents'));
        $this->assertBooksVerify();
    }

    public function test_base_currency_totals_net_the_gain_and_loss_and_the_reports_and_trial_balance_are_in_rand(): void
    {
        $this->test_a_foreign_receipt_realises_the_difference_between_the_invoice_rate_and_the_payment_rate();
        $pl = app(ReportRunner::class)->run($this->ledger, 'profit-loss', ['from' => '2026-11-01', 'to' => '2026-11-30'], CarbonImmutable::parse('2026-11-30'));
        $this->assertSame('ZAR', $pl->header['currency']);
        $this->assertSame(1_800_000, $pl->cell('Total income', 'total'), 'the invoice, in rand, at its own rate');
        $this->assertSame(1_950_000, $pl->cell('Net profit', 'total'), 'R18,000.00 of sales plus the R1,500.00 realised gain');
        $tb = app(ReportRunner::class)->run($this->ledger, 'trial-balance', ['to' => '2026-11-30'], CarbonImmutable::parse('2026-11-30'));
        $this->assertSame($tb->cell('Total', 'debit'), $tb->cell('Total', 'credit'));
        $bs = app(ReportRunner::class)->run($this->ledger, 'balance-sheet', ['to' => '2026-11-30'], CarbonImmutable::parse('2026-11-30'));
        $this->assertSame($bs->cell('Total assets', 'total'), $bs->cell('Total liabilities and equity', 'total'));
        $this->assertStringContainsString('Foreign currency balances are shown in the base currency at the rate posted', implode(' ', $bs->notes), 'the balance sheet says it does not revalue');
    }

    public function test_rounding_is_half_away_from_zero_on_the_total_and_part_payments_clear_the_document_to_the_cent(): void
    {
        // 3 x USD 33.33 = USD 99.99 at 18.37 is R1,836.8163: R1,836.82 (not three rounded lines)
        $this->asOwner()->post(route('sales.documents.store'), ['kind' => 'tax_invoice', 'party_id' => $this->acme->id, 'currency' => 'USD', 'issue_date' => '2026-11-08', 'fx_rate' => '18.37', 'client_token' => 'round',
            'lines' => [['description' => 'Tiny', 'quantity' => '3', 'unit_price' => '33.33', 'tax_code' => 'ZA_VAT_ZERO_EXPORT']]])->assertSessionHasNoErrors();
        $doc = SalesDocument::query()->forContext($this->ledger)->where('client_token', 'round')->firstOrFail();
        $this->asOwner()->post(route('sales.documents.issue', $doc))->assertSessionHasNoErrors();
        $this->assertSame([9_999, 183_682], [(int) $doc->fresh()->total_cents, (int) $doc->fresh()->base_total_cents]);
        $this->assertSame(183_682, $this->balance('ar'));

        foreach ([[3_333, '18.50'], [3_333, '18.10'], [3_333, '19.01']] as $i => [$cents, $rate]) {
            $this->asOwner()->post(route('sales.payments.store'), ['party_id' => $this->acme->id, 'cash_account_id' => $this->usd->id, 'amount' => number_format($cents / 100, 2, '.', ''), 'paid_on' => '2026-11-10', 'fx_rate' => $rate,
                'idempotency_key' => "round-{$i}", 'allocations' => [$doc->id => number_format($cents / 100, 2, '.', '')]])->assertSessionHasNoErrors();
        }
        $this->assertSame('paid', $doc->fresh()->status);
        $this->assertSame(0, $this->balance('ar'), 'no cent is left behind by rounding');
        $this->assertBooksVerify();
    }

    public function test_a_currency_with_no_rate_is_refused_in_words_not_guessed_and_a_payment_must_match_the_account_currency(): void
    {
        $json = $this->asOwner()->postJson(route('sales.calculate'), ['kind' => 'tax_invoice', 'party_id' => $this->acme->id, 'currency' => 'EUR', 'issue_date' => '2026-11-05',
            'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '100.00', 'tax_code' => 'ZA_VAT_ZERO_EXPORT']]])->assertOk()->json();
        $this->assertFalse($json['ok']);
        $this->assertStringContainsString('exchange rate', $json['message']);

        $rand = $this->usdInvoice();
        $this->asOwner()->post(route('sales.payments.store'), ['party_id' => $this->acme->id, 'cash_account_id' => $this->bank->id, 'amount' => '100.00', 'paid_on' => '2026-11-10', 'idempotency_key' => 'wrong-ccy', 'allocations' => [$rand->id => '100.00']])->assertSessionHasErrors('sales');
        $this->assertSame(0, Payment::query()->forContext($this->ledger)->count());
    }

    public function test_a_rate_is_never_overwritten_a_correction_is_a_new_row_and_old_documents_keep_their_rate(): void
    {
        $doc = $this->usdInvoice('2026-11-05');
        $before = FxRate::query()->withoutGlobalScopes()->where('rate_e8', 1_800_000_000)->count();
        $this->rate('USD', 'ZAR', '18.05', '2026-11-02');                       // a correction for the same day
        $this->assertSame($before, FxRate::query()->withoutGlobalScopes()->where('rate_e8', 1_800_000_000)->count(), 'the old rate row is still there');
        $this->assertGreaterThanOrEqual(2, FxRate::query()->withoutGlobalScopes()->where('as_of', '2026-11-02')->count());
        $this->assertSame(1_805_000_000, app(FxService::class)->forContext($this->ledger)->rate('USD', 'ZAR', '2026-11-05')->rateE8, 'the lookup uses the newest entry for the day');
        $this->assertSame(1_800_000_000, (int) $doc->fresh()->fx_rate_e8, 'the invoice booked before keeps the rate it was booked at');

        $row = FxRate::query()->withoutGlobalScopes()->where('rate_e8', 1_800_000_000)->firstOrFail();
        foreach ([fn () => $row->update(['rate_e8' => 1]), fn () => $row->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('a stored rate was changed');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1_800_000_000, (int) $row->fresh()->rate_e8);
    }

    private function glOfAccount(Account $account): string
    {
        return (string) GlAccount::query()->forContext($this->ledger)->findOrFail($account->fresh()->gl_account_id)->code;
    }
}
