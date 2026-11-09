<?php

namespace Tests\Feature\Acceptance;

use App\Models\FinanceAuditLog;
use App\Models\Tax\TaxReturn;
use App\Services\Controls\ApprovalPolicies;
use App\Services\Controls\ApprovalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owner criterion 30: critical operations maintain audit trails. The operations the owner named (issue, void, payment, journal, period
 * close, approval, tax submission record, role change, export) are done by named people through the real routes, and each leaves a row
 * that says who, what, on which record and when; the rows cannot be changed or removed by the application; the audit page lists them
 * for people allowed to read it and for nobody else, and never prints free text the user typed (descriptions and notes are logged as
 * "changed", not copied).
 */
class Acceptance30_AuditTrailTest extends AcceptanceCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntegration();
        Carbon::setTestNow('2026-12-01 09:00:00');
    }

    /** @return array{0: FinanceAuditLog, 1: int} the newest row of this kind and how many there are */
    private function trail(string $subject, string $action): array
    {
        $rows = $this->auditRows($action, $subject);
        $this->assertGreaterThan(0, $rows->count(), "no audit row: {$subject} / {$action}");

        return [$rows->last(), $rows->count()];
    }

    private function assertWhoWhatWhen(FinanceAuditLog $row, int $actor, string $label = ''): void
    {
        $this->assertSame($actor, (int) $row->actor_user_id, "{$row->subject_type}/{$row->action}: who");
        $this->assertSame('user', $row->actor);
        $this->assertNotNull($row->subject_type);
        $this->assertNotNull($row->subject_id, "{$row->subject_type}/{$row->action}: on which record");
        $this->assertNotNull($row->subject_label);
        $this->assertSame('2026-12-01', $row->created_at->toDateString(), "{$row->subject_type}/{$row->action}: when");
        if ($label !== '') {
            $this->assertStringContainsString($label, (string) $row->subject_label);
        }
    }

    public function test_issuing_voiding_and_paying_documents_are_on_the_record_with_the_person_who_did_them(): void
    {
        $accountant = $this->memberAs('accountant');
        $doc = $this->issueInvoice($this->acme, [['description' => 'Job', 'quantity' => '1', 'unit_cents' => 100_000, 'tax_code' => 'ZA_VAT_STD']], '2026-11-03');
        $this->asMember($accountant)->post(route('sales.documents.void', $doc), ['reason' => 'Wrong customer'])->assertSessionHasNoErrors();
        $this->assertSame('void', $doc->fresh()->status);

        $subjects = $this->auditRows()->groupBy('subject_type')->map(fn ($g) => $g->pluck('action')->unique()->values()->all());
        $this->assertArrayHasKey('sales_document', $subjects->all(), 'documents are audited as their own subject');
        $actions = $subjects['sales_document'];
        $this->assertContains('issued', $actions);
        $this->assertContains('voided', $actions);
        $void = $this->auditRows('voided', 'sales_document')->last();
        $this->assertWhoWhatWhen($void, $accountant->id, 'INV-00001');
        $this->assertSame(['issued', 'void'], [json_decode(json_encode($this->auditRows('voided', 'sales_document')->last()->changes), true)['status']['before'] ?? null, $doc->fresh()->status]);
        $this->assertSame('Wrong customer', $doc->fresh()->void_reason, 'the reason is kept on the voided document itself');
        $this->assertWhoWhatWhen($this->auditRows('issued', 'sales_document')->last(), $this->owner->id, 'INV-00001');

        $second = $this->issueInvoice($this->acme, [['description' => 'Job', 'quantity' => '1', 'unit_cents' => 100_000, 'tax_code' => 'ZA_VAT_STD']], '2026-11-04');
        $this->asMember($this->memberAs('bookkeeper'))->post(route('sales.payments.store'), ['party_id' => $this->acme->id, 'cash_account_id' => $this->bank->id, 'amount' => '1150.00', 'paid_on' => '2026-11-10', 'idempotency_key' => 'audit-pay', 'allocations' => [$second->id => '1150.00']])->assertSessionHasNoErrors();
        [$payment] = $this->trail('payment', 'created');
        $this->assertSame('payment', $payment->subject_type);
        $this->assertNotNull($payment->actor_user_id);
        $this->assertTrue($this->auditRows('created', 'payment_alloc')->count() > 0, 'and its allocation to the invoice');
    }

    public function test_a_supplier_bill_its_approval_and_its_payment_are_on_the_record(): void
    {
        app(ApprovalPolicies::class)->create($this->owner, $this->ledger, ['subject_type' => 'bill', 'name' => 'All bills', 'threshold_cents' => 0, 'currency' => 'ZAR', 'steps_required' => 1]);
        $bookkeeper = $this->memberAs('bookkeeper');
        $approver = $this->memberAs('approver');
        $this->asMember($bookkeeper)->post(route('purchases.bills.store'), ['party_id' => $this->paperCo->id, 'bill_date' => '2026-11-04', 'supplier_reference' => 'AUD-1', 'document_type' => 'tax_invoice', 'submit_now' => '1', 'client_token' => 'aud-1',
            'lines' => [['description' => 'x', 'gl_account_id' => $this->gl('6190')->id, 'quantity' => '1', 'unit_price' => '100.00', 'tax_code' => 'ZA_VAT_STD']]])->assertSessionHasNoErrors();
        $bill = \App\Models\Purchases\Bill::query()->forContext($this->ledger)->where('supplier_reference', 'AUD-1')->firstOrFail();
        $request = app(ApprovalService::class)->latestFor($bill);
        $this->assertWhoWhatWhen($this->auditRows('requested', 'approval_request')->last(), $bookkeeper->id);

        $this->asMember($approver)->post(route('controls.approvals.approve', $request), ['reason' => 'Matches the order'])->assertSessionHasNoErrors();
        $approval = $this->auditRows('approved', 'approval_request')->last();
        $this->assertNotNull($approval, 'the decision is on the record');
        $this->assertWhoWhatWhen($approval, $approver->id);
        $this->assertTrue($this->auditRows(null, 'bill')->count() > 0, 'and the bill itself');

        $this->asOwner()->post(route('purchases.payments.store'), ['party_id' => $this->paperCo->id, 'cash_account_id' => $this->bank->id, 'paid_on' => '2026-11-12', 'idempotency_key' => 'aud-pay', 'pay' => ['bill-'.$bill->id => '115.00']])->assertSessionHasNoErrors();
        $this->assertTrue($this->auditRows('created', 'payment')->where('actor_user_id', $this->owner->id)->count() > 0);
    }

    public function test_a_manual_journal_and_its_reversal_a_period_close_and_a_role_change_are_on_the_record(): void
    {
        $accountant = $this->memberAs('accountant');
        [$a, $b] = \App\Models\Accounting\GlAccount::query()->forContext($this->ledger)->whereIn('class', ['expense', 'income'])->where('is_postable', true)->whereNull('system_key')->limit(2)->pluck('id')->all();
        $this->asMember($accountant)->post(route('accounting.journals.store'), ['entry_date' => '2026-11-10', 'memo' => 'Reclass', 'lines' => [['account_id' => $a, 'debit' => '10.00', 'credit' => ''], ['account_id' => $b, 'debit' => '', 'credit' => '10.00']]])->assertSessionHasNoErrors();
        $entry = \App\Models\Accounting\JournalEntry::query()->forContext($this->ledger)->where('source_type', 'manual')->firstOrFail();
        $this->assertSame($accountant->id, (int) $entry->posted_by_user_id, 'a journal says who posted it, on the entry itself and when');
        $this->assertNotNull($entry->posted_at);
        $this->asMember($accountant)->post(route('accounting.journals.reverse', $entry), ['reason' => 'Wrong accounts'])->assertSessionHasNoErrors();
        $reversal = \App\Models\Accounting\JournalEntry::query()->forContext($this->ledger)->where('source_type', 'reversal')->firstOrFail();
        $this->assertSame($accountant->id, (int) $reversal->posted_by_user_id);
        $this->assertNotNull($reversal->reverses_entry_id, 'and what it reversed');

        $this->asMember($accountant)->post(route('accounting.periods.close-through'), ['through' => '2026-10'])->assertSessionHasNoErrors();
        $closed = $this->auditRows(null, 'period');
        $this->assertGreaterThan(0, $closed->where('actor_user_id', $accountant->id)->count(), 'closing a period is on the record with who closed it');
        $this->assertStringContainsString('2026-10', json_encode($closed->last()->changes).$closed->last()->subject_label);

        $this->asOwner()->put(route('controls.members.update', $accountant->id), ['role' => 'bookkeeper'])->assertSessionHasNoErrors();
        $this->assertWhoWhatWhen($this->auditRows(null, 'member_role')->last(), $this->owner->id);
    }

    public function test_recording_a_tax_submission_and_exporting_a_report_are_on_the_record(): void
    {
        $this->runMonth();
        Carbon::setTestNow('2026-12-01 09:00:00');
        $accountant = $this->memberAs('accountant');
        $this->asMember($accountant)->post(route('tax.vat.start'), ['period_start' => '2026-11-01'])->assertSessionHasNoErrors();
        $return = TaxReturn::query()->forContext($this->ledger)->firstOrFail();
        $this->asMember($accountant)->post(route('tax.returns.prepare', $return))->assertSessionHasNoErrors();
        $this->asMember($accountant)->post(route('tax.returns.submission', $return), ['sars_reference' => 'SARS-AUD-1', 'submitted_on' => '2026-12-01'])->assertSessionHasNoErrors();
        $row = $this->auditRows('submission_recorded', 'tax_return')->last();
        $this->assertWhoWhatWhen($row, $accountant->id);
        $this->assertSame('SARS-AUD-1', $return->fresh()->sars_reference, 'the reference the person typed is kept on the return, and the row says who recorded it and that it was a person, not the software');
        $this->assertSame('2026-12-01', $return->fresh()->submitted_on->toDateString());

        // D-1605: a report export, direct or queued, and the module CSVs leave a record of who took the figures out, and of what
        $before = $this->auditRows('exported', 'report_export')->count();
        $this->asOwner()->post(route('reports.exports.store'), ['report' => 'profit-loss', 'format' => 'csv', 'from' => '2026-11-01', 'to' => '2026-11-30'])->assertOk()->streamedContent();
        $row = $this->auditRows('exported', 'report_export')->last();
        $this->assertSame($before + 1, $this->auditRows('exported', 'report_export')->count());
        $this->assertSame([$this->owner->id, 'user', '2026-12-01'], [(int) $row->actor_user_id, $row->actor, $row->created_at->toDateString()], 'who and when');
        $this->assertStringContainsString('Profit and loss', $row->subject_label, 'what');
        $this->assertSame(['profit-loss', 'csv', '2026-11-01', '2026-11-30'], [$row->changes['report']['after'], $row->changes['format']['after'], $row->changes['from']['after'], $row->changes['to']['after']]);

        $bookkeeper = $this->memberAs('bookkeeper');
        $this->asMember($bookkeeper)->post(route('reports.exports.store'), ['report' => 'balance-sheet', 'format' => 'xlsx', 'to' => '2026-11-30'])->assertOk()->streamedContent();
        $this->assertSame($bookkeeper->id, (int) $this->auditRows('exported', 'report_export')->last()->actor_user_id);

        $count = $this->auditRows('exported', 'report_export')->count();
        $this->asMember($bookkeeper)->get(route('sales.receivables.csv'))->assertOk();
        $this->asMember($bookkeeper)->get(route('purchases.ap.index', ['format' => 'csv']))->assertOk()->streamedContent();
        $this->asMember($bookkeeper)->get(route('sales.customers.statement.csv', $this->acme))->assertOk();
        $this->asMember($bookkeeper)->get(route('purchases.suppliers.statement', [$this->paperCo, 'format' => 'csv']))->assertOk()->streamedContent();
        $this->asMember($accountant)->get(route('tax.returns.csv', $return))->assertOk();
        $this->asMember($accountant)->get(route('tax.income.csv'))->assertOk();
        $this->asMember($accountant)->get(route('controls.access-review.export'))->assertOk();
        $this->assertSame($count + 7, $this->auditRows('exported', 'report_export')->count(), 'every CSV leaves a row');

        // a request that was refused leaves none
        $count = $this->auditRows('exported', 'report_export')->count();
        $this->asMember($this->memberAs('viewer'))->post(route('reports.exports.store'), ['report' => 'profit-loss', 'format' => 'csv', 'from' => '2026-11-01', 'to' => '2026-11-30'])->assertForbidden();
        $this->assertSame($count, $this->auditRows('exported', 'report_export')->count());
    }

    public function test_the_audit_page_lists_them_for_those_who_may_read_it_and_the_rows_cannot_be_changed(): void
    {
        $this->runMonth();
        $page = $this->asOwner()->get(route('audit.index'))->assertOk()->getContent();
        $text = html_entity_decode(strip_tags($page), ENT_QUOTES);
        $this->assertStringContainsString('bank statement', $text, 'the audit page renders a bank statement import row (D-1603: it used to fail with a server error)');
        $this->assertStringContainsString('november.csv', $text);
        foreach (['invoice', 'approval request', 'payment'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        $this->asMember($this->memberAs('accountant'))->get(route('audit.index'))->assertOk();
        $this->asMember($this->memberAs('viewer'))->get(route('audit.index'))->assertForbidden();
        $this->asMember($this->memberAs('bookkeeper'))->get(route('audit.index'))->assertForbidden();

        $row = FinanceAuditLog::withoutGlobalScopes()->where('team_id', $this->company->id)->firstOrFail();
        foreach ([fn () => $row->update(['action' => 'edited']), fn () => $row->delete(), fn () => FinanceAuditLog::query()->forContext($this->ledger)->update(['action' => 'x']), fn () => FinanceAuditLog::query()->forContext($this->ledger)->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('an audit row was changed');
            } catch (\Throwable $e) {
                $this->assertStringContainsString('append-only', strtolower($e->getMessage()));
            }
        }
        $this->assertSame('created', FinanceAuditLog::withoutGlobalScopes()->whereKey($row->id)->value('action'), 'nothing changed in the database');
    }

    public function test_free_text_typed_by_users_is_never_copied_into_the_log(): void
    {
        $this->asOwner()->post(route('categories.store'), ['name' => 'Travel', 'type' => 'expense']);
        $category = \App\Models\Category::query()->forContext($this->ledger)->firstOrFail();
        $this->asOwner()->post(route('transactions.store'), ['account_id' => $this->bank->id, 'category_id' => $category->id, 'description' => 'SECRET-DESCRIPTION-9917 for the minister', 'notes' => 'SECRET-NOTE-4471', 'amount' => '10.00', 'type' => 'expense', 'date' => '2026-11-12'])->assertSessionHasNoErrors();
        $dump = DB::table('finance_audit_log')->where('team_id', $this->company->id)->get()->map(fn ($r) => json_encode($r))->implode("\n");
        $this->assertStringNotContainsString('SECRET-DESCRIPTION-9917', $dump);
        $this->assertStringNotContainsString('SECRET-NOTE-4471', $dump);
        $tx = DB::table('finance_audit_log')->where('team_id', $this->company->id)->where('subject_type', 'transaction')->where('action', 'created')->first();
        $this->assertSame(['changed' => true], json_decode($tx->changes, true)['description'], 'only the fact that it changed is kept');
        $this->assertSame(['changed' => true], json_decode($tx->changes, true)['notes']);
    }

    public function test_another_ledgers_audit_rows_are_never_shown(): void
    {
        $this->issueInvoice($this->acme, [['description' => 'Job', 'quantity' => '1', 'unit_cents' => 100_000, 'tax_code' => 'ZA_VAT_STD']], '2026-11-03');
        $other = $this->user();
        $company = $this->company($other, 'Elsewhere');
        $text = html_entity_decode(strip_tags($this->inCompany($other, $company)->get(route('audit.index'))->assertOk()->getContent()), ENT_QUOTES);
        $this->assertStringNotContainsString('INV-00001', $text);
        $this->assertStringNotContainsString('Acme Retail', $text);
    }
}
