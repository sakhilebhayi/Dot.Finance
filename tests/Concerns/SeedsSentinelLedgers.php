<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Bank\BankMatch;
use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankRule;
use App\Models\Bank\BankStatement;
use App\Models\Bank\BankStatementLine;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Insight;
use App\Models\Integrations\EmallSaleLine;
use App\Models\Intelligence\AssistantDraft;
use App\Models\Intelligence\AssistantMessage;
use App\Models\Intelligence\FinanceInsight;
use App\Models\Intelligence\FinanceNotification;
use App\Models\Planning\Dimension;
use App\Models\Planning\PlanBudget;
use App\Models\Planning\PlanBudgetLine;
use App\Models\Planning\Scenario;
use App\Models\Purchases\Bill;
use App\Models\Purchases\ExpenseClaim;
use App\Models\Purchases\ExpenseClaimLine;
use App\Models\Purchases\PaymentRun;
use App\Models\Purchases\RecurringBill;
use App\Models\Purchases\SupplierBankDetail;
use App\Models\RecurringRule;
use App\Models\Reporting\ReportExport;
use App\Models\Reporting\ReportPreset;
use App\Models\Sales\SalesRecurringTemplate;
use App\Models\Sales\SalesShareLink;
use App\Models\Shared\Attachment;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Models\Tax\TaxAdjustment;
use App\Models\Tax\TaxCorrespondence;
use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxDocument;
use App\Models\Tax\TaxPayrollRun;
use App\Models\Tax\TaxProvisionalPayment;
use App\Models\Tax\TaxReturn;
use App\Models\Tax\TaxReturnAdjustment;
use App\Models\Team;
use App\Models\User;
use App\Services\Accounting\Chart;
use App\Services\Accounting\ChartTemplates;
use App\Services\Accounting\GlBackfill;
use App\Services\Sales\DocumentDrafts;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\ReceiptService;
use App\Services\TransferService;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One row of every kind of ledger record, in a given ledger, named "Sentinel ... <label>" so a leak is readable.
 * Copied from ContextIsolationRoutesTest (which keeps its own private copy) so the R-44 security matrix does not
 * depend on, or edit, that file. Needs $this->me (the signed-in person whose staged import folder is used).
 */
trait SeedsSentinelLedgers
{
    /** One row of every bindable kind in a context, named so a leak is readable. */
    protected function seedContext(string $label, array $owner, ?Team $company = null): array
    {
        $account = Account::factory()->create($owner + ['name' => "Sentinel account {$label}", 'currency' => 'ZAR']);
        $other = Account::factory()->create($owner + ['name' => "Sentinel second {$label}", 'currency' => 'ZAR']);
        $category = Category::factory()->create($owner + ['name' => "Sentinel category {$label}", 'type' => 'expense']);
        $entry = $this->entryOn($account, ['category_id' => $category->id, 'description' => "Sentinel entry {$label}", 'type' => 'expense', 'date' => now()->toDateString()]);
        $budget = Budget::factory()->create($owner + ['category_id' => $category->id, 'currency' => 'ZAR']);
        $rule = RecurringRule::factory()->create($owner + ['account_id' => $account->id, 'description' => "Sentinel rule {$label}"]);
        Insight::create($owner + ['insight' => "Sentinel insight {$label}", 'type' => 'x', 'iso_week' => '2026-W0'.(strlen($label) % 9 + 1)]);

        $party = Party::factory()->create($owner + ['name' => "Sentinel party {$label}"]);
        $attachment = Attachment::create($owner + [
            'attachable_type' => $party->getMorphClass(), 'attachable_id' => $party->id, 'disk' => 'local',
            'path' => 'finance-attachments/sentinel-'.Str::uuid().'.pdf', 'sha256' => hash('sha256', $label), 'original_name' => "Sentinel file {$label}.pdf",
            'mime' => 'application/pdf', 'size' => 10,
        ]);

        // R-44 bank: a statement with a line, an open reconciliation with a match, and a rule.
        $bank = $this->seedBank($label, $owner, $account);

        // A staged import is bound to the context it was staged in.
        $token = (string) Str::uuid();
        Storage::disk('local')->put("finance-imports/{$this->me->id}/{$token}.csv", "date,description,amount\n2026-08-01,Sentinel row {$label},-1.00\n");
        Storage::disk('local')->put("finance-imports/{$this->me->id}/{$token}.json", json_encode(['account_id' => $account->id, 'filename' => 's.csv', 'context' => $this->keyFor($owner)]));

        if ($company === null) {
            app(TransferService::class)->create(User::findOrFail($owner['user_id']), $account, $other, 100, null, now(), "Sentinel transfer {$label}", null);
        } else {
            app(TransferService::class)->create($company->owner, $account, $other, 100, null, now(), "Sentinel transfer {$label}", null);
        }

        // R-44 intelligence and integrations rows, owned by the context's own person (the company's owner for a company).
        $asker = $company === null ? User::findOrFail($owner['user_id']) : $company->owner;
        $message = AssistantMessage::create($owner + ['asker_id' => $asker->id, 'question' => "Sentinel question {$label}", 'intent' => 'spend', 'status' => 'answered', 'answer_text' => 'x', 'answer' => [], 'basis' => 'calculated']);
        $draft = AssistantDraft::create($owner + ['asker_id' => $asker->id, 'message_id' => $message->id, 'kind' => 'categorise', 'payload' => ['items' => []], 'summary' => "Sentinel draft {$label}", 'status' => 'pending', 'expires_at' => now()->addDay()]);
        $insight2 = FinanceInsight::create($owner + ['kind' => 'duplicate', 'subject_key' => 'sentinel', 'period_key' => '2026-11', 'severity' => 'notice', 'title' => "Sentinel finding {$label}", 'body' => 'x', 'evidence' => [], 'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $notification = FinanceNotification::create($owner + ['recipient_id' => $asker->id, 'kind' => 'system', 'title' => "Sentinel notification {$label}", 'body' => 'x', 'dedupe_key' => 'sentinel']);
        $line = EmallSaleLine::create($owner + ['order_item_id' => "sentinel-{$label}", 'order_ref' => "Sentinel line {$label}", 'sold_on' => now()->toDateString(), 'currency' => 'ZAR', 'description' => "Sentinel line {$label}", 'gross_cents' => 100, 'claimed' => [], 'status' => 'staged']);

        // Accounting on for the context, so there are journal entries, a chart and a period to smuggle too.
        $ledger = $company === null ? LedgerContext::personal((int) $owner['user_id']) : LedgerContext::company($company);
        LedgerContext::run($ledger, function () use ($ledger, $company) {
            $backfill = app(GlBackfill::class);
            $backfill->prepare($ledger, 'ZAR', 1, $company === null ? ChartTemplates::PERSONAL : ChartTemplates::COMPANY);
            $backfill->apply($ledger);
            $backfill->enable($ledger);
        });
        [$journalEntry, $glAccount, $period] = LedgerContext::run($ledger, fn () => [
            JournalEntry::query()->forContext($ledger)->orderBy('id')->firstOrFail()->id,
            GlAccount::query()->forContext($ledger)->orderBy('id')->firstOrFail()->id,
            AccountingPeriod::query()->forContext($ledger)->orderBy('id')->firstOrFail()->id,
        ]);

        // Purchases: one of each record the purchases routes bind, in this context.
        $ownerUser = $owner['user_id'] ?? $company->user_id;
        $supplier = Party::factory()->create($owner + ['type' => 'supplier', 'name' => "Sentinel supplier {$label}"]);
        $bill = Bill::create($owner + ['party_id' => $supplier->id, 'bill_date' => '2026-11-01', 'currency' => 'ZAR', 'status' => 'draft', 'kind' => 'bill']);
        $claim = ExpenseClaim::create($owner + ['claimant_user_id' => $ownerUser, 'title' => "Sentinel claim {$label}", 'claim_date' => '2026-11-01', 'currency' => 'ZAR', 'status' => 'draft']);
        $claimLine = ExpenseClaimLine::create($owner + ['expense_claim_id' => $claim->id, 'line_no' => 1, 'spent_on' => '2026-11-01', 'merchant' => 'Sentinel merchant', 'description' => 'x', 'gl_account_id' => $glAccount, 'tax_code' => 'ZA_VAT_ZERO']);
        $run = PaymentRun::create($owner + ['name' => "Sentinel run {$label}", 'due_by' => '2026-11-30', 'paid_on' => '2026-11-30', 'cash_account_id' => $account->id, 'currency' => 'ZAR', 'status' => 'draft', 'idempotency_key' => "sentinel-run-{$label}"]);
        $recurring = RecurringBill::create($owner + ['party_id' => $supplier->id, 'name' => "Sentinel schedule {$label}", 'frequency' => 'monthly', 'start_on' => '2026-11-01', 'next_run_on' => '2026-12-01', 'currency' => 'ZAR', 'template' => []]);
        $detail = SupplierBankDetail::create($owner + ['party_id' => $supplier->id, 'version' => 1, 'status' => 'pending', 'payload' => ['account_number' => '123456'], 'bank_name' => 'Bank', 'account_last4' => '3456', 'account_hash' => hash('sha256', $label), 'change_reason' => 'sentinel', 'requested_at' => now()]);
        $payment = Payment::create($owner + ['direction' => 'out', 'party_id' => $supplier->id, 'cash_account_id' => $account->id, 'amount_cents' => 100, 'currency' => 'ZAR', 'paid_on' => '2026-11-01', 'method' => 'eft', 'idempotency_key' => "sentinel-pay-{$label}", 'status' => 'recorded']);

        // Reporting and planning rows: a plan with a cell, a scenario, a named dimension, a saved view, a finished export.
        $plan = PlanBudget::create($owner + ['name' => "Sentinel plan {$label}", 'fiscal_year' => 2026, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'currency' => 'ZAR', 'status' => 'draft']);
        PlanBudgetLine::create($owner + ['plan_budget_id' => $plan->id, 'gl_account_id' => $glAccount, 'month' => '2026-01-01', 'amount_cents' => 1_000]);
        $scenario = Scenario::create($owner + ['name' => "Sentinel scenario {$label}", 'horizon' => 'weeks_13', 'assumptions' => [['type' => 'collect_faster', 'days' => 5]]]);
        $dimension = Dimension::create($owner + ['ref' => 'department:'.Str::slug($label), 'kind' => 'department', 'name' => "Sentinel dimension {$label}"]);
        $preset = ReportPreset::create($owner + ['name' => "Sentinel view {$label}", 'report_key' => 'profit-loss', 'params' => ['preset' => 'this_year']]);
        $export = ReportExport::create($owner + ['report_key' => 'profit-loss', 'format' => 'csv', 'params' => ['preset' => 'this_year'], 'params_hash' => hash('sha256', $label), 'idempotency_key' => hash('sha256', 'k'.$label), 'status' => 'queued']);

        [$document, $salesPayment, $application, $template, $link] = LedgerContext::run($ledger, function () use ($ledger, $owner, $label, $party, $account, $company) {
            $user = $company === null ? User::findOrFail($owner['user_id']) : $company->owner;
            // a personal chart has receivables and output VAT switched off; invoicing needs them on
            foreach (['ar', 'vat_output', 'sales'] as $key) {
                $gl = app(Chart::class)->system($key, $ledger);
                $gl->is_active || app(Chart::class)->activate($gl);
            }
            $drafts = app(DocumentDrafts::class);
            $header = ['kind' => 'tax_invoice', 'party_id' => $party->id, 'currency' => 'ZAR', 'issue_date' => now()->toDateString(), 'reference' => "Sentinel document {$label}"];
            $draft = $drafts->create($ledger, $user, $header, [['description' => 'Sentinel line', 'quantity' => '1', 'unit_cents' => 10_000]]);
            $issued = app(DocumentIssuer::class)->issue($drafts->create($ledger, $user, ['reference' => "Sentinel issued {$label}"] + $header, [['description' => 'Sentinel line', 'quantity' => '1', 'unit_cents' => 10_000]]), $user)['document'];
            $received = app(ReceiptService::class)->receive($ledger, $user, [
                'party_id' => $party->id, 'cash_account_id' => $account->id, 'amount_cents' => 4_000, 'paid_on' => now()->toDateString(), 'idempotency_key' => 'sentinel-'.$label,
                'allocations' => [['document_id' => $issued->id, 'amount_cents' => 4_000]],
            ]);
            $template = SalesRecurringTemplate::create($ledger->ownerAttributes() + ['party_id' => $party->id, 'name' => "Sentinel template {$label}", 'frequency' => 'monthly', 'every' => 1, 'start_date' => now()->toDateString(), 'next_run_date' => now()->addMonth()->toDateString(), 'currency' => 'ZAR', 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_cents' => 100]], 'created_by' => $user->id]);
            $link = SalesShareLink::create($ledger->ownerAttributes() + ['kind' => 'document', 'document_id' => $issued->id, 'party_id' => $party->id, 'token_hash' => hash('sha256', 'sentinel-'.$label), 'expires_at' => now()->addDay()]);

            return [$draft->id, $received['payment']->id, $received['applications'][0]->id, $template->id, $link->id];
        });

        // R-44 Tax: one of each kind of row, so every tax route can be smuggled a foreign id.
        $deadline = TaxDeadline::create($owner + ['obligation' => 'vat201', 'period_start' => '2026-09-01', 'period_end' => '2026-10-31', 'period_label' => "Sentinel period {$label}", 'base_due_date' => '2026-11-30', 'due_date' => '2026-11-30']);
        $taxReturn = TaxReturn::create($owner + ['kind' => 'vat201', 'period_start' => '2026-09-01', 'period_end' => '2026-10-31', 'period_label' => "Sentinel return {$label}", 'status' => 'draft']);
        $returnAdjustment = TaxReturnAdjustment::create($owner + ['tax_return_id' => $taxReturn->id, 'box_code' => '15', 'kind' => 'other', 'amount_cents' => 100, 'reason' => "Sentinel adjustment {$label}"]);
        $payrollRun = TaxPayrollRun::create($owner + ['period_month' => '2026-10-01', 'source' => 'manual', 'status' => 'draft', 'gross_pay_cents' => 100]);
        $taxAdjustment = TaxAdjustment::create($owner + ['tax_year_end' => '2027-02-28', 'kind' => 'add_back', 'label' => "Sentinel add-back {$label}", 'amount_cents' => 100, 'reason' => 'Sentinel reason']);
        $provisional = TaxProvisionalPayment::create($owner + ['tax_year_end' => '2027-02-28', 'period' => 1, 'due_date' => '2026-08-31']);
        $taxDocument = TaxDocument::create($owner + ['category' => 'other', 'title' => "Sentinel document {$label}", 'retention_until' => '2031-01-01', 'retention_basis' => 'Sentinel']);
        $correspondence = TaxCorrespondence::create($owner + ['direction' => 'received', 'channel' => 'letter', 'subject' => "Sentinel letter {$label}", 'occurred_on' => '2026-10-01']);

        // QA security extras: an inbox receipt (a stored file no record owns yet) and a finished export with its file on disk.
        $receiptPath = 'finance-attachments/sentinel-receipt-'.Str::uuid().'.pdf';
        Storage::disk('local')->put($receiptPath, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $receipt = Attachment::create($owner + ['disk' => 'local', 'path' => $receiptPath, 'sha256' => hash('sha256', 'receipt'.$label), 'original_name' => "Sentinel receipt {$label}.pdf", 'mime' => 'application/pdf', 'size' => 40]);
        $exportPath = 'report-exports/sentinel-'.Str::uuid().'.csv';
        Storage::disk(config('reporting.export_disk'))->put($exportPath, "Sentinel export {$label}\n");
        $doneExport = ReportExport::create($owner + ['report_key' => 'profit-loss', 'format' => 'csv', 'params' => ['preset' => 'last_year'], 'params_hash' => hash('sha256', 'done'.$label), 'idempotency_key' => hash('sha256', 'kd'.$label), 'status' => 'done', 'disk' => config('reporting.export_disk'), 'path' => $exportPath, 'sha256' => hash('sha256', "Sentinel export {$label}\n"), 'size_bytes' => 20, 'row_count' => 1, 'finished_at' => now(), 'expires_at' => now()->addDay()]);

        return $bank + ['supplierParty' => $supplier->id, 'receiptAttachment' => $receipt->id, 'doneExport' => $doneExport->id, 'detailParty' => $supplier->id, 'taxDeadline' => $deadline->id, 'taxReturn' => $taxReturn->id, 'returnAdjustment' => $returnAdjustment->id, 'taxPayrollRun' => $payrollRun->id, 'taxAdjustment' => $taxAdjustment->id, 'provisionalPayment' => $provisional->id, 'taxDocument' => $taxDocument->id, 'taxCorrespondence' => $correspondence->id, 'document' => $document, 'application' => $application, 'template' => $template, 'link' => $link, 'shareToken' => 'notatoken', 'planBudget' => $plan->id, 'dimension' => $dimension->id, 'scenario' => $scenario->id, 'reportPreset' => $preset->id, 'reportExport' => $export->id, 'bill' => $bill->id, 'claim' => $claim->id, 'claimLine' => $claimLine->id, 'run' => $run->id, 'recurring' => $recurring->id, 'detail' => $detail->id, 'payment' => $payment->id, 'glAccount' => $glAccount, 'entry' => $journalEntry, 'period' => $period, 'account' => $account->id, 'transaction' => $entry->id, 'category' => $category->id, 'budget' => $budget->id, 'rule' => $rule->id, 'token' => $token, 'second_account' => $other->id, 'party' => $party->id, 'attachment' => $attachment->id, 'message' => $message->id, 'draft' => $draft->id, 'insight' => $insight2->id, 'notification' => $notification->id, 'line' => $line->id];
    }

    /** @return array<string, int> */
    protected function seedBank(string $label, array $owner, Account $account): array
    {
        $statement = BankStatement::create($owner + ['account_id' => $account->id, 'source' => 'csv', 'original_name' => "Sentinel file {$label}.csv", 'file_sha256' => hash('sha256', $label), 'currency' => 'ZAR', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'line_count' => 1]);
        BankStatementLine::create($owner + ['bank_statement_id' => $statement->id, 'account_id' => $account->id, 'line_no' => 1, 'posted_on' => '2026-10-02', 'amount_cents' => 1234, 'currency' => 'ZAR', 'description' => "Sentinel row {$label}", 'fingerprint' => hash('sha256', 'line'.$label)]);
        $session = BankReconciliation::create($owner + ['account_id' => $account->id, 'status' => 'draft', 'statement_date' => '2026-10-31', 'statement_closing_cents' => 1234, 'currency' => 'ZAR', 'started_at' => now()]);
        $match = BankMatch::create($owner + ['account_id' => $account->id, 'bank_reconciliation_id' => $session->id, 'ref' => (string) Str::uuid(), 'method' => 'manual', 'shape' => 'ledger_only', 'reason' => 'seed', 'amount_cents' => 0, 'line_count' => 0, 'transaction_count' => 0, 'state' => 'active', 'matched_at' => now()]);
        $rule = BankRule::create($owner + ['name' => "Sentinel rule bank {$label}", 'pattern' => "sentinel-{$label}"]);

        return ['statement' => $statement->id, 'reconciliation' => $session->id, 'bankMatch' => $match->id, 'bankRule' => $rule->id];
    }

    protected function keyFor(array $owner): string
    {
        return $owner['team_id'] !== null ? 'company:'.$owner['team_id'] : 'personal:'.$owner['user_id'];
    }
}
