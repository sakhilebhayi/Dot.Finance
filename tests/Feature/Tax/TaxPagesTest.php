<?php

namespace Tests\Feature\Tax;

use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxPayrollRun;
use App\Models\Tax\TaxReturn;
use App\Services\Tax\DeadlineGenerator;
use App\Services\Tax\TaxReturnService;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaxFixtures;
use Tests\TestCase;

class TaxPagesTest extends TestCase
{
    use BuildsTaxFixtures;
    use RefreshDatabase;

    private TaxReturn $vat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->taxLedger();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00'));
        $profile = $this->vatProfile();
        $this->sale('2026-09-10', 100_000);
        $this->purchase('2026-09-15', 40_000);
        LedgerContext::run($this->tax, fn () => app(DeadlineGenerator::class)->generate($this->tax, $profile, CarbonImmutable::today()));
        $this->vat = LedgerContext::run($this->tax, fn () => app(TaxReturnService::class)->start($this->tax, 'vat201', CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-31'), 'Sep 2026 to Oct 2026 (category B)', $profile));
    }

    public function test_every_tax_page_opens_for_the_owner(): void
    {
        $run = TaxPayrollRun::query()->create($this->tax->ownerAttributes() + ['period_month' => '2026-10-01', 'source' => 'manual', 'status' => 'draft', 'gross_pay_cents' => 3_000_000, 'paye_cents' => 400_000, 'uif_employee_cents' => 30_000, 'uif_employer_cents' => 30_000, 'sdl_cents' => 30_000, 'employee_count' => 3]);
        $deadline = TaxDeadline::query()->forContext($this->tax)->first();
        $this->assertNotNull($deadline);

        $owner = $this->people['owner'];
        $this->withoutExceptionHandling();
        foreach ([
            route('tax.index'), route('tax.profile.edit'), route('tax.obligations'), route('tax.calendar.index'), route('tax.calendar.show', $deadline),
            route('tax.vat.index'), route('tax.vat.review'), route('tax.returns.show', $this->vat), route('tax.returns.workpaper', $this->vat), route('tax.returns.csv', $this->vat),
            route('tax.emp201.index'), route('tax.payroll.index'), route('tax.payroll.show', $run), route('tax.income.index'), route('tax.income.workpaper'), route('tax.income.csv'),
            route('tax.provisional.index'), route('tax.documents.index'), route('tax.explain.index'), route('tax.pack.index'),
        ] as $url) {
            $response = $this->inCompany($owner, $this->acme)->get($url);
            $this->assertSame(200, $response->getStatusCode(), "{$url} answered {$response->getStatusCode()}");
        }
    }

    public function test_the_tax_pages_use_plain_labels_and_keep_internal_words_behind_show_working(): void
    {
        $owner = $this->people['owner'];

        $overview = $this->inCompany($owner, $this->acme)->get(route('tax.index'))->assertOk();
        $overview->assertSee('VAT return (VAT201)')->assertSee('Applies to you')->assertDontSee('category B')->assertDontSee('reminder record(s)');
        $this->assertStringNotContainsString('str_replace', $overview->getContent());

        $return = $this->inCompany($owner, $this->acme)->get(route('tax.returns.show', $this->vat))->assertOk();
        $return->assertSee('Sep 2026 to Oct 2026 (two-month period)')->assertDontSee('(category B)')->assertSee('Output tax (VAT you charged)')->assertSee('Input tax (VAT you paid)');

        // Every amount in the box table and the totals carries one of the four basis tags.
        $html = $return->getContent();
        $this->assertGreaterThanOrEqual(substr_count($html, 'class="tax-box"'), substr_count($html, 'Calculated') + substr_count($html, 'You entered'), 'each field shows how its amount came to be');

        $obligations = $this->inCompany($owner, $this->acme)->get(route('tax.obligations'))->assertOk();
        $obligations->assertSee('Show working');
        // Internal identifiers may appear, but only inside a "Show working" disclosure.
        $outside = preg_replace('#<details.*?</details>#s', '', $obligations->getContent());
        $this->assertDoesNotMatchRegularExpression('/tax_rules#\d+|tax_rates#\d+|ZA_VAT_STD/', $outside);
    }

    public function test_the_working_paper_is_a_standalone_light_page_with_print_rules(): void
    {
        $paper = $this->inCompany($this->people['owner'], $this->acme)->get(route('tax.returns.workpaper', $this->vat))->assertOk();
        $html = $paper->getContent();

        $this->assertStringContainsString('class="tax-print"', $html);
        $this->assertStringContainsString('data-theme="light"', $html);
        $this->assertSame(1, substr_count($html, '<h1>'), 'one title');
        $this->assertStringContainsString('PREPARED, NOT SUBMITTED', $html, 'the banner a printed copy must carry');
        $this->assertStringContainsString('<caption>', $html);
        $this->assertStringNotContainsString('fx-side', $html, 'no app chrome on the paper');
    }
}
