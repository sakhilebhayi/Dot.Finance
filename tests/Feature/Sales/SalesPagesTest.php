<?php

namespace Tests\Feature\Sales;

use App\Models\Sales\SalesDocument;
use App\Models\Shared\Payment;
use App\Services\Sales\DocumentDrafts;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\DocumentLifecycle;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSales;
use Tests\TestCase;

/** Every Sales page renders for the owner, and the main flows work through the real HTTP routes. */
class SalesPagesTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    private function as(): static
    {
        return $this->inCompany($this->owner, $this->company);
    }

    private function invoiceBody(array $more = []): array
    {
        return $more + [
            'kind' => 'tax_invoice', 'party_id' => $this->customer->id, 'currency' => 'ZAR', 'issue_date' => now()->toDateString(), 'client_token' => 'tok-'.uniqid(),
            'lines' => [['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '1000.00', 'tax_code' => 'ZA_VAT_STD'], ['description' => '', 'unit_price' => '']],
        ];
    }

    public function test_the_whole_flow_through_the_routes(): void
    {
        $this->setUpSales();

        $this->as()->get(route('sales.invoices.create'))->assertOk()->assertSee('Worked out by the server')->assertSee('Is it a valid tax invoice?');
        $response = $this->as()->post(route('sales.documents.store'), $this->invoiceBody());
        $document = SalesDocument::query()->forContext($this->ledger)->firstOrFail();
        $response->assertRedirect(route('sales.documents.show', $document));
        $this->assertSame(230_000, (int) $document->total_cents, 'the server worked it out; the form sent no total');

        $this->as()->get(route('sales.documents.show', $document))->assertOk()->assertSee('Draft')->assertSee('Issue tax invoice');
        $this->as()->get(route('sales.documents.edit', $document))->assertOk()->assertSee('Consulting');

        $this->as()->post(route('sales.documents.issue', $document))->assertSessionHasNoErrors();
        $document->refresh();
        $this->assertSame('INV-00001', $document->number);
        $this->as()->get(route('sales.documents.show', $document))->assertOk()->assertSee('INV-00001')->assertSee('Record a payment')->assertSee('Valid tax invoice');
        $this->as()->get(route('sales.documents.print', $document))->assertOk()->assertSee('Tax invoice')->assertSee('Seller (Pty) Ltd')->assertSee('4123456789');
        $pdf = $this->as()->get(route('sales.documents.pdf', $document));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->as()->get(route('sales.payments.create', ['document' => $document->id]))->assertOk()->assertSee('INV-00001');
        $this->as()->post(route('sales.payments.store'), [
            'party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount' => '2300.00', 'paid_on' => now()->toDateString(), 'idempotency_key' => 'http-1',
            'allocations' => [$document->id => '2300.00'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('paid', $document->refresh()->status);

        $payment = Payment::query()->forContext($this->ledger)->firstOrFail();
        $this->as()->get(route('sales.payments.show', $payment))->assertOk()->assertSee('INV-00001');
        $this->as()->get(route('sales.payments.index'))->assertOk();
    }

    public function test_listing_and_reporting_pages_render(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $draft = $this->draft();
        $quote = LedgerContext::run($this->ledger, fn () => app(DocumentIssuer::class)->issue(app(DocumentDrafts::class)->create($this->ledger, $this->owner, ['kind' => 'quote', 'party_id' => $this->customer->id, 'currency' => 'ZAR', 'issue_date' => now()->toDateString()], [$this->line()]), $this->owner)['document']);
        $credit = LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->draftCreditNote($invoice, $this->owner, 'Returned'));

        foreach ([
            route('sales.invoices.index'), route('sales.invoices.index', ['status' => 'overdue']), route('sales.invoices.index', ['q' => 'INV', 'status' => 'open']), route('sales.quotes.index'), route('sales.credit-notes.index'),
            route('sales.quotes.create'), route('sales.documents.show', $quote), route('sales.documents.show', $draft), route('sales.documents.edit', $credit), route('sales.documents.show', $credit),
            route('sales.customers.index'), route('sales.customers.show', $this->customer), route('sales.customers.statement', $this->customer), route('sales.receivables.index'), route('sales.receivables.index', ['basis' => 'invoice']),
            route('sales.recurring.index'), route('sales.recurring.create'), route('sales.profile.edit'), route('sales.reminders.index'), route('sales.payments.create'), route('dashboard'),
        ] as $url) {
            $this->as()->get($url)->assertOk();
        }

        $csv = $this->as()->get(route('sales.receivables.csv'));
        $csv->assertOk();
        $this->assertStringContainsString('Receivables ageing', $csv->getContent());
        $this->as()->get(route('sales.customers.statement.csv', $this->customer))->assertOk();
        $this->assertStringStartsWith('%PDF', $this->as()->get(route('sales.customers.statement.pdf', $this->customer))->getContent());
    }

    public function test_the_dashboard_panel_shows_what_is_owed_with_the_proof(): void
    {
        $this->setUpSales();
        $this->issued();

        $this->as()->get(route('dashboard'))->assertOk()->assertSee('Owed to you')->assertSee('Agrees with the books');
    }

    public function test_the_calculation_endpoint_returns_server_figures_and_the_checklist(): void
    {
        $this->setUpSales();

        $json = $this->as()->postJson(route('sales.calculate'), $this->invoiceBody())->assertOk()->json();

        $this->assertTrue($json['ok']);
        $this->assertSame('ZAR 2,300.00', $json['totals']['total']);
        $this->assertSame('ZAR 300.00', $json['totals']['tax']);
        $this->assertSame('ZAR 2,300.00', $json['lines'][0]['gross']);
        $this->assertTrue($json['checklist']['valid']);

        $bad = $this->as()->postJson(route('sales.calculate'), $this->invoiceBody(['currency' => 'USD']))->assertStatus(422)->json();
        $this->assertStringContainsString('exchange rate', $bad['message']);
    }

    public function test_the_calculation_ignores_any_total_the_browser_sends(): void
    {
        $this->setUpSales();
        $body = $this->invoiceBody(['total_cents' => 1, 'totals' => ['total' => '0.01']]);

        $json = $this->as()->postJson(route('sales.calculate'), $body)->assertOk()->json();
        $this->assertSame('ZAR 2,300.00', $json['totals']['total']);
    }

    public function test_the_editor_js_is_built_and_the_page_has_no_inline_script_or_style(): void
    {
        $this->setUpSales();
        $html = $this->as()->get(route('sales.invoices.create'))->getContent();

        $this->assertStringNotContainsString(' style=', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*(nonce|src))/i', $html);
        $this->assertStringContainsString('data-calc-url', $html);
    }
}
