<?php

namespace Tests\Feature\Sales;

use App\Enums\FinanceRole;
use App\Exceptions\Controls\ControlsViolation;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\LedgerSetting;
use App\Models\Controls\LedgerMemberRole;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesDocumentLine;
use App\Models\Shared\Party;
use App\Services\Controls\ApprovalPolicies;
use App\Services\Controls\ApprovalService;
use App\Services\Sales\DocumentDrafts;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\SalesProfiles;
use App\Services\Sales\Support\SalesViolation;
use App\Support\Ledger\LedgerContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\BuildsSales;
use Tests\TestCase;

class IssueTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    private function issue(SalesDocument $draft, array $options = []): array
    {
        return LedgerContext::run($this->ledger, fn () => app(DocumentIssuer::class)->issue($draft, $this->owner, $options));
    }

    private function assertRefused(string $reason, callable $do): SalesViolation
    {
        try {
            $do();
            $this->fail("expected a '{$reason}' refusal");
        } catch (SalesViolation $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());

            return $e;
        }
    }

    // ---- numbering ----

    public function test_a_draft_has_no_number_and_posts_nothing(): void
    {
        $this->setUpSales();
        $draft = $this->draft();

        $this->assertNull($draft->number);
        $this->assertNull($draft->sealed_at);
        $this->assertSame('draft', $draft->status);
        $this->assertSame(0, JournalEntry::query()->forContext($this->ledger)->where('source_type', 'invoice')->count());
    }

    public function test_numbers_are_sequential_with_no_gaps_and_a_failed_issue_hands_its_number_back(): void
    {
        $this->setUpSales();
        $one = $this->issued();

        $bad = $this->draft([$this->line()], ['issue_date' => now()->subMonths(3)->toDateString()]);
        LedgerContext::run($this->ledger, fn () => LedgerSetting::query()->forContext($this->ledger)->update(['books_closed_through' => now()->subMonth()->toDateString()]));
        $this->assertRefused('period_closed', fn () => $this->issue($bad));

        $two = $this->issued();

        $this->assertSame('INV-00001', $one->number);
        $this->assertSame('INV-00002', $two->number, 'the failed issue did not use a number');
        $this->assertNull($bad->refresh()->number);
        $this->assertSame('draft', $bad->status);
    }

    public function test_issuing_twice_returns_the_same_document_and_posts_once(): void
    {
        $this->setUpSales();
        $draft = $this->draft();
        $a = $this->issue($draft);
        $b = $this->issue($draft);

        $this->assertFalse($a['replayed']);
        $this->assertTrue($b['replayed']);
        $this->assertSame($a['document']->number, $b['document']->number);
        $this->assertSame(1, JournalEntry::query()->forContext($this->ledger)->where('source_type', 'invoice')->where('source_id', (string) $draft->id)->count());
        $this->assertSame(115_000, $this->glBalance('ar'));
    }

    public function test_each_kind_has_its_own_series(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $quote = LedgerContext::run($this->ledger, fn () => app(DocumentIssuer::class)->issue(
            app(DocumentDrafts::class)->create($this->ledger, $this->owner, ['kind' => 'quote', 'party_id' => $this->customer->id, 'currency' => 'ZAR', 'issue_date' => now()->toDateString()], [$this->line()]),
            $this->owner,
        )['document']);

        $this->assertSame('INV-00001', $invoice->number);
        $this->assertSame('QT-00001', $quote->number);
        $this->assertNull($quote->gl_entry_id, 'a quote reaches no books');
        $this->assertSame('issued', $quote->status);
        $this->assertSame(115_000, $this->glBalance('ar'), 'only the invoice posted');
    }

    public function test_a_duplicate_number_is_refused_by_the_database(): void
    {
        $this->setUpSales();
        $one = $this->issued();
        $two = $this->draft();

        $this->expectException(QueryException::class);
        DB::table('sales_documents')->where('id', $two->id)->update(['number' => $one->number, 'number_series' => 'invoice', 'sealed_at' => now(), 'status' => 'issued']);
    }

    // ---- the valid tax invoice checklist ----

    public function test_a_vat_registered_business_without_a_vat_number_cannot_issue(): void
    {
        $this->setUpSales();
        LedgerContext::run($this->ledger, fn () => app(SalesProfiles::class)->save($this->ledger, ['vat_number' => null]));

        $e = $this->assertRefused('checklist', fn () => $this->issue($this->draft()));
        $this->assertStringContainsString('VAT number', $e->getMessage());
        $this->assertSame(0, $this->glBalance('ar'));
    }

    public function test_a_full_invoice_over_the_limit_needs_the_customer_address(): void
    {
        $this->setUpSales();
        $noAddress = LedgerContext::run($this->ledger, fn () => Party::create($this->ledger->ownerAttributes() + ['type' => 'customer', 'name' => 'No Address Co', 'status' => 'active']));

        $this->assertRefused('checklist', fn () => $this->issue($this->draft([$this->line('Big job', '1', 1_000_000)], [], $noAddress)));
        // under the R5,000 limit an address is not needed, and the invoice goes through
        $small = $this->issue($this->draft([$this->line('Small job', '1', 100_000)], [], $noAddress));
        $this->assertSame('issued', $small['document']->status);
    }

    public function test_a_zero_rated_line_always_needs_a_full_invoice(): void
    {
        $this->setUpSales();
        $noAddress = LedgerContext::run($this->ledger, fn () => Party::create($this->ledger->ownerAttributes() + ['type' => 'customer', 'name' => 'No Address Co', 'status' => 'active']));

        $this->assertRefused('checklist', fn () => $this->issue($this->draft([$this->line('Bread', '1', 10_000, 'ZA_VAT_ZERO')], [], $noAddress)));
    }

    public function test_the_checklist_is_visible_on_the_preview_with_every_requirement(): void
    {
        $this->setUpSales();
        $preview = LedgerContext::run($this->ledger, fn () => app(DocumentDrafts::class)->preview($this->ledger, [
            'kind' => 'tax_invoice', 'party_id' => $this->customer->id, 'currency' => 'ZAR', 'issue_date' => now()->toDateString(),
        ], [$this->line()]));

        $keys = collect($preview['checklist']['items'])->pluck('key')->all();
        foreach (['supplier_name', 'supplier_address', 'supplier_vat', 'customer_name', 'customer_address', 'date', 'number', 'description', 'quantity', 'amounts'] as $required) {
            $this->assertContains($required, $keys);
        }
        $this->assertTrue($preview['checklist']['valid']);
        $this->assertTrue($preview['checklist']['applies']);
    }

    public function test_a_zero_total_is_refused(): void
    {
        $this->setUpSales();

        $this->assertRefused('zero_total', fn () => $this->issue($this->draft([$this->line('Free', '1', 0)])));
    }

    public function test_a_future_dated_invoice_is_refused(): void
    {
        $this->setUpSales();

        $this->assertRefused('dates', fn () => $this->issue($this->draft([$this->line()], ['issue_date' => now()->addDays(3)->toDateString()])));
    }

    // ---- immutability ----

    public function test_an_issued_document_cannot_be_edited_through_the_model(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();

        foreach (['total_cents' => 1, 'number' => 'INV-9', 'party_id' => 999, 'issue_date' => '2020-01-01'] as $column => $value) {
            try {
                $invoice->forceFill([$column => $value])->save();
                $this->fail("{$column} was changed on an issued document");
            } catch (LogicException) {
                $invoice = SalesDocument::find($invoice->id);
            }
        }

        $this->assertSame('INV-00001', $invoice->number);
        $this->assertSame(115_000, (int) $invoice->total_cents);
    }

    public function test_an_issued_document_cannot_be_edited_through_the_service(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();

        $this->assertRefused('sealed', fn () => LedgerContext::run($this->ledger, fn () => app(DocumentDrafts::class)->update($invoice, $this->owner, ['party_id' => $this->customer->id], [$this->line('Changed', '1', 1)])));
    }

    public function test_the_database_itself_refuses_to_change_or_delete_what_was_issued(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $line = $invoice->lines()->first();

        foreach ([
            fn () => DB::table('sales_documents')->where('id', $invoice->id)->update(['total_cents' => 5, 'net_cents' => 5, 'base_total_cents' => 5, 'base_net_cents' => 5, 'tax_cents' => 0, 'base_tax_cents' => 0]),
            fn () => DB::table('sales_documents')->where('id', $invoice->id)->update(['due_date' => '2030-01-01']),
            fn () => DB::table('sales_documents')->where('id', $invoice->id)->update(['status' => 'draft']),
            fn () => DB::table('sales_documents')->where('id', $invoice->id)->delete(),
            fn () => DB::table('sales_document_lines')->where('id', $line->id)->update(['unit_cents' => 1]),
            fn () => DB::table('sales_document_lines')->where('id', $line->id)->delete(),
        ] as $i => $attempt) {
            try {
                $attempt();
                $this->fail("raw change #{$i} to an issued document went through");
            } catch (QueryException $e) {
                $this->assertStringContainsString('issued sales document', $e->getMessage());
            }
        }

        $this->assertSame(115_000, (int) DB::table('sales_documents')->where('id', $invoice->id)->value('total_cents'));
    }

    public function test_the_lines_of_an_issued_document_cannot_be_replaced(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();

        $this->expectException(LogicException::class);
        SalesDocumentLine::query()->create($invoice->ledgerOwner() + ['document_id' => $invoice->id, 'position' => 9, 'description' => 'sneaky', 'quantity_e4' => 10_000, 'unit_cents' => 1, 'net_cents' => 1, 'tax_cents' => 0, 'gross_cents' => 1]);
    }

    public function test_status_fields_still_move_after_issue(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $invoice->forceFill(['sent_at' => now(), 'internal_note' => 'chased'])->save();

        $this->assertNotNull($invoice->refresh()->sent_at);
    }

    // ---- drafts ----

    public function test_a_draft_can_be_edited_and_discarded_but_never_a_numbered_document(): void
    {
        $this->setUpSales();
        $draft = $this->draft();
        $edited = LedgerContext::run($this->ledger, fn () => app(DocumentDrafts::class)->update($draft, $this->owner, ['party_id' => $this->customer->id, 'issue_date' => now()->toDateString()], [$this->line('Changed', '2', 50_000)]));
        $this->assertSame(115_000, (int) $edited->total_cents);

        LedgerContext::run($this->ledger, fn () => app(DocumentDrafts::class)->discard($edited, $this->owner));
        $this->assertNull(SalesDocument::query()->forContext($this->ledger)->find($draft->id));

        $issued = $this->issued();
        $this->assertRefused('sealed', fn () => LedgerContext::run($this->ledger, fn () => app(DocumentDrafts::class)->discard($issued, $this->owner)));
    }

    public function test_a_double_submitted_form_returns_the_same_draft(): void
    {
        $this->setUpSales();
        $a = $this->draft([$this->line()], ['client_token' => 'form-1']);
        $b = $this->draft([$this->line()], ['client_token' => 'form-1']);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, SalesDocument::query()->forContext($this->ledger)->count());
    }

    public function test_the_browser_cannot_set_a_total(): void
    {
        $this->setUpSales();
        $draft = LedgerContext::run($this->ledger, fn () => app(DocumentDrafts::class)->create($this->ledger, $this->owner,
            ['kind' => 'tax_invoice', 'party_id' => $this->customer->id, 'currency' => 'ZAR', 'issue_date' => now()->toDateString(), 'total_cents' => 1, 'net_cents' => 1, 'tax_cents' => 0],
            [$this->line('Consulting', '1', 100_000) + ['net_cents' => 1, 'tax_cents' => 0, 'gross_cents' => 1]]));

        $this->assertSame(115_000, (int) $draft->total_cents);
        $this->assertSame(100_000, (int) $draft->lines->first()->net_cents);
    }

    public function test_a_customer_from_another_ledger_cannot_be_invoiced(): void
    {
        $this->setUpSales();
        $stranger = $this->user();
        $theirs = Party::create(['user_id' => $stranger->id, 'type' => 'customer', 'name' => 'Theirs', 'status' => 'active']);

        $this->assertRefused('party', fn () => $this->draft([$this->line()], [], $theirs));
    }

    public function test_a_supplier_only_party_is_not_a_customer(): void
    {
        $this->setUpSales();
        $supplier = LedgerContext::run($this->ledger, fn () => Party::create($this->ledger->ownerAttributes() + ['type' => 'supplier', 'name' => 'Only Supplier', 'status' => 'active']));

        $this->assertRefused('party', fn () => $this->draft([$this->line()], [], $supplier));
    }

    // ---- credit limit ----

    public function test_a_credit_limit_warning_must_be_acknowledged(): void
    {
        $this->setUpSales();
        $this->customer->forceFill(['credit_limit_cents' => 200_000])->save();
        $this->issued([$this->line('First', '1', 100_000)]);

        $e = $this->assertRefused('credit_limit', fn () => $this->issue($this->draft([$this->line('Second', '1', 100_000)])));
        $this->assertStringContainsString('credit limit', $e->getMessage());

        $ok = $this->issue($this->draft([$this->line('Second', '1', 100_000)]), ['acknowledge_credit_limit' => true]);
        $this->assertSame('issued', $ok['document']->status);
        $this->assertNotEmpty($ok['warnings']);
    }

    // ---- approvals ----

    private function approvalSetUp(): array
    {
        $this->setUpSales();
        $approver = $this->memberOf($this->company, 'viewer');
        LedgerMemberRole::create(['team_id' => $this->company->id, 'member_user_id' => $approver->id, 'role' => FinanceRole::Approver->value, 'granted_by' => $this->owner->id, 'granted_at' => now()]);
        $bookkeeper = $this->memberOf($this->company, 'editor');
        app(ApprovalPolicies::class)->create($this->owner, $this->ledger, ['subject_type' => 'invoice', 'name' => 'Invoices over R10,000', 'threshold_cents' => 1_000_000, 'currency' => 'ZAR']);

        return [$approver, $bookkeeper];
    }

    public function test_an_invoice_above_the_threshold_waits_for_approval_then_issues_itself(): void
    {
        [$approver, $bookkeeper] = $this->approvalSetUp();
        $draft = $this->draft([$this->line('Big', '1', 2_000_000)]);

        $waiting = LedgerContext::run($this->ledger, fn () => app(DocumentIssuer::class)->issue($draft, $bookkeeper));
        $this->assertTrue($waiting['awaiting_approval']);
        $this->assertSame('awaiting_approval', $waiting['document']->status);
        $this->assertNull($waiting['document']->number, 'no number until it is approved');
        $this->assertSame(0, $this->glBalance('ar'));

        // while it waits it cannot be edited
        $this->assertRefused('sealed', fn () => LedgerContext::run($this->ledger, fn () => app(DocumentDrafts::class)->update($waiting['document'], $this->owner, ['party_id' => $this->customer->id], [$this->line()])));

        $request = app(ApprovalService::class)->latestFor($draft);
        LedgerContext::run($this->ledger, fn () => app(ApprovalService::class)->approve($approver, $request));

        $issued = $draft->refresh();
        $this->assertSame('issued', $issued->status);
        $this->assertSame('INV-00001', $issued->number);
        $this->assertSame($bookkeeper->id, (int) $issued->issued_by, 'issued on behalf of the person who asked');
        $this->assertSame(2_300_000, $this->glBalance('ar'));
        $this->assertBooksBalance();
    }

    public function test_a_rejected_invoice_goes_back_to_draft_with_the_reason(): void
    {
        [$approver, $bookkeeper] = $this->approvalSetUp();
        $draft = $this->draft([$this->line('Big', '1', 2_000_000)]);
        LedgerContext::run($this->ledger, fn () => app(DocumentIssuer::class)->issue($draft, $bookkeeper));

        LedgerContext::run($this->ledger, fn () => app(ApprovalService::class)->reject($approver, app(ApprovalService::class)->latestFor($draft), 'Price is wrong'));

        $draft->refresh();
        $this->assertSame('draft', $draft->status);
        $this->assertStringContainsString('rejected', $draft->approval_note);
        $this->assertNull($draft->number);
    }

    public function test_the_person_who_asked_cannot_approve_their_own_invoice(): void
    {
        [$approver, $bookkeeper] = $this->approvalSetUp();
        $draft = $this->draft([$this->line('Big', '1', 2_000_000)]);
        LedgerContext::run($this->ledger, fn () => app(DocumentIssuer::class)->issue($draft, $bookkeeper));

        $this->expectException(ControlsViolation::class);
        LedgerContext::run($this->ledger, fn () => app(ApprovalService::class)->approve($bookkeeper, app(ApprovalService::class)->latestFor($draft)));
    }

    public function test_below_the_threshold_nothing_waits(): void
    {
        [, $bookkeeper] = $this->approvalSetUp();
        $out = LedgerContext::run($this->ledger, fn () => app(DocumentIssuer::class)->issue($this->draft([$this->line('Small', '1', 100_000)]), $bookkeeper));

        $this->assertFalse($out['awaiting_approval']);
        $this->assertSame('issued', $out['document']->status);
    }
}
