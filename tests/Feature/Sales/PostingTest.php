<?php

namespace Tests\Feature\Sales;

use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Sales\SalesDocument;
use App\Services\Accounting\Chart;
use App\Services\Sales\DocumentLifecycle;
use App\Services\Sales\Support\SalesViolation;
use App\Services\Shared\FxMath;
use App\Services\Shared\FxService;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSales;
use Tests\TestCase;

/** Every posting balances, lands on the right accounts and carries the tags the VAT return reads. */
class PostingTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    private function entryOf(SalesDocument $document): JournalEntry
    {
        return LedgerContext::run($this->ledger, fn () => JournalEntry::query()->forContext($this->ledger)->findOrFail($document->gl_entry_id));
    }

    /** @return array<string, array{debit: int, credit: int}> by GL account system key or code, summed */
    private function lines(JournalEntry $entry): array
    {
        $out = [];
        foreach (LedgerContext::run($this->ledger, fn () => $entry->lines()->with('glAccount')->get()) as $l) {
            $key = $l->glAccount->system_key ?? $l->glAccount->code;
            $out[$key] ??= ['debit' => 0, 'credit' => 0, 'tax_code_ids' => []];
            $out[$key]['debit'] += $l->debit_cents;
            $out[$key]['credit'] += $l->credit_cents;
            $out[$key]['tax_code_ids'][] = $l->tax_code_id;
        }

        return $out;
    }

    public function test_a_standard_rated_invoice_posts_receivables_income_and_output_vat(): void
    {
        $this->setUpSales();
        $invoice = $this->issued([$this->line('Consulting', '2', 100_000)]);
        $lines = $this->lines($this->entryOf($invoice));

        $this->assertSame(230_000, $lines['ar']['debit']);
        $this->assertSame(200_000, $lines['sales']['credit']);
        $this->assertSame(30_000, $lines['vat_output']['credit']);
        $this->assertSame('invoice', $this->entryOf($invoice)->source_type);
        $this->assertSame((string) $invoice->id, $this->entryOf($invoice)->source_id);
        $this->assertNotNull($lines['vat_output']['tax_code_ids'][0], 'the VAT line is tagged with its tax code for the return');
        $this->assertNotNull($lines['sales']['tax_code_ids'][0], 'the net line is tagged too');
        $this->assertBooksBalance();
    }

    public function test_the_ar_line_names_the_customer(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $line = LedgerContext::run($this->ledger, fn () => $this->entryOf($invoice)->lines()->whereHas('glAccount', fn ($q) => $q->where('system_key', 'ar'))->first());

        $this->assertSame($this->customer->id, (int) $line->party_id);
    }

    public function test_mixed_rates_post_one_vat_line_per_code_and_zero_rated_has_none(): void
    {
        $this->setUpSales();
        $invoice = $this->issued([
            $this->line('Standard', '1', 100_000),
            $this->line('Basic food', '1', 50_000, 'ZA_VAT_ZERO'),
            $this->line('Exempt rent', '1', 20_000, 'ZA_VAT_EXEMPT'),
        ]);
        $lines = $this->lines($this->entryOf($invoice));

        $this->assertSame(115_000 + 50_000 + 20_000, $lines['ar']['debit']);
        $this->assertSame(15_000, $lines['vat_output']['credit']);
        $this->assertSame(170_000, $lines['sales']['credit'], 'all three nets land on the default sales account');
        $this->assertSame(3, count(array_unique($lines['sales']['tax_code_ids'])), 'one income line per tax code');
        $this->assertBooksBalance();
    }

    public function test_lines_can_post_to_different_income_accounts(): void
    {
        $this->setUpSales();
        $other = LedgerContext::run($this->ledger, fn () => app(Chart::class)->system('other_income'));
        $invoice = $this->issued([$this->line('Goods', '1', 100_000), $this->line('Fees', '1', 40_000, 'ZA_VAT_STD', ['income_account_id' => $other->id])]);
        $lines = $this->lines($this->entryOf($invoice));

        $this->assertSame(100_000, $lines['sales']['credit']);
        $this->assertSame(40_000, $lines['other_income']['credit']);
        $this->assertSame(21_000, $lines['vat_output']['credit']);
    }

    public function test_a_business_that_is_not_vat_registered_posts_no_vat(): void
    {
        $this->setUpSales(vat: false);
        $invoice = $this->issued([$this->line('Consulting', '1', 100_000, null)]);
        $lines = $this->lines($this->entryOf($invoice));

        $this->assertSame(100_000, (int) $invoice->total_cents);
        $this->assertSame(0, (int) $invoice->tax_cents);
        $this->assertArrayNotHasKey('vat_output', $lines);
        $this->assertFalse((bool) $invoice->vat_registered);
        $this->assertSame('Invoice', $invoice->title());
        $this->assertBooksBalance();
    }

    public function test_prices_that_include_tax_split_correctly(): void
    {
        $this->setUpSales();
        $invoice = $this->issued([$this->line('Retail', '1', 115_000)], ['prices_include_tax' => true]);

        $this->assertSame(115_000, (int) $invoice->total_cents);
        $this->assertSame(100_000, (int) $invoice->net_cents);
        $this->assertSame(15_000, (int) $invoice->tax_cents);
        $this->assertBooksBalance();
    }

    #[DataProvider('roundingCases')]
    public function test_rounding_edge_cases_always_balance_and_add_up(array $lines, bool $inclusive): void
    {
        $this->setUpSales();
        $invoice = $this->issued($lines, ['prices_include_tax' => $inclusive]);

        $this->assertSame((int) $invoice->net_cents + (int) $invoice->tax_cents, (int) $invoice->total_cents);
        $this->assertSame((int) $invoice->lines->sum('net_cents'), (int) $invoice->net_cents);
        $this->assertSame((int) $invoice->lines->sum('tax_cents'), (int) $invoice->tax_cents);
        $this->assertSame($invoice->total_cents, $this->glBalance('ar'));
        $this->assertBooksBalance();
    }

    public static function roundingCases(): array
    {
        $l = fn (string $q, int $u) => ['description' => 'x', 'quantity' => $q, 'unit_cents' => $u, 'tax_code' => 'ZA_VAT_STD'];

        return [
            'one cent' => [[$l('1', 1)], false],
            'three cents tax rounds up' => [[$l('1', 3)], false],
            'ten one-cent lines' => [array_fill(0, 10, $l('1', 1)), false],
            'odd quantity' => [[$l('0.3333', 99_999)], false],
            'big quantity' => [[$l('1234.5678', 12_345)], false],
            'inclusive odd' => [[$l('1', 999), $l('3', 333)], true],
            'inclusive one cent' => [[$l('1', 1)], true],
        ];
    }

    public function test_a_percentage_and_flat_discount_reduce_the_line_before_tax(): void
    {
        $this->setUpSales();
        $invoice = $this->issued([$this->line('Consulting', '1', 100_000, 'ZA_VAT_STD', ['discount_bp' => 1000, 'discount_cents' => 5_000])]);

        $this->assertSame(85_000, (int) $invoice->net_cents);
        $this->assertSame(12_750, (int) $invoice->tax_cents);
        $this->assertSame(15_000, (int) $invoice->discount_cents);
    }

    public function test_the_tax_rule_version_is_stored_on_the_document(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();

        $this->assertNotEmpty($invoice->tax_rule_versions);
        $this->assertContains('line', [$invoice->tax_rounding_level]);
        $this->assertSame('ZA', $invoice->tax_jurisdiction);
    }

    public function test_a_foreign_invoice_posts_in_base_currency_and_keeps_the_original_amount_and_rate(): void
    {
        $this->setUpSales();
        LedgerContext::run($this->ledger, fn () => app(FxService::class)->record('USD', 'ZAR', FxMath::parseRate('18.50'), now()->subDay()->toDateString(), 'manual'));
        $invoice = $this->issued([$this->line('Licence', '1', 100_000, 'ZA_VAT_ZERO_EXPORT')], ['currency' => 'USD']);

        $this->assertSame('USD', $invoice->currency);
        $this->assertSame(1_850_000_000, (int) $invoice->fx_rate_e8);
        $this->assertNotNull($invoice->fx_rate_recorded_at);
        $this->assertSame(100_000, (int) $invoice->total_cents);
        $this->assertSame(1_850_000, (int) $invoice->base_total_cents);
        $this->assertSame(1_850_000, $this->glBalance('ar'));

        $ar = LedgerContext::run($this->ledger, fn () => $this->entryOf($invoice)->lines()->whereHas('glAccount', fn ($q) => $q->where('system_key', 'ar'))->first());
        $this->assertSame('USD', $ar->txn_currency);
        $this->assertSame(100_000, (int) $ar->txn_amount_cents);
        $this->assertSame(1_850_000_000, (int) $ar->fx_rate_e8);
        $this->assertBooksBalance();
    }

    public function test_a_foreign_invoice_with_no_rate_is_refused_rather_than_guessed(): void
    {
        $this->setUpSales();

        $this->expectException(SalesViolation::class);
        $this->expectExceptionMessage('exchange rate');
        $this->draft([$this->line()], ['currency' => 'USD']);
    }

    public function test_a_typed_rate_is_stored_as_manual(): void
    {
        $this->setUpSales();
        $invoice = $this->issued([$this->line('Licence', '1', 100_000, 'ZA_VAT_ZERO_EXPORT')], ['currency' => 'USD', 'fx_rate' => '17.25']);

        $this->assertSame('manual', $invoice->fx_rate_source);
        $this->assertSame(1_725_000, (int) $invoice->base_total_cents);
    }

    public function test_a_voided_invoice_is_reversed_and_receivables_return_to_zero(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $voided = LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->void($invoice, 'Raised in error', $this->owner));

        $this->assertSame('void', $voided->status);
        $this->assertSame(0, $this->glBalance('ar'));
        $this->assertSame(0, $this->glBalance('vat_output'));
        $this->assertSame(0, $this->glBalance('sales'));
        $original = $this->entryOf($invoice);
        $this->assertSame('reversed', $original->status);
        $this->assertSame('reversal', JournalEntry::find($original->reversed_by_entry_id)->source_type);
        $this->assertBooksBalance();
    }

    public function test_every_entry_the_module_wrote_balances_in_the_database(): void
    {
        $this->setUpSales();
        $this->issued([$this->line('a', '3', 12_345), $this->line('b', '1.5', 6_789, 'ZA_VAT_ZERO')]);
        $this->issued([$this->line('c', '7', 1)]);

        $unbalanced = LedgerContext::run($this->ledger, fn () => JournalLine::query()->forContext($this->ledger)->selectRaw('journal_entry_id, SUM(debit_cents) - SUM(credit_cents) as d')->groupBy('journal_entry_id')->get()->filter(fn ($r) => (int) $r->d !== 0));
        $this->assertCount(0, $unbalanced);
    }
}
