<?php

namespace Tests\Feature\Sales;

use App\Support\TaxWords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSales;
use Tests\TestCase;

/** The jury saw ZA_VAT_STD and "tax_rules#25" on invoice and bill pages. Words on the page; identifiers behind "Show working". */
class PlainTaxWordsTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    /** The page with every <details> block taken out: what a person reads without opening anything. */
    private function visible(string $html): string
    {
        return preg_replace('#<details.*?</details>#s', '', $html) ?? $html;
    }

    public function test_tax_labels_are_words(): void
    {
        $this->assertSame('VAT 15% standard-rated', TaxWords::label('ZA_VAT_STD', 1500));
        $this->assertSame('VAT 0% zero-rated', TaxWords::label('ZA_VAT_ZERO', 0));
        $this->assertSame('VAT exempt', TaxWords::label('ZA_VAT_EXEMPT', 0));
        $this->assertSame('Outside the scope of VAT', TaxWords::label('ZA_VAT_OUT_OF_SCOPE', 0));
        $this->assertSame('No VAT', TaxWords::label(null));
        $this->assertSame('VAT 15% standard-rated, entertainment (not claimable)', TaxWords::label('ZA_VAT_DENIED_ENTERTAINMENT', 1500));
        $this->assertSame('VAT 15% odd thing', TaxWords::label('ZA_VAT_ODD_THING', 1500), 'an unknown code is still turned into words');
    }

    public function test_an_invoice_page_shows_vat_in_words_and_keeps_rule_ids_behind_show_working(): void
    {
        $this->setUpSales();
        $invoice = $this->issued([$this->line('Work', '1', 100_000)]);

        $html = $this->actingAs($this->owner)->get(route('sales.documents.show', $invoice))->assertOk()->assertSee('Show working')->getContent();
        $seen = $this->visible($html);

        $this->assertStringContainsString('VAT 15% standard-rated', $seen);
        $this->assertStringNotContainsString('ZA_VAT_STD', $seen);
        $this->assertDoesNotMatchRegularExpression('/tax_(rules|rates|codes)#\d+/', $seen);
        $this->assertMatchesRegularExpression('/tax_(rules|rates|codes)#\d+/', $html, 'the identifiers are still available as working');
    }
}
