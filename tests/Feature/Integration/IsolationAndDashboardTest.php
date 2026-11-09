<?php

namespace Tests\Feature\Integration;

use App\Contracts\Intelligence\PayablesSource;
use App\Contracts\Intelligence\ReceivablesSource;
use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Purchases\Bill;
use App\Models\Sales\SalesDocument;
use App\Models\Shared\Party;
use App\Models\Team;
use App\Models\User;
use App\Services\Accounting\TrialBalance;
use App\Services\Purchases\ApLedger;
use App\Services\Purchases\BillWorkflow;
use App\Services\Sales\DocumentDrafts;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\ReceivablesLedger;
use App\Services\Sales\SalesProfiles;
use App\Services\Tax\TaxProfiles;
use App\Services\Tax\TaxReturnService;
use App\Support\Ledger\LedgerContext;
use App\Support\Ui\StateLine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsAMonth;
use Tests\TestCase;

/**
 * Two companies in one database. Nothing one posts, issues or owes shows in the other's books, returns, ageing, pages or Command centre,
 * and a company with no records shows no panel and no figure at all.
 */
class IsolationAndDashboardTest extends TestCase
{
    use RefreshDatabase;
    use RunsAMonth;

    private User $ownerB;

    private Team $companyB;

    private LedgerContext $ledgerB;

    private SalesDocument $invB;

    private Bill $billB;

    /** A second company with its own books: R1,000 + VAT sold to Zeta Ltd, R500 + VAT bought from Yonder Supplies. */
    private function buildLedgerB(): void
    {
        $this->ownerB = $this->user();
        $this->companyB = $this->company($this->ownerB, 'Other Trading (Pty) Ltd');
        $this->ledgerB = LedgerContext::company($this->companyB);
        Account::factory()->forCompany($this->companyB)->create(['name' => 'Other current', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance_cents' => 500_000, 'opening_balance_date' => '2026-10-01']);
        $this->enableAccounting($this->ledgerB, 'ZAR');

        LedgerContext::run($this->ledgerB, function () {
            app(TaxProfiles::class)->save($this->ledgerB, [
                'jurisdiction' => 'ZA', 'legal_entity' => 'company', 'tax_regime' => 'standard', 'vat_registered' => true, 'vat_number' => '4000000001', 'vat_category' => 'C', 'vat_basis' => 'invoice',
                'vat_registered_on' => '2024-01-01', 'vat_filing_channel' => 'efiling', 'financial_year_end_month' => 2, 'provisional_taxpayer' => true, 'accounting_basis' => 'accrual', 'employs_staff' => false,
            ], $this->ownerB);
            app(SalesProfiles::class)->save($this->ledgerB, [
                'legal_name' => 'Other Trading (Pty) Ltd', 'address' => ['line1' => '9 Far Street', 'city' => 'Gqeberha', 'postal_code' => '6001', 'country' => 'South Africa'], 'email' => 'a@other.test', 'default_terms_days' => 30,
            ], $this->ownerB->id);
            $zeta = Party::create($this->ledgerB->ownerAttributes() + ['type' => 'customer', 'name' => 'Zeta Ltd', 'email' => 'zeta@customer.test', 'tax_number' => '4777888999', 'status' => 'active', 'billing_address' => ['line1' => '1 Z Road', 'city' => 'Paarl', 'country' => 'South Africa']]);
            $yonder = Party::create($this->ledgerB->ownerAttributes() + ['type' => 'supplier', 'name' => 'Yonder Supplies', 'email' => 'y@supplier.test', 'tax_number' => '4666777888', 'status' => 'active', 'payment_terms_days' => 30]);

            $draft = app(DocumentDrafts::class)->create($this->ledgerB, $this->ownerB, ['kind' => 'tax_invoice', 'party_id' => $zeta->id, 'currency' => 'ZAR', 'issue_date' => '2026-11-12'], [['description' => 'Zeta goods', 'quantity' => '1', 'unit_cents' => 100_000, 'tax_code' => 'ZA_VAT_STD']]);
            $this->invB = app(DocumentIssuer::class)->issue($draft, $this->ownerB)['document'];
            $flow = app(BillWorkflow::class);
            $this->billB = $flow->submit($this->ownerB, $flow->saveDraft($this->ledgerB, $this->ownerB, [
                'party_id' => $yonder->id, 'bill_date' => '2026-11-13', 'supplier_reference' => 'Y-1', 'document_type' => 'tax_invoice',
                'lines' => [['description' => 'Yonder stock', 'gl_account_id' => GlAccount::query()->forContext($this->ledgerB)->where('code', '6190')->value('id'), 'quantity' => '1', 'unit_price_cents' => 50_000, 'tax_code' => 'ZA_VAT_STD']],
            ]));
        });
    }

    public function test_a_second_company_changes_nothing_in_the_first_and_sees_nothing_of_it(): void
    {
        $this->runMonth();
        $trial = fn (LedgerContext $c) => app(TrialBalance::class)->asOf($c, '2026-11-30');
        $before = ['debits' => $trial($this->ledger)['total_debits'], 'ar' => $this->balance('ar'), 'ap' => $this->balance('ap'), 'vat_out' => $this->balance('vat_output')];

        $this->buildLedgerB();

        // A is exactly as it was
        $this->assertSame($before, ['debits' => $trial($this->ledger)['total_debits'], 'ar' => $this->balance('ar'), 'ap' => $this->balance('ap'), 'vat_out' => $this->balance('vat_output')]);
        $this->assertSame(14_453_500, $trial($this->ledger)['total_debits']);
        $this->assertTrue(app(ReceivablesLedger::class)->reconcile($this->ledger)['ok']);
        $this->assertTrue(app(ApLedger::class)->reconcile($this->ledger)['ok']);
        $this->assertBooksVerify($this->ledger);

        // B holds only its own: R1,150 owed by Zeta, R575 owed to Yonder, VAT 150 out and 75 in
        $this->assertBooksVerify($this->ledgerB);
        $this->assertSame(115_000, app(ReceivablesLedger::class)->glBalance($this->ledgerB));
        $this->assertSame(['Zeta Ltd'], app(ReceivablesLedger::class)->openItems($this->ledgerB)->map(fn ($r) => $r['document']->party->name)->all());
        $this->assertSame(57_500, app(ApLedger::class)->ageing($this->ledgerB, CarbonImmutable::parse('2026-11-30'))['grand_total']);

        $service = app(TaxReturnService::class);
        $profileB = app(TaxProfiles::class)->forLedger($this->ledgerB);
        $return = LedgerContext::run($this->ledgerB, fn () => $service->start($this->ledgerB, 'vat201', CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'), 'November 2026', $profileB, $this->ownerB));
        $computed = LedgerContext::run($this->ledgerB, fn () => $service->compute($this->ledgerB, $return));
        $this->assertSame(115_000, $computed->amount('1'), 'only Zeta\'s invoice: R1,000 + R150 VAT');
        $this->assertSame(15_000, $computed->amount('13'));
        $this->assertSame(7_500, $computed->amount('19'));
        $this->assertSame(7_500, $computed->amount('20'));
        $this->assertTrue($computed->reconciliation['ties']);

        // the same questions through the providers the assistant and the projection use
        $this->assertSame(['Zeta Ltd'], array_column(app(ReceivablesSource::class)->owedByCustomers($this->ledgerB), 'party'));
        $this->assertSame(['Acme Retail'], array_column(app(ReceivablesSource::class)->owedByCustomers($this->ledger), 'party'));
        $upcomingB = app(PayablesSource::class)->upcoming($this->ledgerB, CarbonImmutable::parse('2026-11-30'), CarbonImmutable::parse('2026-12-31'));
        $this->assertSame(['Yonder Supplies BILL-2026-00001'], array_column($upcomingB, 'label'));
        $this->assertSame(57_500, $upcomingB[0]['amount_cents']);
    }

    public function test_the_pages_of_one_company_do_not_show_or_open_the_other(): void
    {
        $this->runMonth();
        $this->buildLedgerB();

        // as A: B's records are not there
        $this->inCompany($this->owner, $this->company);
        $this->get(route('sales.documents.show', $this->invB))->assertNotFound();
        $this->get(route('purchases.bills.show', $this->billB))->assertNotFound();
        foreach (['reports.profit-loss', 'reports.balance-sheet', 'reports.ageing-receivables', 'sales.receivables.index', 'purchases.ap.index', 'dashboard'] as $name) {
            $this->get(route($name))->assertOk()->assertDontSee('Zeta Ltd')->assertDontSee('Yonder Supplies');
        }

        // as B: A's records are not there
        $this->inCompany($this->ownerB, $this->companyB);
        $this->get(route('sales.documents.show', $this->inv1))->assertNotFound();
        $this->get(route('purchases.bills.show', $this->bill1))->assertNotFound();
        foreach (['reports.profit-loss', 'reports.ageing-receivables', 'sales.receivables.index', 'purchases.ap.index', 'dashboard'] as $name) {
            $this->get(route($name))->assertOk()->assertDontSee('Acme Retail')->assertDontSee('Paper Co')->assertDontSee('Beta Stores');
        }
        $this->get(route('sales.receivables.index'))->assertSee('Zeta Ltd');
    }

    public function test_the_command_centre_shows_each_modules_panel_from_real_figures(): void
    {
        $this->runMonth();
        $this->artisan('finance:tax:calendar')->assertExitCode(0);
        // something overdue on each side, so the state line has an order to keep
        $this->issueInvoice($this->beta, [['description' => 'September goods', 'quantity' => '1', 'unit_cents' => 1_000_000, 'tax_code' => 'ZA_VAT_STD']], '2026-09-15');
        $this->postBill($this->paperCo, '2026-09-01', [$this->billLine(['unit_price_cents' => 123_400])]);

        $page = $this->inCompany($this->owner, $this->company)->get(route('dashboard'))->assertOk();
        $page->assertSee('Owed to you');                          // Sales: receivables
        $page->assertSee('Supplier bills due');                   // Purchases: payables (the overdue September bill)
        $page->assertSee('Tax coming up');                        // Tax: next deadline
        $page->assertSee('Profit and loss at a glance');          // Reporting
        $page->assertSee('Cash position and the next 13 weeks');  // Planning
        $page->assertSee('119,290.00');                           // cash today: the statement's closing balance, from the books
        $page->assertSee('15,280.00');                            // November profit so far
        $page->assertSee('invoice is past its due date');         // state line: Sales

        $keys = array_column(StateLine::for($this->ledger), 'key');
        $first = array_values(array_filter($keys, fn ($k) => in_array($k, ['invoices-overdue', 'bills-overdue', 'tax_overdue'], true)));
        $this->assertSame(['tax_overdue', 'invoices-overdue', 'bills-overdue'], $first, 'overdue first: the October VAT201 (due 25 November) before what customers owe, before what is owed to suppliers');
        $tones = array_column(StateLine::for($this->ledger), 'tone');
        $this->assertSame($tones, collect($tones)->sortBy(fn ($t) => $t === 'variance' ? 0 : 1)->values()->all(), 'every variance item comes before every neutral one');
    }

    public function test_a_company_with_no_records_shows_no_panel_and_no_figure(): void
    {
        $this->setUpIntegration();
        $empty = $this->company($this->user(), 'Brand New Ltd');
        $owner = User::query()->findOrFail($empty->user_id);
        $this->enableAccounting(LedgerContext::company($empty), 'ZAR');

        $page = $this->inCompany($owner, $empty)->get(route('dashboard'))->assertOk();
        foreach (['Owed to you', 'Supplier bills due', 'Tax coming up', 'Profit and loss at a glance', 'Cash position and the next 13 weeks', 'Bank accounts against their statements'] as $title) {
            $page->assertDontSee($title);
        }
        $this->assertSame([], StateLine::for(LedgerContext::company($empty)));
    }
}
