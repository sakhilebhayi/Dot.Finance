<?php

namespace Tests\Feature\Tax\Qa;

use App\Models\Tax\TaxReturnAdjustment;
use App\Services\Accounting\PostingLine;
use App\Services\Tax\Dto\ComputedReturn;
use App\Services\Tax\Vat201Builder;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaxFixtures;
use Tests\TestCase;

/**
 * INDEPENDENT QA: one worked VAT201 in which EVERY box 1 to 20 is derived by hand from the entries below (arithmetic in the
 * comments), checked against the SARS guide GEN-ELEC-04-G01 rev 11 formulas, not against the builder's own output.
 * Period 1 September to 31 October 2026 (category B). All amounts in cents.
 */
class QaVat201WorkedExampleTest extends TestCase
{
    use BuildsTaxFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->taxLedger();
        $this->vatProfile();
    }

    private function build(iterable $adjustments = [], string $from = '2026-09-01', string $to = '2026-10-31'): ComputedReturn
    {
        return LedgerContext::run($this->tax, fn () => app(Vat201Builder::class)->build($this->tax, CarbonImmutable::parse($from), CarbonImmutable::parse($to), $adjustments));
    }

    private function adj(string $box, int $cents, string $kind = 'other'): TaxReturnAdjustment
    {
        return (new TaxReturnAdjustment)->forceFill(['id' => random_int(1, 9999), 'box_code' => $box, 'kind' => $kind, 'amount_cents' => $cents, 'reason' => 'QA worked example']);
    }

    /** Books for the worked example: eleven entries. */
    private function books(): void
    {
        $this->sale('2026-09-01', 1_000_000);                       // S1 R10,000.00 + R1,500.00 (first day of the period)
        $this->sale('2026-09-17', 333_333);                          // S2 R3,333.33 + VAT 15% = 499,999.95c -> booked 50,000 (the fixture rounds 50,000.45 down)
        $this->sale('2026-10-05', 2_000_000, 'ZA_VAT_CAPITAL');      // S3 capital sale R20,000.00 + R3,000.00
        $this->sale('2026-10-06', 500_000, 'ZA_VAT_ZERO');           // S4 zero-rated R5,000.00
        $this->sale('2026-10-07', 750_000, 'ZA_VAT_ZERO_EXPORT');    // S5 exported R7,500.00
        $this->sale('2026-10-08', 120_000, 'ZA_VAT_EXEMPT');         // S6 exempt R1,200.00
        $this->sale('2026-10-09', 30_000, 'ZA_VAT_OUT_OF_SCOPE');    // S7 out of scope R300.00
        $this->purchase('2026-09-10', 400_000);                      // P1 R4,000.00 + R600.00 -> field 15 = 60,000
        $this->purchase('2026-09-11', 5_000_000, 'ZA_VAT_CAPITAL', null, null, 'Equipment and vehicles (cost)'); // P2 capital R50,000.00 -> 14 = 750,000
        $this->purchase('2026-09-12', 115_000, 'ZA_VAT_DENIED_ENTERTAINMENT', 0, null, 'Advertising and marketing'); // P3 client lunch, VAT inside the cost: nothing claimed
        $this->purchase('2026-10-31', 23_000_000, 'ZA_VAT_DENIED_MOTOR_CAR', 0, null, 'Equipment and vehicles (cost)'); // P4 motor car, last day of the period: nothing claimed
        $this->creditNote('2026-10-10', 100_000, 15_000);            // CN1 credit note ISSUED: R1,000.00 + R150.00 -> field 18 = 15,000

        // SCN1 supplier credit note RECEIVED: Cr expense R300.00, Cr VAT input R45.00, Dr AP R345.00 -> field 12 += 4,500
        $code = $this->taxCodeId('ZA_VAT_STD', '2026-10-12');
        $this->postEntry($this->tax, '2026-10-12', 'credit_note', 'Supplier credit note', [
            new PostingLine($this->glByName('Rent', $this->tax), creditCents: 30_000, taxCodeId: $code),
            new PostingLine('vat_input', creditCents: 4_500, taxCodeId: $code),
            new PostingLine('ap', debitCents: 34_500),
        ]);
    }

    public function test_every_box_one_to_twenty_by_hand(): void
    {
        $this->books();
        $r = $this->build([
            $this->adj('5', 1_000_000),          // accommodation > 28 days, VAT exclusive R10,000.00
            $this->adj('7', 200_000),            // accommodation <= 28 days, VAT exclusive R2,000.00
            $this->adj('10', 1_150_000),         // change in use, VAT inclusive R11,500.00
            $this->adj('12', 11_500, 'bad_debt_recovered'), // recovered bad debt: VAT portion R115.00
            $this->adj('14A', 90_000),           // imported capital goods VAT
            $this->adj('15A', 4_000),            // imported other goods VAT
            $this->adj('16', 20_000, 'change_in_use'),
            $this->adj('17', 7_500, 'bad_debt'),
        ]);

        // OUTPUT SIDE
        // field 1  = S1 1,150,000 + S2 383,333 (333,333 + 50,000)                  = 1,533,333   (VAT inclusive)
        // field 1A = S3 2,000,000 + 300,000                                         = 2,300,000
        // field 2  = S4                                                              =   500,000
        // field 2A = S5                                                              =   750,000
        // field 3  = S6 120,000 + S7 30,000                                          =   150,000
        // field 4  = booked output VAT 150,000 + 50,000                              =   200,000
        // field 4A = booked output VAT on the capital sale                           =   300,000
        $this->assertSame(1_533_333, $r->amount('1'));
        $this->assertSame(2_300_000, $r->amount('1A'));
        $this->assertSame(500_000, $r->amount('2'));
        $this->assertSame(750_000, $r->amount('2A'));
        $this->assertSame(150_000, $r->amount('3'));
        $this->assertSame(200_000, $r->amount('4'));
        $this->assertSame(300_000, $r->amount('4A'));

        // field 5 = 1,000,000 ; field 6 = 60% of field 5 = 600,000 ; field 7 = 200,000 ; field 8 = 6 + 7 = 800,000
        // field 9 = field 8 x 15% (the RATE, not the 15/115 fraction) = 120,000
        $this->assertSame(1_000_000, $r->amount('5'));
        $this->assertSame(600_000, $r->amount('6'));
        $this->assertSame(200_000, $r->amount('7'));
        $this->assertSame(800_000, $r->amount('8'));
        $this->assertSame(120_000, $r->amount('9'));

        // field 10 = 1,150,000 ; field 11 = field 10 x 15/115 = 1,150,000 / 115 x 15 = 10,000 x 15 = 150,000
        $this->assertSame(1_150_000, $r->amount('10'));
        $this->assertSame(150_000, $r->amount('11'), 'field 11 is field 10 x 15/115 (SARS guide), not an input the user must also remember to fill');

        // field 12 = supplier credit note received 4,500 + recovered bad debt 11,500 = 16,000
        $this->assertSame(16_000, $r->amount('12'));

        // field 13 = 4 + 4A + 9 + 11 + 12 = 200,000 + 300,000 + 120,000 + 150,000 + 16,000 = 786,000
        $this->assertSame(786_000, $r->amount('13'));

        // INPUT SIDE
        // 14 = 750,000 ; 14A = 90,000 ; 15 = 60,000 ; 15A = 4,000 ; 16 = 20,000 ; 17 = 7,500 ; 18 = credit note issued 15,000
        // (entertainment and motor car: nothing)
        $this->assertSame(750_000, $r->amount('14'));
        $this->assertSame(90_000, $r->amount('14A'));
        $this->assertSame(60_000, $r->amount('15'));
        $this->assertSame(4_000, $r->amount('15A'));
        $this->assertSame(20_000, $r->amount('16'));
        $this->assertSame(7_500, $r->amount('17'));
        $this->assertSame(15_000, $r->amount('18'));

        // field 19 = 750,000 + 90,000 + 60,000 + 4,000 + 20,000 + 7,500 + 15,000 = 946,500
        $this->assertSame(946_500, $r->amount('19'));

        // field 20 = 13 - 19 = 786,000 - 946,500 = -160,500 (a refund)
        $this->assertSame(-160_500, $r->amount('20'));
        $this->assertSame(-160_500, $r->payableCents);
        $this->assertSame(786_000, $r->outputTaxCents);
        $this->assertSame(946_500, $r->inputTaxCents);

        // no error-level issue: the example is clean
        $this->assertSame([], array_values(array_filter($r->issues, fn ($i) => $i['severity'] === 'error')));
    }

    public function test_books_alone_without_adjustments_give_the_book_boxes_and_nothing_invented(): void
    {
        $this->books();
        $r = $this->build();

        // output: 200,000 + 300,000 = 500,000 ; input: 750,000 + 60,000 + 15,000 (credit note issued) = 825,000 ; field 12 4,500
        // field 13 = 500,000 + 0 + 0 + 4,500 = 504,500 ; field 19 = 825,000 ; field 20 = -320,500
        $this->assertSame(504_500, $r->amount('13'));
        $this->assertSame(825_000, $r->amount('19'));
        $this->assertSame(-320_500, $r->amount('20'));
        foreach (['5', '6', '7', '8', '9', '10', '11', '14A', '15A', '16', '17'] as $zero) {
            $this->assertSame(0, $r->amount($zero), "field {$zero} is empty when nothing feeds it");
        }
    }

    public function test_the_period_is_inclusive_on_both_ends_and_nothing_outside_leaks_in(): void
    {
        $this->sale('2026-08-31', 100_000);   // day before: out
        $this->sale('2026-09-01', 200_000);   // first day: in, VAT 30,000
        $this->sale('2026-10-31', 300_000);   // last day: in, VAT 45,000
        $this->sale('2026-11-01', 400_000);   // day after: out
        $r = $this->build();

        // field 4 = 30,000 + 45,000 = 75,000 ; field 1 = 230,000 + 345,000 = 575,000
        $this->assertSame(575_000, $r->amount('1'));
        $this->assertSame(75_000, $r->amount('4'));
    }

    public function test_field_10_alone_still_produces_field_11_output_tax(): void
    {
        // R230.00 VAT-inclusive change in use: 23,000 / 115 x 15 = 200 x 15 = 3,000. If field 11 stayed 0 the return would understate VAT by R30.00.
        $r = $this->build([$this->adj('10', 23_000, 'change_in_use')]);
        $this->assertSame(3_000, $r->amount('11'));
        $this->assertSame(3_000, $r->amount('13'));
        $this->assertSame(3_000, $r->amount('20'));
    }
}
