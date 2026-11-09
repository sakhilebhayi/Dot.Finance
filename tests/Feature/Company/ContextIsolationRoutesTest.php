<?php

namespace Tests\Feature\Company;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * The regression net for the whole feature. It walks the application's own route
 * list, so a route added later without a matching entry here fails the suite,
 * and it smuggles a row id from a context the caller is NOT in into every route
 * parameter. Each attempt must be a 404 and must leave the database byte-for-byte
 * as it was.
 *
 * Callers are tried in the personal ledger, in a company they belong to, and in a
 * company they do not belong to at all; the foreign rows come from the personal
 * ledger, from each company and from another person.
 */
class ContextIsolationRoutesTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private const LEDGER_TABLES = [
        'accounts', 'categories', 'budgets', 'transactions', 'recurring_rules', 'finance_audit_log', 'ai_insights', 'parties', 'attachments',
        // R-44 accounting tables
        'ledger_settings', 'gl_accounts', 'accounting_periods', 'journal_entries', 'journal_lines', 'gl_backfill_runs',
        // R-44 intelligence and integrations tables
        'assistant_messages', 'assistant_drafts', 'finance_insights', 'finance_notifications', 'notification_preferences', 'integration_inbound_events', 'emall_sale_lines',
        // R-44 bank tables
        'bank_statements', 'bank_statement_lines', 'bank_reconciliations', 'bank_matches', 'bank_reconciliation_events', 'bank_adjustments', 'bank_rules',
        // R-44 purchases tables
        'payments', 'payment_allocations', 'purchase_settings', 'supplier_profiles', 'supplier_bank_details', 'bills', 'bill_lines', 'expense_claims', 'expense_claim_lines', 'recurring_bills', 'payment_runs', 'payment_run_items',
        // R-44 reporting and planning
        'report_exports', 'report_presets', 'dimensions', 'plan_budgets', 'plan_budget_lines', 'plan_scenarios',
        // R-44 Sales
        'sales_profiles', 'sales_party_prefs', 'sales_documents', 'sales_document_lines', 'sales_applications', 'sales_refunds', 'sales_write_offs',
        'sales_document_events', 'sales_share_links', 'sales_transaction_links', 'sales_reminders', 'sales_recurring_templates',
        // R-44 tax tables
        'tax_profiles', 'tax_deadlines', 'tax_reminders', 'tax_returns', 'tax_return_adjustments', 'tax_review_resolutions', 'tax_adjustments',
        'tax_income_estimates', 'tax_provisional_payments', 'tax_payroll_runs', 'tax_payroll_lines', 'tax_documents', 'tax_correspondence', 'tax_checklist_ticks',
    ];

    /** route parameter name => model it binds to */
    private const BINDINGS = ['account' => 'account', 'transaction' => 'transaction', 'category' => 'category', 'budget' => 'budget', 'rule' => 'rule', 'token' => 'token', 'party' => 'party', 'attachment' => 'attachment', 'glAccount' => 'glAccount', 'entry' => 'entry', 'period' => 'period', 'message' => 'message', 'draft' => 'draft', 'insight' => 'insight', 'notification' => 'notification', 'line' => 'line', 'statement' => 'statement', 'reconciliation' => 'reconciliation', 'bankMatch' => 'bankMatch', 'bankRule' => 'bankRule', 'bill' => 'bill', 'claim' => 'claim', 'claimLine' => 'claimLine', 'run' => 'run', 'recurring' => 'recurring', 'detail' => 'detail', 'payment' => 'payment', 'reportExport' => 'reportExport', 'reportPreset' => 'reportPreset', 'planBudget' => 'planBudget', 'dimension' => 'dimension', 'scenario' => 'scenario', 'document' => 'document', 'application' => 'application', 'template' => 'template', 'link' => 'link', 'shareToken' => 'shareToken', 'taxDeadline' => 'taxDeadline', 'taxReturn' => 'taxReturn', 'returnAdjustment' => 'returnAdjustment', 'taxPayrollRun' => 'taxPayrollRun', 'taxAdjustment' => 'taxAdjustment', 'provisionalPayment' => 'provisionalPayment', 'taxDocument' => 'taxDocument', 'taxCorrespondence' => 'taxCorrespondence'];

    private User $me;

    private User $stranger;

    private Team $mine;       // a company $me belongs to

    private Team $theirs;     // a company $me does NOT belong to

    /** @var array<string, array<string, int|string>> context label => [param => id] */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->me = $this->user();
        $this->stranger = $this->user();
        $this->mine = $this->company($this->me, 'Mine Ltd');
        $this->theirs = $this->company($this->stranger, 'Theirs Ltd');

        $this->rows['my personal'] = $this->seedContext('personal', ['user_id' => $this->me->id, 'team_id' => null]);
        $this->rows['my company'] = $this->seedContext('mine', ['user_id' => null, 'team_id' => $this->mine->id], company: $this->mine);
        $this->rows['their company'] = $this->seedContext('theirs', ['user_id' => null, 'team_id' => $this->theirs->id], company: $this->theirs);
        $this->rows['their personal'] = $this->seedContext('stranger', ['user_id' => $this->stranger->id, 'team_id' => null]);
    }

    /** One row of every bindable kind in a context, named so a leak is readable. */
    private function seedContext(string $label, array $owner, ?Team $company = null): array
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

        return $bank + ['taxDeadline' => $deadline->id, 'taxReturn' => $taxReturn->id, 'returnAdjustment' => $returnAdjustment->id, 'taxPayrollRun' => $payrollRun->id, 'taxAdjustment' => $taxAdjustment->id, 'provisionalPayment' => $provisional->id, 'taxDocument' => $taxDocument->id, 'taxCorrespondence' => $correspondence->id, 'document' => $document, 'application' => $application, 'template' => $template, 'link' => $link, 'shareToken' => 'notatoken', 'planBudget' => $plan->id, 'dimension' => $dimension->id, 'scenario' => $scenario->id, 'reportPreset' => $preset->id, 'reportExport' => $export->id, 'bill' => $bill->id, 'claim' => $claim->id, 'claimLine' => $claimLine->id, 'run' => $run->id, 'recurring' => $recurring->id, 'detail' => $detail->id, 'payment' => $payment->id, 'glAccount' => $glAccount, 'entry' => $journalEntry, 'period' => $period, 'account' => $account->id, 'transaction' => $entry->id, 'category' => $category->id, 'budget' => $budget->id, 'rule' => $rule->id, 'token' => $token, 'second_account' => $other->id, 'party' => $party->id, 'attachment' => $attachment->id, 'message' => $message->id, 'draft' => $draft->id, 'insight' => $insight2->id, 'notification' => $notification->id, 'line' => $line->id];
    }

    /** @return array<string, int> */
    private function seedBank(string $label, array $owner, Account $account): array
    {
        $statement = BankStatement::create($owner + ['account_id' => $account->id, 'source' => 'csv', 'original_name' => "Sentinel file {$label}.csv", 'file_sha256' => hash('sha256', $label), 'currency' => 'ZAR', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'line_count' => 1]);
        BankStatementLine::create($owner + ['bank_statement_id' => $statement->id, 'account_id' => $account->id, 'line_no' => 1, 'posted_on' => '2026-10-02', 'amount_cents' => 1234, 'currency' => 'ZAR', 'description' => "Sentinel row {$label}", 'fingerprint' => hash('sha256', 'line'.$label)]);
        $session = BankReconciliation::create($owner + ['account_id' => $account->id, 'status' => 'draft', 'statement_date' => '2026-10-31', 'statement_closing_cents' => 1234, 'currency' => 'ZAR', 'started_at' => now()]);
        $match = BankMatch::create($owner + ['account_id' => $account->id, 'bank_reconciliation_id' => $session->id, 'ref' => (string) Str::uuid(), 'method' => 'manual', 'shape' => 'ledger_only', 'reason' => 'seed', 'amount_cents' => 0, 'line_count' => 0, 'transaction_count' => 0, 'state' => 'active', 'matched_at' => now()]);
        $rule = BankRule::create($owner + ['name' => "Sentinel rule bank {$label}", 'pattern' => "sentinel-{$label}"]);

        return ['statement' => $statement->id, 'reconciliation' => $session->id, 'bankMatch' => $match->id, 'bankRule' => $rule->id];
    }

    private function keyFor(array $owner): string
    {
        return $owner['team_id'] !== null ? 'company:'.$owner['team_id'] : 'personal:'.$owner['user_id'];
    }

    /** A hash of every ledger row, deleted ones included. */
    private function fingerprint(): string
    {
        $out = [];
        foreach (self::LEDGER_TABLES as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return md5(json_encode($out));
    }

    /** @return list<LaravelRoute> every route that binds one of the ledger parameters */
    private function bindingRoutes(): array
    {
        $found = [];
        foreach (Route::getRoutes() as $route) {
            $params = $route->parameterNames();
            // Only this app's own routes (Fortify's reset-password/{token} is not a ledger route).
            if (str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\') && array_intersect($params, array_keys(self::BINDINGS)) !== []) {
                $found[] = $route;
            }
        }

        return $found;
    }

    private function callerContexts(): array
    {
        return [
            'personal' => fn () => $this->inPersonal($this->me),
            'my company' => fn () => $this->inCompany($this->me, $this->mine),
        ];
    }

    private function send(LaravelRoute $route, array $params): TestResponse
    {
        $method = collect($route->methods())->first(fn ($m) => ! in_array($m, ['HEAD'], true));
        $uri = route($route->getName(), $params, false);

        $payload = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            ? ['amount' => '1.00', 'description' => 'smuggled', 'type' => 'expense', 'date' => now()->toDateString(), 'name' => 'smuggled', 'confirm' => '1', 'opening_balance' => '1.00', 'opening_balance_date' => now()->toDateString(), 'currency' => 'ZAR', 'category_id' => $params['category'] ?? null, 'account_id' => $params['account'] ?? null, 'recurrence_type' => 'monthly', 'recurrence_interval' => 1, 'month' => 1, 'year' => 2026, 'token' => $params['token'] ?? null, 'date_format' => 'ymd', 'amount_locale' => 'en', 'end_date' => now()->toDateString()]
            : [];

        return $this->call($method, $uri, $payload);
    }

    public function test_every_route_that_takes_a_ledger_id_is_known_to_this_test(): void
    {
        $unknown = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\')) {
                continue;
            }
            // R-44 Controls routes ({member}, {policy}, {approval}, {limit}) bind their own models and are smuggled across
            // ledgers, with the database compared before and after, by tests/Feature/Controls/ControlsRoutesTest.php.
            if (str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\Controls\\')) {
                continue;
            }
            foreach ($route->parameterNames() as $name) {
                if (! array_key_exists($name, self::BINDINGS)) {
                    $unknown[] = $route->methods()[0].' '.$route->uri().' {'.$name.'}';
                }
            }
        }

        $this->assertSame([], $unknown, 'These routes take an id this regression test does not smuggle yet');
        $this->assertGreaterThan(15, count($this->bindingRoutes()), 'expected the finance routes to be found');
    }

    public function test_smuggling_a_foreign_id_into_any_route_is_a_404_and_changes_nothing(): void
    {
        $attempts = 0;

        foreach ($this->callerContexts() as $callerLabel => $enter) {
            // Own context is a baseline the personal and company cases must NOT be confused with.
            $foreign = collect($this->rows)->except($callerLabel === 'personal' ? ['my personal'] : ['my company']);

            foreach ($foreign as $foreignLabel => $ids) {
                foreach ($this->bindingRoutes() as $route) {
                    $enter();
                    $params = [];
                    foreach ($route->parameterNames() as $name) {
                        $params[$name] = $ids[$name] ?? null;
                    }
                    $params = array_filter($params, fn ($v) => $v !== null);
                    if (count($params) !== count($route->parameterNames())) {
                        continue;
                    }

                    $before = $this->fingerprint();
                    $response = $this->send($route, $params);
                    $attempts++;

                    $label = "{$callerLabel} caller -> {$foreignLabel} id on ".implode('|', $route->methods()).' '.$route->uri();

                    // An import preview of a token from another context redirects to the upload page
                    // (that is how a stale or foreign token is always answered); everything else is a 404.
                    if ($route->getName() === 'transactions.import.preview') {
                        $response->assertRedirect(route('transactions.import.create'));
                    } else {
                        $this->assertSame(404, $response->getStatusCode(), $label.' should be 404, got '.$response->getStatusCode());
                    }

                    $this->assertSame($before, $this->fingerprint(), $label.' changed the database');
                }
            }
        }

        $this->assertGreaterThan(100, $attempts);
    }

    public function test_a_caller_in_a_company_they_do_not_belong_to_reaches_nothing_by_id_either(): void
    {
        $outsider = $this->user();

        foreach ($this->rows as $label => $ids) {
            foreach ($this->bindingRoutes() as $route) {
                $params = [];
                foreach ($route->parameterNames() as $name) {
                    $params[$name] = $ids[$name] ?? null;
                }
                $params = array_filter($params, fn ($v) => $v !== null);
                if (count($params) !== count($route->parameterNames()) || $route->getName() === 'transactions.import.preview') {
                    continue;
                }

                // The session claims membership of a company the outsider is not in.
                $this->inCompany($outsider, $this->mine);
                $before = $this->fingerprint();
                $response = $this->send($route, $params);

                $this->assertSame(404, $response->getStatusCode(), "outsider -> {$label} on ".$route->uri().' should be 404');
                $this->assertSame($before, $this->fingerprint());
            }
        }
    }

    public function test_the_list_and_report_pages_never_show_a_row_from_another_context(): void
    {
        $pages = ['dashboard', 'parties.index', 'transactions.index', 'accounts.index', 'categories.index', 'budgets.index', 'recurring.index', 'reports.cash-flow', 'forecast.index', 'insights.index', 'audit.index', 'reconciliation.index', 'transactions.create', 'transactions.import.create', 'transfers.create',
            'accounting.trial-balance', 'accounting.general-ledger', 'accounting.journals.index', 'accounting.chart.index', 'accounting.periods.index', 'accounting.journals.create',
            'intel.assistant.index', 'intel.attention.index', 'intel.notifications.index', 'intel.notifications.preferences', 'intel.integrations.index', 'intel.integrations.emall.index',
            'bank.statements.index', 'bank.reconciliation.index', 'bank.rules.index', 'bank.import.create',
            'purchases.index', 'purchases.bills.index', 'purchases.suppliers.index', 'purchases.claims.index', 'purchases.payments.index', 'purchases.runs.index', 'purchases.ap.index', 'purchases.documents.index', 'purchases.recurring.index', 'purchases.receipts.index',
            'reports.index', 'reports.profit-loss', 'reports.balance-sheet', 'reports.general-ledger', 'reports.budget-vs-actual', 'reports.exports.index', 'planning.budgets.index', 'planning.scenarios.index', 'planning.dimensions.index', 'planning.cash.index', 'planning.afford',
            'sales.invoices.index', 'sales.quotes.index', 'sales.credit-notes.index', 'sales.payments.index', 'sales.customers.index', 'sales.receivables.index', 'sales.recurring.index', 'sales.reminders.index', 'sales.invoices.create'];

        $cases = [
            ['personal', fn () => $this->inPersonal($this->me), 'personal'],
            ['my company', fn () => $this->inCompany($this->me, $this->mine), 'mine'],
        ];

        foreach ($cases as [$label, $enter, $own]) {
            foreach ($pages as $page) {
                $html = $enter()->get(route($page))->assertOk()->getContent();

                foreach (['personal', 'mine', 'theirs', 'stranger'] as $other) {
                    if ($other === $own) {
                        continue;
                    }
                    foreach (['account', 'second', 'category', 'entry', 'rule', 'insight', 'transfer', 'row', 'party', 'question', 'finding', 'notification', 'line', 'draft', 'file', 'supplier', 'claim', 'run', 'schedule', 'plan', 'dimension', 'view', 'document', 'issued', 'template'] as $kind) {
                        $this->assertStringNotContainsString("Sentinel {$kind} {$other}", $html, "{$page} in the {$label} ledger leaked '{$kind}' from {$other}");
                    }
                }
            }
        }
    }

    public function test_the_ledger_pages_do_show_their_own_rows_so_the_leak_test_is_not_vacuous(): void
    {
        $this->inPersonal($this->me)->get(route('accounts.index'))->assertSee('Sentinel account personal');
        $this->inCompany($this->me, $this->mine)->get(route('accounts.index'))->assertSee('Sentinel account mine');
        $this->get(route('transactions.index'))->assertSee('Sentinel entry mine');
        $this->get(route('categories.index'))->assertSee('Sentinel category mine');
        $this->get(route('recurring.index'))->assertSee('Sentinel rule mine');
        $this->get(route('parties.index'))->assertSee('Sentinel party mine');
        $this->get(route('bank.statements.index'))->assertSee('Sentinel file mine');
        $this->get(route('bank.rules.index'))->assertSee('Sentinel rule bank mine');
    }

    // ── foreign ids smuggled in the request body ───────────────────────────

    public function test_a_foreign_account_or_category_in_a_form_body_is_rejected_and_writes_nothing(): void
    {
        $day = now()->toDateString();

        $cases = [
            'my company' => [fn () => $this->inCompany($this->me, $this->mine), ['personal' => $this->rows['my personal'], 'their company' => $this->rows['their company'], 'their personal' => $this->rows['their personal']], $this->rows['my company']],
            'personal' => [fn () => $this->inPersonal($this->me), ['company' => $this->rows['my company'], 'their company' => $this->rows['their company'], 'their personal' => $this->rows['their personal']], $this->rows['my personal']],
        ];

        foreach ($cases as $label => [$enter, $foreigners, $own]) {
            foreach ($foreigners as $whose => $foreign) {
                $attempts = [
                    'transaction in a foreign account' => ['post', route('transactions.store'), ['account_id' => $foreign['account'], 'description' => 'x', 'amount' => '1.00', 'type' => 'expense', 'date' => $day]],
                    'transaction with a foreign category' => ['post', route('transactions.store'), ['account_id' => $own['account'], 'category_id' => $foreign['category'], 'description' => 'x', 'amount' => '1.00', 'type' => 'expense', 'date' => $day]],
                    'edit own transaction into a foreign account' => ['put', route('transactions.update', $own['transaction']), ['account_id' => $foreign['account'], 'description' => 'x', 'amount' => '1.00', 'type' => 'expense', 'date' => $day]],
                    'edit own transaction with a foreign category' => ['put', route('transactions.update', $own['transaction']), ['account_id' => $own['account'], 'category_id' => $foreign['category'], 'description' => 'x', 'amount' => '1.00', 'type' => 'expense', 'date' => $day]],
                    'transfer from a foreign account' => ['post', route('transfers.store'), ['from_account_id' => $foreign['account'], 'to_account_id' => $own['account'], 'amount' => '1.00', 'date' => $day]],
                    'transfer to a foreign account' => ['post', route('transfers.store'), ['from_account_id' => $own['account'], 'to_account_id' => $foreign['account'], 'amount' => '1.00', 'date' => $day]],
                    'budget for a foreign category' => ['post', route('budgets.store'), ['category_id' => $foreign['category'], 'amount' => '1.00', 'currency' => 'ZAR', 'month' => 3, 'year' => 2027]],
                    'edit own budget onto a foreign category' => ['put', route('budgets.update', $own['budget']), ['category_id' => $foreign['category'], 'amount' => '1.00', 'currency' => 'ZAR', 'month' => 3, 'year' => 2027]],
                    'edit own rule onto a foreign category' => ['put', route('recurring.update', $own['rule']), ['description' => 'x', 'amount' => '1.00', 'category_id' => $foreign['category'], 'recurrence_type' => 'monthly', 'recurrence_interval' => 1]],
                    'bank statement into a foreign account' => ['post', route('bank.import.upload'), ['account_id' => $foreign['account'], 'file' => UploadedFile::fake()->createWithContent('s.csv', "date,description,amount\n2026-08-01,x,-1.00\n")]],
                    'reconciliation of a foreign account' => ['post', route('bank.reconciliation.start'), ['account_id' => $foreign['account'], 'statement_date' => '2026-10-31', 'closing' => '1.00']],
                    'reconciliation from a foreign statement' => ['post', route('bank.reconciliation.start'), ['account_id' => $own['account'], 'bank_statement_id' => $foreign['statement']]],
                    'bank rule with a foreign category' => ['post', route('bank.rules.store'), ['name' => 'x', 'pattern' => 'x', 'match_field' => 'any', 'operator' => 'contains', 'direction' => 'any', 'category_id' => $foreign['category']]],
                    'match foreign lines in my session' => ['post', route('bank.reconciliation.match', $own['reconciliation']), ['line_ids' => [$foreign['statement']], 'entry_ids' => [$foreign['transaction']]]],
                    'import into a foreign account' => ['post', route('transactions.import.upload'), ['account_id' => $foreign['account'], 'file' => UploadedFile::fake()->createWithContent('s.csv', "date,description,amount\n2026-08-01,x,-1.00\n")]],
                ];

                foreach ($attempts as $what => [$method, $url, $body]) {
                    $enter();
                    $before = $this->fingerprint();
                    $response = $this->{$method}($url, $body);

                    $this->assertContains($response->getStatusCode(), [302, 422], "{$label} / {$whose} / {$what}");
                    $this->assertTrue(session()->has('errors'), "{$label} / {$whose} / {$what} should be refused with an error");
                    $this->assertSame($before, $this->fingerprint(), "{$label} / {$whose} / {$what} changed the database");
                }
            }
        }
    }

    public function test_the_import_commit_refuses_a_token_from_another_context_and_writes_nothing(): void
    {
        $this->inCompany($this->me, $this->mine);
        $before = $this->fingerprint();

        $this->post(route('transactions.import.store'), ['token' => $this->rows['my personal']['token'], 'date_format' => 'ymd', 'amount_locale' => 'en'])
            ->assertSessionHasErrors('token');

        $this->assertSame($before, $this->fingerprint());
    }

    public function test_exports_and_every_other_get_route_without_an_id_is_scoped_too(): void
    {
        // Every parameterless GET route of the app must answer in both contexts without showing a foreign sentinel.
        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || $route->parameterNames() !== [] || ! str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\')) {
                continue;
            }
            if (in_array($route->getName(), ['ecosystem.auth', null], true)) {
                continue;
            }

            foreach ([fn () => $this->inPersonal($this->me), fn () => $this->inCompany($this->me, $this->mine)] as $i => $enter) {
                $response = $enter()->get('/'.ltrim($route->uri(), '/'));
                if ($response->getStatusCode() >= 300) {
                    continue;
                }
                $own = $i === 0 ? 'personal' : 'mine';
                foreach (['personal', 'mine', 'theirs', 'stranger'] as $other) {
                    if ($other !== $own) {
                        $this->assertStringNotContainsString("Sentinel account {$other}", $response->getContent(), $route->uri().' leaked');
                        $this->assertStringNotContainsString("Sentinel entry {$other}", $response->getContent(), $route->uri().' leaked');
                    }
                }
            }
        }
        $this->addToAssertionCount(1);
    }
}
