<?php

namespace Tests\Feature\Sales;

use App\Enums\FinanceRole;
use App\Models\Controls\LedgerMemberRole;
use App\Models\Sales\SalesApplication;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesRecurringTemplate;
use App\Models\Sales\SalesShareLink;
use App\Models\Shared\Payment;
use App\Models\User;
use App\Services\Sales\DocumentLifecycle;
use App\Services\Sales\ReceiptService;
use App\Services\Sales\ShareLinks;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsSales;
use Tests\TestCase;

/**
 * Every Sales route against every finance role. A route is either open to a role (anything but 403) or shut (403 and
 * nothing written). The table is the specification: change a capability and this table must change with it.
 */
class SalesCapabilityMatrixTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    /** @var array<string, User> */
    private array $people = [];

    private const ALL = ['owner', 'manager', 'accountant', 'bookkeeper', 'approver', 'viewer'];

    private const ISSUERS = ['owner', 'manager', 'accountant', 'bookkeeper'];       // issue-invoice, record-payment

    private SalesDocument $issued;

    private SalesDocument $draft;

    private SalesDocument $credit;

    private Payment $payment;

    private SalesApplication $application;

    private SalesRecurringTemplate $template;

    private SalesShareLink $link;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSales();
        $this->people = [
            'owner' => $this->owner,
            'manager' => $this->memberOf($this->company, 'admin'),
            'accountant' => $this->memberOf($this->company, 'editor'),
            'bookkeeper' => $this->memberOf($this->company, 'editor'),
            'approver' => $this->memberOf($this->company, 'viewer'),
            'viewer' => $this->memberOf($this->company, 'viewer'),
        ];
        foreach (['accountant' => FinanceRole::Accountant, 'approver' => FinanceRole::Approver] as $who => $role) {
            LedgerMemberRole::create(['team_id' => $this->company->id, 'member_user_id' => $this->people[$who]->id, 'role' => $role->value, 'granted_by' => $this->owner->id, 'granted_at' => now()]);
        }

        $this->issued = $this->issued([$this->line('Work', '1', 500_000)]);
        $this->draft = $this->draft();
        $this->credit = LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->draftCreditNote($this->issued, $this->owner, 'Returned'));
        $out = LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->receive($this->ledger, $this->owner, [
            'party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => 100_000, 'paid_on' => now()->toDateString(), 'idempotency_key' => 'matrix',
            'allocations' => [['document_id' => $this->issued->id, 'amount_cents' => 50_000]],
        ]));
        $this->payment = $out['payment'];
        $this->application = $out['applications'][0];
        $this->template = SalesRecurringTemplate::create($this->ledger->ownerAttributes() + ['party_id' => $this->customer->id, 'name' => 'T', 'frequency' => 'monthly', 'every' => 1, 'start_date' => now()->toDateString(), 'next_run_date' => now()->addMonth()->toDateString(),
            'currency' => 'ZAR', 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_cents' => 100, 'tax_code' => 'ZA_VAT_STD']], 'created_by' => $this->owner->id]);
        $this->link = LedgerContext::run($this->ledger, fn () => app(ShareLinks::class)->forDocument($this->issued, $this->owner)['link']);
    }

    /** @return array<string, array{0: string, 1: string, 2: callable(): array, 3: callable(): array, 4: list<string>}> */
    private function matrix(): array
    {
        $none = fn () => [];

        return [
            // reads: everyone who may read the books
            'GET invoices' => ['GET', 'sales.invoices.index', $none, $none, self::ALL],
            'GET quotes' => ['GET', 'sales.quotes.index', $none, $none, self::ALL],
            'GET credit notes' => ['GET', 'sales.credit-notes.index', $none, $none, self::ALL],
            'GET document' => ['GET', 'sales.documents.show', fn () => ['document' => $this->issued], $none, self::ALL],
            'GET print' => ['GET', 'sales.documents.print', fn () => ['document' => $this->issued], $none, self::ALL],
            'GET pdf' => ['GET', 'sales.documents.pdf', fn () => ['document' => $this->issued], $none, self::ALL],
            'GET payments' => ['GET', 'sales.payments.index', $none, $none, self::ALL],
            'GET payment' => ['GET', 'sales.payments.show', fn () => ['payment' => $this->payment], $none, self::ALL],
            'GET customers' => ['GET', 'sales.customers.index', $none, $none, self::ALL],
            'GET customer' => ['GET', 'sales.customers.show', fn () => ['party' => $this->customer], $none, self::ALL],
            'GET statement' => ['GET', 'sales.customers.statement', fn () => ['party' => $this->customer], $none, self::ALL],
            'GET statement csv' => ['GET', 'sales.customers.statement.csv', fn () => ['party' => $this->customer], $none, self::ALL],
            'GET statement pdf' => ['GET', 'sales.customers.statement.pdf', fn () => ['party' => $this->customer], $none, self::ALL],
            'GET receivables' => ['GET', 'sales.receivables.index', $none, $none, self::ALL],
            'GET reminders' => ['GET', 'sales.reminders.index', $none, $none, self::ALL],
            'GET logo' => ['GET', 'sales.profile.logo.show', $none, $none, self::ALL],    // 404 when there is none; never 403 for a reader

            // drafting and issuing: issue-invoice
            'GET create invoice' => ['GET', 'sales.invoices.create', $none, $none, self::ISSUERS],
            'GET create quote' => ['GET', 'sales.quotes.create', $none, $none, self::ISSUERS],
            'POST calculate' => ['POST', 'sales.calculate', $none, fn () => $this->body(), self::ISSUERS],
            'POST store' => ['POST', 'sales.documents.store', $none, fn () => $this->body(), self::ISSUERS],
            'GET edit' => ['GET', 'sales.documents.edit', fn () => ['document' => $this->draft], $none, self::ISSUERS],
            'PUT update' => ['PUT', 'sales.documents.update', fn () => ['document' => $this->draft], fn () => $this->body(), self::ISSUERS],
            'POST issue' => ['POST', 'sales.documents.issue', fn () => ['document' => $this->draft], $none, self::ISSUERS],
            'POST void' => ['POST', 'sales.documents.void', fn () => ['document' => $this->issued([$this->line('V', '1', 1_000)])], fn () => ['reason' => 'x'], self::ISSUERS],
            'POST send' => ['POST', 'sales.documents.send', fn () => ['document' => $this->issued], fn () => ['to' => 'a@b.test'], self::ISSUERS],
            'POST share' => ['POST', 'sales.documents.share', fn () => ['document' => $this->issued], $none, self::ISSUERS],
            'POST credit note' => ['POST', 'sales.documents.credit', fn () => ['document' => $this->issued], $none, self::ISSUERS],
            'POST debit note' => ['POST', 'sales.documents.debit', fn () => ['document' => $this->issued], $none, self::ISSUERS],
            'POST convert' => ['POST', 'sales.documents.convert', fn () => ['document' => $this->issued], $none, self::ISSUERS],
            'POST answer' => ['POST', 'sales.documents.answer', fn () => ['document' => $this->issued], fn () => ['answer' => 'accepted'], self::ISSUERS],
            'DELETE discard' => ['DELETE', 'sales.documents.discard', fn () => ['document' => $this->draft()], $none, self::ISSUERS],
            'POST recurring store' => ['POST', 'sales.recurring.store', $none, fn () => ['name' => 'N', 'party_id' => $this->customer->id, 'frequency' => 'monthly', 'every' => 1, 'start_date' => now()->toDateString(), 'mode' => 'draft', 'terms_days' => 30, 'currency' => 'ZAR', 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '10.00', 'tax_code' => 'ZA_VAT_STD']]], self::ISSUERS],
            'PUT recurring update' => ['PUT', 'sales.recurring.update', fn () => ['template' => $this->template], fn () => ['name' => 'N2', 'party_id' => $this->customer->id, 'frequency' => 'monthly', 'every' => 1, 'start_date' => now()->toDateString(), 'mode' => 'draft', 'terms_days' => 30, 'currency' => 'ZAR', 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '10.00', 'tax_code' => 'ZA_VAT_STD']]], self::ISSUERS],
            'POST link income' => ['POST', 'sales.customers.link-transaction', fn () => ['party' => $this->customer], fn () => ['transaction_id' => 999999], self::ISSUERS],
            'POST revoke link' => ['POST', 'sales.links.revoke', fn () => ['link' => $this->link], $none, self::ISSUERS],
            'GET recurring' => ['GET', 'sales.recurring.index', $none, $none, self::ISSUERS],
            'GET recurring create' => ['GET', 'sales.recurring.create', $none, $none, self::ISSUERS],
            'GET recurring edit' => ['GET', 'sales.recurring.edit', fn () => ['template' => $this->template], $none, self::ISSUERS],
            'POST recurring status' => ['POST', 'sales.recurring.status', fn () => ['template' => $this->template], fn () => ['status' => 'paused'], self::ISSUERS],
            'POST recurring run' => ['POST', 'sales.recurring.run', $none, $none, self::ISSUERS],
            'POST reminders opt-out' => ['POST', 'sales.customers.reminders', fn () => ['party' => $this->customer], fn () => ['opt_out' => 1], self::ISSUERS],
            'POST statement link' => ['POST', 'sales.customers.statement.share', fn () => ['party' => $this->customer], $none, self::ISSUERS],

            // money in: record-payment
            'GET payment form' => ['GET', 'sales.payments.create', $none, $none, self::ISSUERS],
            'POST payment' => ['POST', 'sales.payments.store', $none, fn () => ['party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount' => '10.00', 'paid_on' => now()->toDateString(), 'idempotency_key' => 'm-'.uniqid()], self::ISSUERS],
            'POST allocate' => ['POST', 'sales.payments.allocate', fn () => ['payment' => $this->payment], fn () => ['document_id' => $this->issued->id, 'amount' => '1.00'], self::ISSUERS],
            'POST apply credit' => ['POST', 'sales.documents.apply-credit', fn () => ['document' => $this->issued], fn () => ['credit_id' => $this->credit->id, 'amount' => '1.00'], self::ISSUERS],
            'POST release' => ['POST', 'sales.applications.release', fn () => ['application' => $this->application], fn () => ['reason' => 'x'], self::ISSUERS],
            'POST reverse payment' => ['POST', 'sales.payments.reverse', fn () => ['payment' => $this->payment], fn () => ['reason' => 'x'], self::ISSUERS],

            // refunds are money out: approve-payment
            'POST refund' => ['POST', 'sales.refunds.store', $none, fn () => ['source_payment_id' => $this->payment->id, 'amount' => '1.00', 'cash_account_id' => $this->bank->id, 'paid_on' => now()->toDateString(), 'reason' => 'x', 'idempotency_key' => 'r-'.uniqid()], ['owner', 'manager', 'approver']],

            // writing off: needs approve-invoice. (A bookkeeper or accountant passes the policy but the service answers 403 unless
            // an approval rule covers the amount, when they may ask: see WriteOffAndQuoteTest.)
            'POST write-off' => ['POST', 'sales.documents.write-off', fn () => ['document' => $this->issued], fn () => ['amount' => '1.00', 'reason' => 'x'], ['owner', 'manager', 'approver']],

            // tax settings: manage-tax
            'GET profile' => ['GET', 'sales.profile.edit', $none, $none, ['owner', 'manager', 'accountant']],
            'PUT profile' => ['PUT', 'sales.profile.update', $none, fn () => ['default_terms_days' => 30, 'quote_validity_days' => 30, 'reminder_before_days' => 3], ['owner', 'manager', 'accountant']],
            'POST logo' => ['POST', 'sales.profile.logo', $none, $none, ['owner', 'manager', 'accountant']],

            // export: export-reports
            'GET receivables csv' => ['GET', 'sales.receivables.csv', $none, $none, ['owner', 'manager', 'accountant', 'bookkeeper']],
        ];
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return ['kind' => 'tax_invoice', 'party_id' => $this->customer->id, 'currency' => 'ZAR', 'issue_date' => now()->toDateString(), 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '10.00', 'tax_code' => 'ZA_VAT_STD']]];
    }

    public function test_every_sales_route_is_in_the_matrix(): void
    {
        $covered = collect($this->matrix())->pluck(1)->all();
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'sales.') && ! str_starts_with($r->getName(), 'sales.share.'))
            ->map(fn ($r) => $r->getName())->unique()->values()->all();

        $this->assertSame([], array_values(array_diff($routes, $covered)), 'These sales routes have no row in the capability matrix');
    }

    public function test_each_role_gets_exactly_what_the_matrix_says(): void
    {
        $failures = [];
        $cases = 0;

        foreach ($this->matrix() as $label => [$method, $name, $params, $payload, $allowed]) {
            foreach (self::ALL as $role) {
                // a fresh start for every attempt: some actions change the state the next ones depend on
                $url = route($name, $params(), false);
                $data = $payload();
                $before = $this->snapshot();
                $response = $this->inCompany($this->people[$role], $this->company)->call($method, $url, $data);
                $cases++;

                $denied = $response->getStatusCode() === 403;
                if (in_array($role, $allowed, true) && $denied) {
                    $failures[] = "{$role} should be allowed {$label} but got 403";
                }
                if (! in_array($role, $allowed, true)) {
                    if (! $denied) {
                        $failures[] = "{$role} should be refused {$label} but got ".$response->getStatusCode();
                    } elseif ($this->snapshot() !== $before) {
                        $failures[] = "{$role} was refused {$label} but something was written";
                    }
                }
                if ($response->getStatusCode() >= 500) {
                    $failures[] = "{$role} {$label} answered ".$response->getStatusCode();
                }
            }
        }

        $this->assertSame([], $failures);
        $this->assertGreaterThan(250, $cases);
    }

    private function snapshot(): string
    {
        return md5(json_encode([
            DB::table('sales_documents')->count(), DB::table('payments')->count(), DB::table('journal_entries')->count(),
            DB::table('sales_applications')->whereNotNull('released_at')->count(), DB::table('sales_share_links')->whereNotNull('revoked_at')->count(),
            DB::table('sales_write_offs')->count(), DB::table('sales_recurring_templates')->pluck('status', 'id'),
            DB::table('sales_profiles')->pluck('version'), DB::table('sales_party_prefs')->count(),
        ]));
    }
}
