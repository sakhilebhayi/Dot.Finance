<?php

namespace Tests\Concerns;

use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankStatementLine;
use App\Models\Purchases\Bill;
use App\Models\Purchases\ExpenseClaim;
use App\Models\Sales\SalesDocument;
use App\Models\Shared\Payment;
use App\Models\Tax\TaxPayrollRun;
use App\Services\Bank\DocumentSources;
use App\Services\Bank\LineActions;
use App\Services\Bank\MatchingService;
use App\Services\Bank\MatchText;
use App\Services\Bank\ReconciliationService;
use App\Services\Bank\StatementImporter;
use App\Services\Controls\ApprovalPolicies;
use App\Services\Controls\ApprovalService;
use App\Services\Purchases\BillWorkflow;
use App\Services\Purchases\ClaimWorkflow;
use App\Services\Purchases\SupplierPayments;
use App\Services\Tax\PayrollJournal;
use App\Services\Tax\PayrollRuns;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * One November in the life of a small VAT-registered company, run through the real services in the order a bookkeeper would, with
 * every figure chosen so it can be worked out by hand (see the table in MonthInTheLifeTest).
 */
trait RunsAMonth
{
    use BuildsIntegration;

    protected SalesDocument $inv1;

    protected SalesDocument $inv2;

    protected SalesDocument $creditNote;

    protected Bill $bill1;

    protected Bill $bill2;

    protected Bill $bill3;

    protected Bill $supplierCredit;

    protected ExpenseClaim $claim;

    protected TaxPayrollRun $payroll;

    protected BankReconciliation $session;

    /** @var array<string, int> */
    protected array $lineIds = [];

    protected function runMonth(bool $bank = true): void
    {
        Storage::fake('local');
        $this->setUpIntegration();
        $ledger = $this->ledger;

        // ---- sales (invoice dates in the first half of the month, terms 30 days) -----------------------------------------
        $this->inv1 = $this->issueInvoice($this->acme, [
            ['description' => 'Consulting', 'quantity' => '1', 'unit_cents' => 1_000_000, 'tax_code' => 'ZA_VAT_STD'],          // R10,000.00 + R1,500.00 VAT
            ['description' => 'Export service', 'quantity' => '1', 'unit_cents' => 200_000, 'tax_code' => 'ZA_VAT_ZERO'],       // R2,000.00, zero-rated
            ['description' => 'Residential rental', 'quantity' => '1', 'unit_cents' => 50_000, 'tax_code' => 'ZA_VAT_EXEMPT'], // R500.00, exempt
        ], '2026-11-03');
        $this->inv2 = $this->issueInvoice($this->beta, [['description' => 'Goods', 'quantity' => '1', 'unit_cents' => 2_000_000, 'tax_code' => 'ZA_VAT_STD']], '2026-11-05'); // R20,000 + R3,000 VAT
        $this->creditNote = $this->issueCreditNote($this->inv2, '2026-11-10', 200_000);                                         // R2,000 + R300 VAT back to Beta
        $this->receive($this->acme, 500_000, '2026-11-12', $this->inv1, 'ACME RETAIL');                                           // part payment R5,000.00

        // ---- purchases -----------------------------------------------------------------------------------------------------
        $this->bill1 = $this->postBill($this->paperCo, '2026-11-04', [$this->billLine()]);                                      // R4,000 + R600 VAT, claimable
        $flow = app(BillWorkflow::class);
        $this->supplierCredit = $flow->submit($this->owner, $flow->saveDraft($ledger, $this->owner, [
            'kind' => 'credit_note', 'party_id' => $this->paperCo->id, 'credit_for_bill_id' => $this->bill1->id, 'bill_date' => '2026-11-08',
            'credit_reason' => 'Half the reams were damaged', 'supplier_reference' => 'CN-77', 'lines' => [$this->billLine(['unit_price_cents' => 40_000])],  // R400 + R60 VAT
        ]));
        $this->bill2 = $this->postBill($this->lunchCo, '2026-11-06', [$this->billLine(['description' => 'Client lunch', 'gl_account_id' => $this->gl('6190')->id, 'unit_price_cents' => 90_000, 'tax_code' => 'ZA_VAT_DENIED_ENTERTAINMENT'])]); // R900 + R135 VAT, blocked

        $this->bill3 = $this->postBill($this->paperCo, '2026-11-20', [$this->billLine(['description' => 'Printer toner', 'unit_price_cents' => 200_000])]); // R2,000 + R300 VAT, left unpaid

        // an expense claim goes through approval (claims over R500 need one)
        app(ApprovalPolicies::class)->create($this->owner, $ledger, ['subject_type' => 'expense', 'name' => 'Claims policy', 'threshold_cents' => 50_000, 'currency' => 'ZAR', 'steps_required' => 1]);
        $bookkeeper = $this->memberAs('bookkeeper');
        $approver = $this->memberAs('approver');
        $claims = app(ClaimWorkflow::class);
        $claim = $claims->saveDraft($ledger, $bookkeeper, [
            'title' => 'Client visit fuel', 'claim_date' => '2026-11-09', 'claimant_user_id' => $bookkeeper->id,
            'lines' => [['spent_on' => '2026-11-09', 'merchant' => 'Engen', 'description' => 'Fuel', 'receipt_type' => 'tax_invoice', 'merchant_vat_number' => '4987654321', 'gl_account_id' => $this->gl('6170')->id, 'tax_code' => 'ZA_VAT_STD', 'gross_cents' => 115_000]],
        ]);
        $claim = $claims->submit($bookkeeper, $claim);
        $this->assertSame('awaiting_approval', $claim->status);
        app(ApprovalService::class)->approve($approver, app(ApprovalService::class)->latestFor($claim));
        $this->claim = $claim->fresh();

        // supplier payments: the bill net of its credit, and the claim paid back
        $pay = fn (int $partyId, string $on, array $allocations) => app(SupplierPayments::class)->pay($ledger, $this->owner, [
            'party_id' => $partyId, 'cash_account_id' => $this->bank->id, 'paid_on' => $on, 'method' => 'eft', 'reference' => 'EFT', 'idempotency_key' => $this->idem('pay'), 'allocations' => $allocations,
        ]);
        $pay($this->paperCo->id, '2026-11-14', [['type' => 'bill', 'id' => $this->bill1->id, 'amount_cents' => 414_000]]);
        $pay($this->claim->party_id, '2026-11-15', [['type' => 'expense_claim', 'id' => $this->claim->id, 'amount_cents' => 115_000]]);

        // ---- payroll summary for the month: captured, then its journal posted ------------------------------------------------
        $this->payroll = app(PayrollRuns::class)->capture($ledger, CarbonImmutable::parse('2026-11-01'), [
            'gross_pay_cents' => 5_000_000, 'paye_cents' => 600_000, 'uif_employee_cents' => 50_000, 'uif_employer_cents' => 50_000,
            'sdl_cents' => 50_000, 'eti_claimed_cents' => 0, 'employee_count' => 4,
        ], $this->owner);
        LedgerContext::run($ledger, fn () => app(PayrollJournal::class)->post($ledger, $this->payroll, $this->owner));

        if ($bank) {
            $this->reconcileBank();
        }
    }

    protected function statementCsv(): string
    {
        return implode("\n", [
            'Date,Description,Amount,Balance',
            '2026-11-12,EFT CREDIT ACME RETAIL,5000.00,105000.00',
            '2026-11-14,EFT PAPER CO,-4140.00,100860.00',
            '2026-11-15,EFT FUEL REIMBURSE,-1150.00,99710.00',
            '2026-11-18,EFT CREDIT BETA STORES INV-00002,20700.00,120410.00',
            '2026-11-20,EFT LUNCH CO,-1035.00,119375.00',
            '2026-11-30,MONTHLY ACCOUNT FEE,-120.00,119255.00',
            '2026-11-30,CREDIT INTEREST,35.00,119290.00',
        ])."\n";
    }

    /** Import the statement, let the engine match what it can, settle two documents from their lines, book the bank's own charges, complete. */
    protected function reconcileBank(): void
    {
        $ledger = $this->ledger;
        $file = UploadedFile::fake()->createWithContent('november.csv', $this->statementCsv());

        LedgerContext::run($ledger, function () use ($ledger, $file) {
            $importer = app(StatementImporter::class);
            $token = $importer->stage($this->owner, $ledger, $this->bank, $file);
            $imported = $importer->commit($this->owner, $ledger, $token, []);
            $this->assertSame(7, $imported['imported']);
            $this->assertSame(11_929_000, $imported['statement']->closing_balance_cents, 'the running balance proves the closing balance');

            $this->session = app(ReconciliationService::class)->start($this->owner, $this->bank, '2026-11-30', 11_929_000, null, $imported['statement']);

            // what the engine can see on its own: the receipt, the supplier payment and the claim payment
            $matching = app(MatchingService::class);
            $accepted = $matching->accept($this->session, $matching->suggest($this->session)['auto'] ?? [], $this->owner);
            $this->assertSame(3, $accepted['accepted']);

            foreach (BankStatementLine::query()->forContext($ledger)->get() as $line) {
                $this->lineIds[$line->description] = $line->id;
            }

            // the two lines for documents are settled through Sales and Purchases, each through its own payment service
            $sources = app(DocumentSources::class);
            $actions = app(LineActions::class);
            foreach (['EFT CREDIT BETA STORES INV-00002', 'EFT LUNCH CO'] as $description) {
                $line = BankStatementLine::query()->forContext($ledger)->findOrFail($this->lineIds[$description]);
                $prepared = MatchText::prepare($line->description, $line->reference);
                $found = $sources->candidates($ledger, [['id' => $line->id, 'cents' => $line->amount_cents, 'currency' => 'ZAR', 'text' => $prepared['text'], 'docs' => $prepared['docs']]]);
                $this->assertNotEmpty($found[$line->id] ?? [], "no open document offered for {$description}");
                $actions->settleDocument($this->session, $this->owner, $line, $found[$line->id][0]);
            }

            $actions->createEntries($this->session, $this->owner, [$this->lineIds['MONTHLY ACCOUNT FEE']], 'bank_charge');
            $actions->createEntries($this->session, $this->owner, [$this->lineIds['CREDIT INTEREST']], 'interest');

            $this->session = app(ReconciliationService::class)->complete($this->session->fresh(), $this->owner);
        });
    }
}
