<?php

namespace Tests\Feature\Sales;

use App\Mail\SalesDocumentMail;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesDocumentEvent;
use App\Models\Sales\SalesShareLink;
use App\Models\Shared\Party;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\DocumentLifecycle;
use App\Services\Sales\DocumentMailer;
use App\Services\Sales\DocumentRenderer;
use App\Services\Sales\ReceiptService;
use App\Services\Sales\SalesProfiles;
use App\Services\Sales\ShareLinks;
use App\Services\Sales\Statements;
use App\Services\Sales\Support\SalesViolation;
use App\Services\Shared\FxService;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsSales;
use Tests\TestCase;

class ShareMailStatementTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    // ---- customer links ----

    private function link(SalesDocument $doc): array
    {
        return LedgerContext::run($this->ledger, fn () => app(ShareLinks::class)->forDocument($doc, $this->owner));
    }

    public function test_a_link_opens_a_read_only_copy_without_signing_in_and_only_the_hash_is_stored(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $made = $this->link($invoice);

        $this->assertNotContains($made['token'], DB::table('sales_share_links')->pluck('token_hash')->all());
        $this->assertSame(hash('sha256', $made['token']), DB::table('sales_share_links')->value('token_hash'));

        $response = $this->get(route('sales.share.show', ['shareToken' => $made['token']]));
        $response->assertOk()->assertSee('INV-00001')->assertSee('Seller (Pty) Ltd')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringNotContainsString('Void', $response->getContent());
        $this->assertStringNotContainsString('<form', $response->getContent(), 'read-only: nothing to submit');

        $this->assertNotNull($invoice->refresh()->viewed_at);
        $this->assertSame(1, SalesShareLink::query()->forContext($this->ledger)->first()->views);
        $this->assertTrue(SalesDocumentEvent::query()->forContext($this->ledger)->where('kind', 'viewed')->exists());

        $pdf = $this->get(route('sales.share.pdf', ['shareToken' => $made['token']]));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_unknown_expired_and_revoked_links_are_all_the_same_404(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $made = $this->link($invoice);

        $this->get(route('sales.share.show', ['shareToken' => 'x']))->assertNotFound();
        $this->get(route('sales.share.show', ['shareToken' => str_repeat('a', 40)]))->assertNotFound();

        DB::table('sales_share_links')->update(['expires_at' => now()->subMinute()]);
        $this->get(route('sales.share.show', ['shareToken' => $made['token']]))->assertNotFound();

        DB::table('sales_share_links')->update(['expires_at' => now()->addDay()]);
        $this->get(route('sales.share.show', ['shareToken' => $made['token']]))->assertOk();
        LedgerContext::run($this->ledger, fn () => app(ShareLinks::class)->revoke(SalesShareLink::query()->forContext($this->ledger)->first(), $this->owner));
        $this->get(route('sales.share.show', ['shareToken' => $made['token']]))->assertNotFound();
        $this->get(route('sales.share.pdf', ['shareToken' => $made['token']]))->assertNotFound();
    }

    public function test_a_link_cannot_be_made_for_a_draft_and_is_throttled(): void
    {
        $this->setUpSales();
        $draft = $this->draft();

        try {
            LedgerContext::run($this->ledger, fn () => app(ShareLinks::class)->forDocument($draft, $this->owner));
            $this->fail('shared a draft');
        } catch (SalesViolation $e) {
            $this->assertSame('sealed', $e->reason);
        }

        config(['sales.share.throttle_per_minute' => 3]);
        for ($i = 0; $i < 3; $i++) {
            $this->get(route('sales.share.show', ['shareToken' => 'nope'.$i]))->assertNotFound();
        }
        // the same token from the same address is counted together
        $codes = collect(range(1, 6))->map(fn () => $this->get(route('sales.share.show', ['shareToken' => 'same-token']))->getStatusCode());
        $this->assertContains(429, $codes->all());
    }

    public function test_a_voided_invoice_is_still_shown_but_marked_void(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $made = $this->link($invoice);
        LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->void($invoice, 'Wrong customer', $this->owner));

        $this->get(route('sales.share.show', ['shareToken' => $made['token']]))->assertOk()->assertSee('VOID');
    }

    // ---- e-mail ----

    public function test_sending_hands_the_pdf_to_the_mailer_and_records_only_that(): void
    {
        $this->setUpSales();
        Mail::fake();
        $invoice = $this->issued();

        LedgerContext::run($this->ledger, fn () => app(DocumentMailer::class)->send($invoice, $this->owner, 'ap@acme.test', 'Thanks for the work.'));

        Mail::assertSent(SalesDocumentMail::class, fn ($m) => $m->hasTo('ap@acme.test') && $m->subjectLine === 'Tax invoice INV-00001 from Seller' && str_starts_with($m->pdf, '%PDF') && $m->viewUrl !== null);
        $event = SalesDocumentEvent::query()->forContext($this->ledger)->where('kind', 'emailed')->firstOrFail();
        $this->assertSame('handed to the mailer', $event->meta['state']);
        $this->assertNotNull($invoice->refresh()->sent_at);
        $this->assertFalse(SalesDocumentEvent::query()->forContext($this->ledger)->whereIn('kind', ['delivered', 'read', 'opened'])->exists(), 'delivery is never claimed');
    }

    public function test_a_failing_mailer_sends_nothing_and_says_so(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        try {
            LedgerContext::run($this->ledger, fn () => app(DocumentMailer::class)->send($invoice, $this->owner, 'ap@acme.test'));
            $this->fail('sent');
        } catch (SalesViolation $e) {
            $this->assertSame('mail_failed', $e->reason);
        }
        $this->assertNull($invoice->refresh()->sent_at);
        $this->assertTrue(SalesDocumentEvent::query()->forContext($this->ledger)->where('kind', 'email_failed')->exists());
    }

    public function test_only_a_valid_address_on_an_issued_document_can_be_mailed(): void
    {
        $this->setUpSales();
        Mail::fake();
        $draft = $this->draft();
        $invoice = $this->issued();

        foreach ([[$draft, 'a@b.test'], [$invoice, 'not-an-email']] as [$doc, $to]) {
            try {
                LedgerContext::run($this->ledger, fn () => app(DocumentMailer::class)->send($doc, $this->owner, $to));
                $this->fail('sent');
            } catch (SalesViolation) {
                $this->assertTrue(true);
            }
        }
        Mail::assertNothingSent();
    }

    // ---- rendering ----

    public function test_a_document_is_drawn_from_what_was_issued_not_from_what_the_customer_is_now(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $this->customer->forceFill(['name' => 'Renamed Co', 'legal_name' => 'Renamed Co Ltd', 'tax_number' => null])->save();
        LedgerContext::run($this->ledger, fn () => app(SalesProfiles::class)->save($this->ledger, ['legal_name' => 'Someone Else Ltd']));

        $html = LedgerContext::run($this->ledger, fn () => app(DocumentRenderer::class)->html($invoice));

        $this->assertStringContainsString('Acme Retail (Pty) Ltd', $html);
        $this->assertStringContainsString('4987654321', $html);
        $this->assertStringContainsString('Seller (Pty) Ltd', $html);
        $this->assertStringNotContainsString('Renamed Co', $html);
        $this->assertStringNotContainsString('Someone Else', $html);
    }

    public function test_the_printed_invoice_carries_every_required_tax_invoice_item(): void
    {
        $this->setUpSales();
        $invoice = $this->issued([$this->line('Consulting', '2', 100_000)]);
        $html = LedgerContext::run($this->ledger, fn () => app(DocumentRenderer::class)->html($invoice));

        foreach (['Tax invoice', 'Seller (Pty) Ltd', '1 Long Street', 'VAT number 4123456789', 'Acme Retail (Pty) Ltd', '5 Short Road', 'INV-00001', now()->format('j F Y'), 'Consulting', '2', '1,000.00', '2,000.00', '300.00', '2,300.00', 'VAT summary', '15%'] as $needle) {
            $this->assertStringContainsString($needle, $html, "missing on the printed invoice: {$needle}");
        }
    }

    public function test_a_draft_is_marked_draft_and_a_credit_note_names_the_invoice_it_corrects(): void
    {
        $this->setUpSales();
        $draftHtml = LedgerContext::run($this->ledger, fn () => app(DocumentRenderer::class)->html($this->draft()));
        $this->assertStringContainsString('DRAFT', $draftHtml);

        $invoice = $this->issued();
        $credit = LedgerContext::run($this->ledger, function () use ($invoice) {
            $d = app(DocumentLifecycle::class)->draftCreditNote($invoice, $this->owner, 'Returned');

            return app(DocumentIssuer::class)->issue($d, $this->owner)['document'];
        });
        $html = LedgerContext::run($this->ledger, fn () => app(DocumentRenderer::class)->html($credit));
        $this->assertStringContainsString('Credit note', $html);
        $this->assertStringContainsString('INV-00001', $html);
        $this->assertStringContainsString('Returned', $html);
    }

    // ---- statements ----

    public function test_a_statement_opens_with_the_balance_brought_forward_and_closes_at_what_is_owed(): void
    {
        $this->setUpSales();
        $old = $this->issued([$this->line('Old job', '1', 100_000)], ['issue_date' => now()->subMonths(5)->toDateString()]);      // 1,150 before the period
        $new = $this->issued([$this->line('New job', '1', 200_000)], ['issue_date' => now()->subDays(10)->toDateString()]);       // 2,300 in the period
        LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->receive($this->ledger, $this->owner, [
            'party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => 50_000, 'paid_on' => now()->subDays(5)->toDateString(), 'idempotency_key' => 'p',
            'allocations' => [['document_id' => $old->id, 'amount_cents' => 50_000]],
        ]));

        $s = LedgerContext::run($this->ledger, fn () => app(Statements::class)->build($this->ledger, $this->customer, CarbonImmutable::today()->subMonth(), CarbonImmutable::today()));
        $section = $s['sections'][0];

        $this->assertSame('ZAR', $section['currency']);
        $this->assertSame(115_000, $section['opening_cents']);
        $this->assertCount(2, $section['lines']);
        $this->assertSame(345_000, $section['lines'][0]['balance']);
        $this->assertSame(295_000, $section['closing_cents']);
        $this->assertSame(295_000, $section['balance_now_cents']);
        $this->assertSame(295_000, array_sum(array_column($section['open'], 'open_cents')), 'what is open adds up to the closing balance');
        $this->assertSame(295_000, $this->glBalance('ar'));
    }

    public function test_the_statement_csv_cannot_run_a_formula_in_a_spreadsheet(): void
    {
        $this->setUpSales();
        $evil = Party::create($this->ledger->ownerAttributes() + ['type' => 'customer', 'name' => '=HYPERLINK("http://evil.test")', 'status' => 'active', 'billing_address' => ['line1' => '1 X']]);
        $this->issued([$this->line()], [], $evil);

        $rows = LedgerContext::run($this->ledger, fn () => app(Statements::class)->csv(app(Statements::class)->build($this->ledger, $evil, CarbonImmutable::today()->subMonth(), CarbonImmutable::today())));

        $this->assertSame("'=HYPERLINK(\"http://evil.test\")", $rows[0][1]);
    }

    public function test_the_statement_page_renders_and_a_foreign_currency_gets_its_own_section(): void
    {
        $this->setUpSales();
        $this->issued();
        LedgerContext::run($this->ledger, fn () => app(FxService::class)->record('USD', 'ZAR', 1_850_000_000, now()->toDateString()));
        $this->issued([$this->line('Licence', '1', 100_000, 'ZA_VAT_ZERO_EXPORT')], ['currency' => 'USD']);

        $s = LedgerContext::run($this->ledger, fn () => app(Statements::class)->build($this->ledger, $this->customer, CarbonImmutable::today()->subMonth(), CarbonImmutable::today()));
        $this->assertEqualsCanonicalizing(['ZAR', 'USD'], array_column($s['sections'], 'currency'));

        $this->inCompany($this->owner, $this->company)->get(route('sales.customers.statement', $this->customer))->assertOk()->assertSee('Amounts in USD')->assertSee('Amounts in ZAR');
    }
}
