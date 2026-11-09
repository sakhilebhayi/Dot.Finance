<?php

namespace Tests\Feature\Acceptance;

use App\Models\Shared\TaxRate;
use App\Models\Tax\TaxReturn;
use App\Services\Sales\DocumentDrafts;
use App\Services\Sales\DocumentIssuer;
use App\Services\Tax\TaxReturnService;
use App\Support\Ledger\LedgerContext;

/**
 * Owner criterion 19: tax calculations use the correct effective rules. The South African standard rate has been 15 percent since
 * 1 April 2018 and nothing in the seeded rules changes it, so this test does what an audit would: it adds a TEST rate version that
 * takes effect in the middle of the month (a fixture in the test database, clearly not a real SARS change) and shows that every
 * document uses the version in force on its own tax point, never "today's" and never a nearby one.
 */
class Acceptance19_EffectiveRulesTest extends AcceptanceCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntegration();
        // TEST FIXTURE: 16 percent from 15 November 2026. The seeded 15 percent version is closed the day before.
        $old = TaxRate::query()->withoutGlobalScopes()->where('rate_key', 'ZA_VAT_STD')->whereNull('valid_to')->firstOrFail();
        $new = $old->replicate(['id']);
        $old->update(['valid_to' => '2026-11-14']);
        $new->fill(['rate_bp' => 1600, 'valid_from' => '2026-11-15', 'valid_to' => null, 'note' => 'TEST FIXTURE: not a real rate change'])->save();
    }

    private function line(string $code = 'ZA_VAT_STD'): array
    {
        return [['description' => 'Service', 'quantity' => '1', 'unit_cents' => 100_000, 'tax_code' => $code]];
    }

    public function test_each_invoice_uses_the_rate_in_force_on_its_own_date(): void
    {
        $before = $this->issueInvoice($this->acme, $this->line(), '2026-11-10');
        $after = $this->issueInvoice($this->acme, $this->line(), '2026-11-20');
        $boundaryBefore = $this->issueInvoice($this->acme, $this->line(), '2026-11-14');
        $boundaryAfter = $this->issueInvoice($this->acme, $this->line(), '2026-11-15');

        $this->assertSame([15_000, 16_000, 15_000, 16_000], [(int) $before->tax_cents, (int) $after->tax_cents, (int) $boundaryBefore->tax_cents, (int) $boundaryAfter->tax_cents], 'the day before the change and the day of it');
        $this->assertNotSame($before->tax_rule_versions, $after->tax_rule_versions, 'the document records which rule version it used');
        $this->assertNotEmpty($before->tax_rule_versions);
        $this->assertBooksVerify();
    }

    public function test_the_time_of_supply_not_the_date_of_issue_decides_the_rate_and_a_credit_note_follows_the_invoice_it_credits(): void
    {
        // supplied on 10 November, invoiced on 20 November: the 15 percent in force on the supply date applies
        $doc = LedgerContext::run($this->ledger, function () {
            $draft = app(DocumentDrafts::class)->create($this->ledger, $this->owner, ['kind' => 'tax_invoice', 'party_id' => $this->acme->id, 'currency' => 'ZAR', 'issue_date' => '2026-11-20', 'supply_date' => '2026-11-10'], $this->line());

            return app(DocumentIssuer::class)->issue($draft, $this->owner)['document'];
        });
        $this->assertSame(15_000, (int) $doc->tax_cents);

        // credited later, at the new rate being in force: the credit note reverses what was charged
        $credit = $this->issueCreditNote($doc, '2026-11-25', 100_000);
        $this->assertSame(15_000, (int) $credit->tax_cents, 'the credit note takes back exactly the VAT that was charged');
        $this->assertSame(0, $this->balance('ar'));
        $this->assertSame(0, $this->balance('vat_output'));
    }

    public function test_supplier_bills_use_the_rate_of_their_own_date_and_the_posted_vat_is_claimed_at_that_rate(): void
    {
        $old = $this->postBill($this->paperCo, '2026-11-12', [$this->billLine()]);
        $new = $this->postBill($this->paperCo, '2026-11-25', [$this->billLine()]);
        $this->assertSame([60_000, 64_000], [(int) $old->tax_cents, (int) $new->tax_cents]);
        $this->assertSame(124_000, $this->balance('vat_input'));
    }

    public function test_the_vat_return_for_the_month_adds_up_each_side_at_the_rate_it_was_charged(): void
    {
        $this->issueInvoice($this->acme, $this->line(), '2026-11-10');
        $this->issueInvoice($this->acme, $this->line(), '2026-11-20');
        $this->postBill($this->paperCo, '2026-11-12', [$this->billLine()]);
        $this->postBill($this->paperCo, '2026-11-25', [$this->billLine()]);

        $this->asOwner()->post(route('tax.vat.start'), ['period_start' => '2026-11-01'])->assertSessionHasNoErrors();
        $return = TaxReturn::query()->forContext($this->ledger)->firstOrFail();
        $computed = LedgerContext::run($this->ledger, fn () => app(TaxReturnService::class)->compute($this->ledger, $return));
        $this->assertSame(31_000, $computed->outputTaxCents, '150.00 + 160.00');
        $this->assertSame(124_000, $computed->inputTaxCents, '600.00 + 640.00');
        $this->assertSame(31_000 - 124_000, $computed->payableCents);
        $this->assertTrue($computed->reconciliation['ties'], 'and it ties to the VAT accounts');
    }

    public function test_a_date_with_no_rule_in_force_is_refused_in_words_instead_of_using_a_nearby_rate(): void
    {
        $json = $this->asOwner()->postJson(route('sales.calculate'), [
            'kind' => 'tax_invoice', 'party_id' => $this->acme->id, 'currency' => 'ZAR', 'issue_date' => '2015-06-01',
            'lines' => [['description' => 'Old work', 'quantity' => '1', 'unit_price' => '100.00', 'tax_code' => 'ZA_VAT_STD']],
        ])->assertOk()->json();
        $this->assertFalse($json['ok']);
        $this->assertMatchesRegularExpression('/2015-06-01|no .*rate|not valid|on record/i', $json['message']);
    }
}
