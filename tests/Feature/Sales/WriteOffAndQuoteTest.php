<?php

namespace Tests\Feature\Sales;

use App\Enums\FinanceRole;
use App\Models\Controls\LedgerMemberRole;
use App\Models\Sales\SalesDocument;
use App\Services\Controls\ApprovalPolicies;
use App\Services\Controls\ApprovalService;
use App\Services\Sales\ApplicationEngine;
use App\Services\Sales\DocumentDrafts;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\DocumentLifecycle;
use App\Services\Sales\ReceivablesLedger;
use App\Services\Sales\Support\SalesViolation;
use App\Services\Sales\WriteOffs;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSales;
use Tests\TestCase;

class WriteOffAndQuoteTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    private function reconciles(): bool
    {
        return LedgerContext::run($this->ledger, fn () => app(ReceivablesLedger::class)->reconcile($this->ledger))['ok'];
    }

    // ---- write-offs: the only road to "paid" without money ----

    public function test_an_authorised_write_off_clears_the_balance_posts_to_bad_debts_and_marks_paid(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();

        $out = LedgerContext::run($this->ledger, fn () => app(WriteOffs::class)->request($invoice, 115_000, 'Customer liquidated', $this->owner));

        $this->assertTrue($out['applied']);
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame(115_000, (int) $invoice->written_off_cents);
        $this->assertSame(0, $this->glBalance('ar'));
        $this->assertSame(115_000, $this->glBalance('bad_debts'));
        $this->assertSame(0, LedgerContext::run($this->ledger, fn () => app(ApplicationEngine::class)->openBalance($invoice)));
        $this->assertTrue($this->reconciles());
        $this->assertBooksBalance();
    }

    public function test_a_part_write_off_leaves_the_rest_owing(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        LedgerContext::run($this->ledger, fn () => app(WriteOffs::class)->request($invoice, 15_000, 'Rounding concession', $this->owner));

        $this->assertSame('issued', $invoice->refresh()->status, 'a part write-off alone does not make it paid');
        $this->assertSame(100_000, LedgerContext::run($this->ledger, fn () => app(ApplicationEngine::class)->openBalance($invoice)));
        $this->assertTrue($this->reconciles());
    }

    public function test_a_write_off_needs_a_reason_and_cannot_exceed_what_is_owed(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();

        foreach ([[115_000, ' '], [200_000, 'Too big']] as [$amount, $reason]) {
            try {
                LedgerContext::run($this->ledger, fn () => app(WriteOffs::class)->request($invoice, $amount, $reason, $this->owner));
                $this->fail('accepted');
            } catch (SalesViolation $e) {
                $this->assertContains($e->reason, ['reason', 'over_allocation']);
            }
        }
        $this->assertSame(0, (int) $invoice->refresh()->written_off_cents);
    }

    public function test_someone_who_cannot_approve_cannot_write_off_when_no_rule_covers_it(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $bookkeeper = $this->memberOf($this->company, 'editor');

        $this->expectException(SalesViolation::class);
        $this->expectExceptionMessage('approve invoices');
        LedgerContext::run($this->ledger, fn () => app(WriteOffs::class)->request($invoice, 115_000, 'Bad debt', $bookkeeper));
    }

    public function test_with_an_approval_rule_the_write_off_waits_for_a_second_person(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $bookkeeper = $this->memberOf($this->company, 'editor');
        $approver = $this->memberOf($this->company, 'viewer');
        LedgerMemberRole::create(['team_id' => $this->company->id, 'member_user_id' => $approver->id, 'role' => FinanceRole::Approver->value, 'granted_by' => $this->owner->id, 'granted_at' => now()]);
        app(ApprovalPolicies::class)->create($this->owner, $this->ledger, ['subject_type' => 'invoice', 'name' => 'Invoice actions over R500', 'threshold_cents' => 50_000, 'currency' => 'ZAR']);

        $out = LedgerContext::run($this->ledger, fn () => app(WriteOffs::class)->request($invoice, 115_000, 'Customer gone', $bookkeeper));
        $this->assertFalse($out['applied']);
        $this->assertSame('pending', $out['write_off']->status);
        $this->assertSame(115_000, $this->glBalance('ar'), 'nothing posted while it waits');

        LedgerContext::run($this->ledger, fn () => app(ApprovalService::class)->approve($approver, app(ApprovalService::class)->latestFor($out['write_off'])));

        $this->assertSame('applied', $out['write_off']->refresh()->status);
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame(0, $this->glBalance('ar'));
        $this->assertBooksBalance();
    }

    public function test_a_rejected_write_off_changes_nothing(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $bookkeeper = $this->memberOf($this->company, 'editor');
        $approver = $this->memberOf($this->company, 'viewer');
        LedgerMemberRole::create(['team_id' => $this->company->id, 'member_user_id' => $approver->id, 'role' => FinanceRole::Approver->value, 'granted_by' => $this->owner->id, 'granted_at' => now()]);
        app(ApprovalPolicies::class)->create($this->owner, $this->ledger, ['subject_type' => 'invoice', 'name' => 'Invoice actions over R500', 'threshold_cents' => 50_000, 'currency' => 'ZAR']);

        $out = LedgerContext::run($this->ledger, fn () => app(WriteOffs::class)->request($invoice, 115_000, 'Customer gone', $bookkeeper));
        LedgerContext::run($this->ledger, fn () => app(ApprovalService::class)->reject($approver, app(ApprovalService::class)->latestFor($out['write_off']), 'Chase them first'));

        $this->assertSame('rejected', $out['write_off']->refresh()->status);
        $this->assertSame('issued', $invoice->refresh()->status);
        $this->assertSame(115_000, $this->glBalance('ar'));
    }

    // ---- quotes and pro formas ----

    private function quote(string $kind = 'quote'): SalesDocument
    {
        return LedgerContext::run($this->ledger, fn () => app(DocumentIssuer::class)->issue(
            app(DocumentDrafts::class)->create($this->ledger, $this->owner, ['kind' => $kind, 'party_id' => $this->customer->id, 'currency' => 'ZAR', 'issue_date' => now()->toDateString()], [$this->line('Project', '2', 250_000)]),
            $this->owner,
        )['document']);
    }

    public function test_a_quote_defaults_its_validity_and_never_touches_the_books(): void
    {
        $this->setUpSales();
        $quote = $this->quote();

        $this->assertSame(now()->addDays(30)->toDateString(), $quote->valid_until->toDateString());
        $this->assertNull($quote->due_date);
        $this->assertSame(0, $this->glBalance('ar'));
        $this->assertSame(0, $this->glBalance('vat_output'));
    }

    public function test_a_quote_converts_to_a_draft_invoice_once(): void
    {
        $this->setUpSales();
        $quote = $this->quote();
        $a = LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->convert($quote, $this->owner));
        $b = LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->convert($quote, $this->owner));

        $this->assertSame($a->id, $b->id, 'converting twice does not make a second invoice');
        $this->assertSame('draft', $a->status);
        $this->assertSame('tax_invoice', $a->kind);
        $this->assertSame($quote->id, (int) $a->source_document_id);
        $this->assertSame((int) $quote->total_cents, (int) $a->total_cents);
        $this->assertSame('accepted', $quote->refresh()->status);
        $this->assertSame($a->id, (int) $quote->converted_to_id);
    }

    public function test_a_declined_or_expired_quote_cannot_convert(): void
    {
        $this->setUpSales();
        $quote = $this->quote();
        LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->answerQuote($quote, 'declined', $this->owner));

        $this->expectException(SalesViolation::class);
        LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->convert($quote, $this->owner));
    }

    public function test_quotes_past_their_date_expire_and_an_expired_quote_cannot_be_accepted(): void
    {
        $this->setUpSales();
        $quote = LedgerContext::run($this->ledger, fn () => app(DocumentIssuer::class)->issue(
            app(DocumentDrafts::class)->create($this->ledger, $this->owner, ['kind' => 'quote', 'party_id' => $this->customer->id, 'currency' => 'ZAR', 'issue_date' => now()->subDays(40)->toDateString(), 'valid_until' => now()->subDays(10)->toDateString()], [$this->line()]),
            $this->owner,
        )['document']);

        $count = LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->expireQuotes($this->ledger));
        $again = LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->expireQuotes($this->ledger));

        $this->assertSame(1, $count);
        $this->assertSame(0, $again);
        $this->assertSame('expired', $quote->refresh()->status);

        $this->expectException(SalesViolation::class);
        LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->answerQuote($quote, 'accepted', $this->owner));
    }

    public function test_a_pro_forma_gets_its_own_series_and_converts_too(): void
    {
        $this->setUpSales();
        $pf = $this->quote('pro_forma');
        $this->assertSame('PF-00001', $pf->number);
        $this->assertSame('Pro forma invoice', $pf->title());

        $invoice = LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->convert($pf, $this->owner));
        $this->assertSame('tax_invoice', $invoice->kind);
    }

    public function test_an_invoice_cannot_be_converted(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();

        $this->expectException(SalesViolation::class);
        LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->convert($invoice, $this->owner));
    }
}
