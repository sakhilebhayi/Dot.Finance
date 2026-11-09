<?php

namespace Tests\Feature\Acceptance;

use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankStatement;
use App\Models\Reporting\ReportExport;
use App\Models\Shared\Attachment;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxPayrollRun;
use App\Models\Tax\TaxReturn;
use App\Models\Team;
use App\Models\Transaction;
use App\Services\Purchases\BillWorkflow;
use App\Services\Sales\DocumentDrafts;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\SalesProfiles;
use App\Support\Ledger\LedgerContext;
use App\Support\Ledger\LedgerContextManager;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

/**
 * Owner criterion 27: financial data cannot leak between businesses. One person owns two companies and has a personal ledger (the
 * hardest case: the same login in every one). A whole trading month sits in company A, a smaller business in company B and a
 * personal budget in the personal ledger, each with its own recognisable names. Then every page of the application that takes no
 * id is rendered in each ledger and may not contain another ledger's names, and every detail page, export, download and drill-down
 * of A is tried from B and from the personal ledger and must be "not found". The route-by-route net is in ContextIsolationRoutesTest.
 */
class Acceptance27_NoLeakBetweenBusinessesTest extends AcceptanceCase
{
    private Team $b;

    private LedgerContext $bCtx;

    // company names are not markers: the ledger switcher lists every company the person belongs to, by design
    private const A_MARKERS = ['Acme Retail', 'Beta Stores', 'Paper Co', 'Lunch Co', 'ACME RETAIL', '4987654321', 'november.csv', '119,290.00'];

    private const B_MARKERS = ['Zebra Customer', 'Zulu Supplies', 'Zed cheque', '7,777.00'];

    private const P_MARKERS = ['PERSONAL-SENTINEL', 'Sentinel Wallet'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMonth();
        $this->asOwner()->post(route('reports.exports.store'), ['report' => 'profit-loss', 'format' => 'csv', 'from' => '2026-11-01', 'to' => '2026-11-30', 'queue' => '1']);

        // company B: same owner, its own customer, supplier, invoice, bill and money
        $this->b = $this->company($this->owner, 'Zed Holdings (Pty) Ltd');
        $this->bCtx = LedgerContext::company($this->b);
        Account::factory()->forCompany($this->b)->create(['name' => 'Zed cheque', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance_cents' => 777_700, 'opening_balance_date' => '2026-10-01']);
        $this->enableAccounting($this->bCtx, 'ZAR');
        LedgerContext::run($this->bCtx, function () {
            app(SalesProfiles::class)->save($this->bCtx, ['legal_name' => 'Zed Holdings (Pty) Ltd', 'vat_registered' => false, 'address' => ['line1' => '2 Zed Way', 'city' => 'Pretoria', 'postal_code' => '0001', 'country' => 'South Africa']], $this->owner->id);
            $customer = Party::create($this->bCtx->ownerAttributes() + ['type' => 'customer', 'name' => 'Zebra Customer', 'legal_name' => 'Zebra Customer (Pty) Ltd', 'status' => 'active', 'payment_terms_days' => 30, 'billing_address' => ['line1' => '1 Stripe St', 'city' => 'Durban', 'country' => 'South Africa']]);
            $supplier = Party::create($this->bCtx->ownerAttributes() + ['type' => 'supplier', 'name' => 'Zulu Supplies', 'status' => 'active', 'payment_terms_days' => 30]);
            $draft = app(DocumentDrafts::class)->create($this->bCtx, $this->owner, ['kind' => 'tax_invoice', 'party_id' => $customer->id, 'currency' => 'ZAR', 'issue_date' => '2026-11-10'], [['description' => 'Stripes', 'quantity' => '1', 'unit_cents' => 111_111, 'tax_code' => 'ZA_VAT_STD']]);
            app(DocumentIssuer::class)->issue($draft, $this->owner);
            $flow = app(BillWorkflow::class);
            $flow->submit($this->owner, $flow->saveDraft($this->bCtx, $this->owner, ['party_id' => $supplier->id, 'bill_date' => '2026-11-11', 'supplier_reference' => 'ZULU-1', 'document_type' => 'tax_invoice', 'lines' => [
                ['description' => 'Zulu goods', 'gl_account_id' => GlAccount::query()->where('code', '6190')->value('id'), 'quantity' => '1', 'unit_price_cents' => 50_000, 'tax_code' => 'ZA_VAT_ZERO'],
            ]]));
        });

        // the personal ledger
        $wallet = Account::factory()->create(['user_id' => $this->owner->id, 'name' => 'Sentinel Wallet', 'currency' => 'ZAR', 'opening_balance_cents' => 10_000, 'opening_balance_date' => '2026-10-01']);
        Transaction::factory()->create(['account_id' => $wallet->id, 'user_id' => $this->owner->id, 'team_id' => null, 'type' => 'expense', 'amount_cents' => 4_321, 'date' => '2026-11-05', 'description' => 'PERSONAL-SENTINEL coffee']);
    }

    private function inLedger(string $which): static
    {
        return match ($which) {
            'A' => $this->inCompany($this->owner, $this->company),
            'B' => $this->inCompany($this->owner, $this->b),
            'P' => $this->inPersonal($this->owner),
        };
    }

    /** @return list<string> names of GET routes that take no id */
    private function indexRoutes(): array
    {
        $skip = ['register', 'cookies', 'sales.profile.logo.show', 'accounting.setup', 'accounting.setup.review', 'reports.drill'];
        $names = [];
        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null || ! in_array('GET', $route->methods(), true) || str_contains($route->uri(), '{') || in_array($name, $skip, true)) {
                continue;
            }
            if (str_contains($route->uri(), 'api/') || str_starts_with($route->uri(), 'user/') || str_contains($name, 'ecosystem')) {
                continue;
            }
            $names[] = $name;
        }

        return $names;
    }

    private function text(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES);
    }

    public function test_no_index_page_in_any_ledger_shows_another_ledgers_names_or_figures(): void
    {
        $markers = ['A' => self::A_MARKERS, 'B' => self::B_MARKERS, 'P' => self::P_MARKERS];
        $routes = $this->indexRoutes();
        $this->assertGreaterThan(80, count($routes), 'the sweep covers the whole application');
        $pages = 0;
        $sawOwn = ['A' => false, 'B' => false, 'P' => false];

        foreach (['A', 'B', 'P'] as $viewer) {
            foreach ($routes as $name) {
                $response = $this->inLedger($viewer)->get(route($name));
                $this->assertLessThan(500, $response->getStatusCode(), "{$viewer}: {$name}");
                if ($response->getStatusCode() !== 200 && ! $response->isRedirection()) {
                    continue;
                }
                $body = $response instanceof TestResponse && method_exists($response->baseResponse, 'streamedContent') && ! $response->baseResponse instanceof Response ? '' : $response->getContent();
                $text = $this->text((string) $body);
                $pages++;
                foreach ($markers as $owner => $list) {
                    foreach ($list as $needle) {
                        if ($owner === $viewer) {
                            $sawOwn[$viewer] = $sawOwn[$viewer] || str_contains($text, $needle);

                            continue;
                        }
                        $this->assertStringNotContainsString($needle, $text, "ledger {$viewer} must not show {$owner}'s \"{$needle}\" on {$name}");
                    }
                }
            }
        }

        $this->assertGreaterThan(250, $pages);
        $this->assertSame(['A' => true, 'B' => true, 'P' => true], $sawOwn, 'each ledger does show its own data, so the absence above is meaningful');
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> A's detail pages, exports and downloads */
    private function detailPages(): array
    {
        $statement = BankStatement::query()->forContext($this->ledger)->firstOrFail();
        $session = BankReconciliation::query()->forContext($this->ledger)->firstOrFail();
        $glAr = $this->gl('ar');
        $this->asOwner()->post(route('tax.calendar.generate'));
        $this->asMember($this->owner)->post(route('tax.vat.start'), ['period_start' => '2026-11-01']);
        $return = TaxReturn::query()->forContext($this->ledger)->first();
        $path = tempnam(sys_get_temp_dir(), 'fx');
        file_put_contents($path, 'private supplier invoice for Paper Co '.uniqid());
        $this->asOwner()->post(route('purchases.bills.attachments.store', $this->bill1), ['file' => new UploadedFile($path, 'invoice.txt', null, null, true)]);
        $attachment = Attachment::query()->forContext($this->ledger)->firstOrFail();
        $export = ReportExport::query()->forContext($this->ledger)->first();
        $payment = Payment::query()->forContext($this->ledger)->where('direction', 'in')->firstOrFail();
        $supplierPayment = Payment::query()->forContext($this->ledger)->where('direction', 'out')->firstOrFail();
        $entry = JournalEntry::query()->forContext($this->ledger)->where('source_type', 'invoice')->firstOrFail();
        $payroll = TaxPayrollRun::query()->forContext($this->ledger)->firstOrFail();
        $deadline = TaxDeadline::query()->forContext($this->ledger)->firstOrFail();

        $pages = [
            'invoice' => ['sales.documents.show', ['document' => $this->inv1]], 'invoice print' => ['sales.documents.print', ['document' => $this->inv1]], 'invoice pdf' => ['sales.documents.pdf', ['document' => $this->inv1]],
            'invoice edit' => ['sales.documents.edit', ['document' => $this->inv1]],
            'receipt' => ['sales.payments.show', ['payment' => $payment]], 'customer' => ['sales.customers.show', ['party' => $this->acme]],
            'customer statement' => ['sales.customers.statement', ['party' => $this->acme]], 'customer statement csv' => ['sales.customers.statement.csv', ['party' => $this->acme]],
            'customer statement pdf' => ['sales.customers.statement.pdf', ['party' => $this->acme]], 'party' => ['parties.show', ['party' => $this->acme]], 'party edit' => ['parties.edit', ['party' => $this->acme]],
            'bill' => ['purchases.bills.show', ['bill' => $this->bill1]], 'bill edit' => ['purchases.bills.edit', ['bill' => $this->bill3]], 'claim' => ['purchases.claims.show', ['claim' => $this->claim]],
            'supplier' => ['purchases.suppliers.show', ['party' => $this->paperCo]], 'supplier statement' => ['purchases.suppliers.statement', ['party' => $this->paperCo]],
            'supplier payment' => ['purchases.payments.show', ['payment' => $supplierPayment]], 'attachment download' => ['attachments.show', ['attachment' => $attachment]],
            'journal' => ['accounting.journals.show', ['entry' => $entry]], 'general ledger of a foreign account' => ['accounting.general-ledger', ['account' => $glAr->id]], 'drill-down' => ['reports.drill', ['account' => $glAr->id]],
            'report general ledger of a foreign account' => ['reports.general-ledger', ['account' => $glAr->id]],
            'bank statement' => ['bank.statements.show', ['statement' => $statement]], 'reconciliation' => ['bank.reconciliation.show', ['reconciliation' => $session]],
            'reconciliation report' => ['bank.reconciliation.report', ['reconciliation' => $session]], 'reconciliation csv' => ['bank.reconciliation.csv', ['reconciliation' => $session]],
            'payroll' => ['tax.payroll.show', ['taxPayrollRun' => $payroll]], 'deadline' => ['tax.calendar.show', ['taxDeadline' => $deadline]],
            'account' => ['accounts.edit', ['account' => $this->bank]],
        ];
        if ($return !== null) {
            $pages += ['vat return' => ['tax.returns.show', ['taxReturn' => $return]], 'vat working paper' => ['tax.returns.workpaper', ['taxReturn' => $return]], 'vat csv' => ['tax.returns.csv', ['taxReturn' => $return]]];
        }
        if ($export !== null) {
            $pages += ['export status' => ['reports.exports.show', ['reportExport' => $export]]];
        }

        return $pages;
    }

    public function test_every_detail_page_export_and_download_of_a_is_not_found_from_b_and_from_the_personal_ledger(): void
    {
        $pages = $this->detailPages();
        $this->assertGreaterThan(28, count($pages));
        $checked = 0;

        foreach (['B', 'P'] as $viewer) {
            foreach ($pages as $label => [$name, $params]) {
                $url = route($name, $params);
                $status = $this->inLedger($viewer)->get($url)->getStatusCode();
                $response = $this->inLedger($viewer)->get($url);
                $location = $response->headers->get('Location');
                if ($status === 200) {
                    // a ledger with accounting off answers every report with "not switched on": it must show nothing of A
                    $text = $this->text((string) $response->getContent());
                    $this->assertStringContainsString('not switched on', $text, "{$viewer} got a page for A's {$label} ({$name})");
                    foreach (self::A_MARKERS as $needle) {
                        $this->assertStringNotContainsString($needle, $text, "{$viewer}: {$label}");
                    }
                } else {
                    // a ledger without accounting turned on is sent to the set-up page: that shows nothing of A either
                    $this->assertTrue(in_array($status, [403, 404], true) || ($status === 302 && str_ends_with((string) $location, '/accounting/setup')), "{$viewer} opened A's {$label} ({$name}): HTTP {$status} {$location}");
                }
                $checked++;
            }
        }
        // and A itself can open (almost) all of them, so a 404 above is about the ledger and not a broken route
        $opened = 0;
        foreach ($pages as $label => [$name, $params]) {
            $this->assertLessThan(500, $this->inLedger('A')->get(route($name, $params))->getStatusCode(), "A: {$label}");
            $opened += $this->inLedger('A')->get(route($name, $params))->getStatusCode() === 200 ? 1 : 0;
        }
        $this->assertGreaterThan(count($pages) - 6, $opened, 'A opens almost all of its own pages');
        $this->assertGreaterThan(55, $checked);
    }

    public function test_a_signed_export_download_of_a_is_useless_in_b_and_the_personal_ledger(): void
    {
        $export = ReportExport::query()->forContext($this->ledger)->first();
        if ($export === null) {
            config(['reporting.export_queue_threshold_rows' => 1]);
            $this->asOwner()->post(route('reports.exports.store'), ['report' => 'general-ledger', 'format' => 'csv', 'from' => '2026-11-01', 'to' => '2026-11-30', 'queue' => '1']);
            $export = ReportExport::query()->forContext($this->ledger)->firstOrFail();
        }
        $signed = URL::temporarySignedRoute('reports.exports.download', now()->addMinutes(5), ['reportExport' => $export->id]);
        $this->inLedger('A')->get($signed)->assertOk();
        $this->inLedger('B')->get($signed)->assertNotFound();
        $this->inLedger('P')->get($signed)->assertNotFound();
    }

    public function test_writes_aimed_at_another_ledgers_ids_are_refused_and_change_nothing(): void
    {
        $before = [Transaction::query()->forContext($this->ledger)->count(), JournalEntry::query()->forContext($this->ledger)->count(), $this->inv1->fresh()->status, $this->bill3->fresh()->status];

        foreach (['B', 'P'] as $viewer) {
            $this->inLedger($viewer)->post(route('sales.documents.void', $this->inv1), ['reason' => 'sabotage'])->assertNotFound();
            $this->inLedger($viewer)->post(route('purchases.bills.void', $this->bill3), ['reason' => 'sabotage'])->assertNotFound();
            $this->inLedger($viewer)->post(route('sales.payments.allocate', Payment::query()->forContext($this->ledger)->where('direction', 'in')->firstOrFail()), ['document_id' => $this->inv1->id, 'amount' => '1.00'])->assertNotFound();
            $this->inLedger($viewer)->put(route('accounts.update', $this->bank), ['name' => 'Hijacked', 'type' => 'checking', 'currency' => 'ZAR'])->assertNotFound();
            $this->inLedger($viewer)->delete(route('transactions.destroy', Transaction::query()->forContext($this->ledger)->firstOrFail()))->assertNotFound();
            $this->inLedger($viewer)->post(route('transactions.store'), ['account_id' => $this->bank->id, 'description' => 'Smuggled', 'amount' => '1.00', 'type' => 'expense', 'date' => '2026-11-12'])->assertSessionHasErrors('account_id');
            $this->inLedger($viewer)->post(route('sales.documents.store'), ['kind' => 'tax_invoice', 'party_id' => $this->acme->id, 'currency' => 'ZAR', 'issue_date' => '2026-11-12', 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '1.00', 'tax_code' => 'ZA_VAT_STD']]])->assertSessionHasErrors();
        }

        $this->assertSame($before, [Transaction::query()->forContext($this->ledger)->count(), JournalEntry::query()->forContext($this->ledger)->count(), $this->inv1->fresh()->status, $this->bill3->fresh()->status]);
        $this->assertSame('Current account', $this->bank->fresh()->name);
        $this->assertBooksVerify();
    }

    public function test_switching_ledgers_in_the_session_to_one_the_person_is_not_in_falls_back_to_their_own(): void
    {
        $stranger = $this->user();
        $page = $this->text($this->actingAs($stranger->fresh())->withSession([LedgerContextManager::SESSION_KEY => $this->company->id])->get(route('sales.customers.index'))->assertOk()->getContent());
        foreach (self::A_MARKERS as $needle) {
            $this->assertStringNotContainsString($needle, $page);
        }
        $this->actingAs($stranger->fresh())->post(route('ledger.context.switch'), ['context' => (string) $this->company->id]);
        $after = $this->text($this->actingAs($stranger->fresh())->get(route('sales.customers.index'))->getContent());
        $this->assertStringNotContainsString('Acme Retail', $after);
    }
}
