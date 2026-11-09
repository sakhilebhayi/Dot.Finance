<?php

namespace Tests\Feature\Purchases;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPurchases;
use Tests\TestCase;

class BillPageWordsTest extends TestCase
{
    use BuildsPurchases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPurchases();
    }

    public function test_a_bill_shows_vat_in_words_with_the_code_behind_show_working(): void
    {
        $bill = $this->postedBill();

        $html = $this->inCompany($this->owner, $this->company)->get(route('purchases.bills.show', $bill))->assertOk()->assertSee('VAT 15% standard-rated')->getContent();
        $seen = preg_replace('#<details.*?</details>#s', '', $html);

        $this->assertStringNotContainsString('ZA_VAT_STD', $seen);
        $this->assertStringContainsString('ZA_VAT_STD', $html, 'still there as working');
    }
}
