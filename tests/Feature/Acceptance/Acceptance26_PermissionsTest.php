<?php

namespace Tests\Feature\Acceptance;

use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Category;
use App\Models\Controls\ApprovalPolicy;
use App\Models\Controls\LedgerMemberRole;
use App\Models\Planning\PlanBudget;
use App\Models\Purchases\Bill;
use App\Models\Sales\SalesDocument;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Models\Tax\TaxProfile;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Controls\ApprovalService;

/**
 * Owner criterion 26: user permissions are enforced. A written-out table of who may do which representative action in each module
 * (not derived from the code under test) is run through real HTTP requests for the six finance roles: a role that may not gets 403
 * and changes nothing, a role that may is not refused. Role changes take effect on the next request and are audited, and a person
 * removed from the company loses the ledger at once.
 */
class Acceptance26_PermissionsTest extends AcceptanceCase
{
    /** @var array<string, User> */
    private array $people = [];

    private Account $emptyAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMonth(false);
        $this->emptyAccount = Account::factory()->forCompany($this->company)->create(['name' => 'Empty one']);
        $this->people = [
            'owner' => $this->owner, 'manager' => $this->memberAs('manager'), 'accountant' => $this->memberAs('accountant'),
            'bookkeeper' => $this->memberAs('bookkeeper'), 'approver' => $this->memberAs('approver'), 'viewer' => $this->memberAs('viewer'),
        ];
    }

    private const ALL = ['owner', 'manager', 'accountant', 'bookkeeper', 'approver', 'viewer'];

    private const FINANCE_TEAM = ['owner', 'manager', 'accountant', 'bookkeeper'];

    private const BOOKS_KEEPERS = ['owner', 'manager', 'accountant'];

    private const OWNERS = ['owner', 'manager'];

    /** Real, VALID requests: a role that is refused must be refused for authorisation, not validation. @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: list<string>}> */
    private function actions(): array
    {
        $gl = $this->gl('6190')->id;
        $other = $this->gl('4000')->id;

        return [
            'record a transaction (record-entries)' => ['post', route('transactions.store'), ['account_id' => $this->bank->id, 'description' => 'Perm test', 'amount' => '1.00', 'type' => 'expense', 'date' => '2026-11-12'], self::FINANCE_TEAM],
            'add a category (edit-structure)' => ['post', route('categories.store'), ['name' => 'Perm cat', 'type' => 'expense'], self::FINANCE_TEAM],
            'delete an account (destroy-structure)' => ['delete', route('accounts.destroy', $this->emptyAccount), [], self::OWNERS],
            'adjust an opening balance (adjust-opening-balance)' => ['post', route('accounts.opening-balance', $this->bank), ['opening_balance' => '100000.00', 'opening_balance_date' => '2026-10-01'], self::OWNERS],
            'add a chart account (manage-chart)' => ['post', route('accounting.chart.store'), ['code' => '6899', 'name' => 'Perm account', 'class' => 'expense'], self::BOOKS_KEEPERS],
            'post a manual journal (post-journal)' => ['post', route('accounting.journals.store'), ['entry_date' => '2026-11-12', 'memo' => 'Perm journal', 'lines' => [['account_id' => $gl, 'debit' => '5.00', 'credit' => ''], ['account_id' => $other, 'debit' => '', 'credit' => '5.00']]], self::BOOKS_KEEPERS],
            'close a period (close-period)' => ['post', route('accounting.periods.close-through'), ['through' => '2026-09'], self::BOOKS_KEEPERS],
            'add a customer (manage-parties)' => ['post', route('parties.store'), ['type' => 'customer', 'name' => 'Perm customer'], self::FINANCE_TEAM],
            'draft an invoice (issue-invoice)' => ['post', route('sales.documents.store'), ['kind' => 'tax_invoice', 'party_id' => $this->acme->id, 'currency' => 'ZAR', 'issue_date' => '2026-11-12', 'lines' => [['description' => 'Perm', 'quantity' => '1', 'unit_price' => '10.00', 'tax_code' => 'ZA_VAT_STD']]], self::FINANCE_TEAM],
            'capture a bill (enter-bill)' => ['post', route('purchases.bills.store'), ['party_id' => $this->paperCo->id, 'bill_date' => '2026-11-12', 'supplier_reference' => 'PERM-1', 'document_type' => 'tax_invoice', 'lines' => [['description' => 'Perm', 'gl_account_id' => $gl, 'quantity' => '1', 'unit_price' => '10.00', 'tax_code' => 'ZA_VAT_STD']]], self::FINANCE_TEAM],
            'record a customer payment (record-payment)' => ['post', route('sales.payments.store'), ['party_id' => $this->acme->id, 'cash_account_id' => $this->bank->id, 'amount' => '10.00', 'paid_on' => '2026-11-12', 'idempotency_key' => 'perm-pay'], self::FINANCE_TEAM],
            'change the tax profile (manage-tax)' => ['put', route('tax.profile.update'), ['jurisdiction' => 'ZA', 'legal_entity' => 'company', 'tax_regime' => 'standard', 'vat_registered' => '1', 'vat_number' => '4123456789', 'vat_category' => 'A', 'vat_basis' => 'invoice', 'financial_year_end_month' => 2, 'accounting_basis' => 'accrual'], self::BOOKS_KEEPERS],
            'start a VAT return (prepare-tax-return)' => ['post', route('tax.vat.start'), ['period_start' => '2026-10-01'], self::BOOKS_KEEPERS],
            'create a plan (manage-budgets)' => ['post', route('planning.budgets.store'), ['name' => 'Perm plan', 'starts_on' => '2026-12-01', 'months' => 1], self::FINANCE_TEAM],
            'export a report (export-reports)' => ['post', route('reports.exports.store'), ['report' => 'profit-loss', 'format' => 'csv', 'from' => '2026-11-01', 'to' => '2026-11-30'], self::FINANCE_TEAM],
            'set an approval policy (manage-approvals)' => ['post', route('controls.policies.store'), ['subject_type' => 'bill', 'name' => 'Perm policy', 'threshold' => '1000.00', 'currency' => 'ZAR', 'steps_required' => 1, 'approver_capability' => 'approve-bill'], self::OWNERS],
            'import a bank statement (run-reconciliation)' => ['get', route('bank.import.create'), [], self::FINANCE_TEAM],
            'read the audit log (view-audit)' => ['get', route('audit.index'), [], self::BOOKS_KEEPERS],
            'read members and roles' => ['get', route('controls.members.index'), [], self::BOOKS_KEEPERS],
            'read the books (view-finance)' => ['get', route('reports.profit-loss', ['from' => '2026-11-01', 'to' => '2026-11-30']), [], self::ALL],
        ];
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $count = fn (string $model) => $model::query()->forContext($this->ledger)->count();

        return [
            'transactions' => $count(Transaction::class), 'journals' => $count(JournalEntry::class), 'categories' => $count(Category::class), 'accounts' => $count(Account::class),
            'parties' => $count(Party::class), 'invoices' => $count(SalesDocument::class), 'bills' => $count(Bill::class), 'payments' => $count(Payment::class), 'plans' => $count(PlanBudget::class),
            'policies' => $count(ApprovalPolicy::class), 'chart' => GlAccount::query()->forContext($this->ledger)->count(),
        ];
    }

    public function test_a_role_that_may_not_is_refused_with_403_and_changes_nothing(): void
    {
        $before = $this->counts();
        $vat = TaxProfile::query()->forContext($this->ledger)->firstOrFail()->vat_category;
        $refusals = 0;

        foreach ($this->actions() as $name => [$method, $url, $body, $allowed]) {
            foreach (array_diff(self::ALL, $allowed) as $role) {
                $this->asMember($this->people[$role])->{$method}($url, $body)->assertForbidden("{$role} must be refused: {$name}");
                $refusals++;
            }
        }

        $this->assertGreaterThan(40, $refusals);
        $this->assertSame($before, $this->counts(), 'a refused request wrote nothing');
        $this->assertSame($vat, TaxProfile::query()->forContext($this->ledger)->firstOrFail()->vat_category);
    }

    public function test_a_role_that_may_is_not_refused(): void
    {
        $granted = 0;
        foreach ($this->actions() as $name => [$method, $url, $body, $allowed]) {
            foreach ($allowed as $role) {
                if ($role === 'owner' && in_array($name, ['close a period (close-period)', 'delete an account (destroy-structure)'], true)) {
                    continue;      // these change shared state for every later role; the owner's right is shown by the matrix of the other roles
                }
                $status = $this->asMember($this->people[$role])->{$method}($url, $body)->getStatusCode();
                $this->assertNotSame(403, $status, "{$role} must be allowed: {$name}");
                $this->assertNotSame(500, $status, "{$role}: {$name}");
                $granted++;
            }
        }
        $this->assertGreaterThan(40, $granted);
    }

    public function test_the_approver_can_only_approve_and_never_enter_and_the_one_who_enters_cannot_approve(): void
    {
        ApprovalPolicy::create($this->ledger->ownerAttributes() + ['subject_type' => 'bill', 'name' => 'All bills', 'threshold_cents' => 0, 'currency' => 'ZAR', 'approver_capability' => 'approve-bill', 'steps_required' => 1, 'is_active' => true]);
        $this->asMember($this->people['bookkeeper'])->post(route('purchases.bills.store'), ['party_id' => $this->paperCo->id, 'bill_date' => '2026-11-12', 'supplier_reference' => 'AP-1', 'document_type' => 'tax_invoice', 'submit_now' => '1', 'client_token' => 'perm-ap',
            'lines' => [['description' => 'x', 'gl_account_id' => $this->gl('6190')->id, 'quantity' => '1', 'unit_price' => '10.00', 'tax_code' => 'ZA_VAT_STD']]])->assertSessionHasNoErrors();
        $bill = Bill::query()->forContext($this->ledger)->where('supplier_reference', 'AP-1')->firstOrFail();
        $request = app(ApprovalService::class)->latestFor($bill);

        foreach (['bookkeeper', 'viewer'] as $role) {
            $this->asMember($this->people[$role])->post(route('controls.approvals.approve', $request))->assertForbidden();
        }
        $this->assertSame('awaiting_approval', $bill->fresh()->status);
        $this->asMember($this->people['approver'])->post(route('purchases.bills.store'), ['party_id' => $this->paperCo->id, 'bill_date' => '2026-11-12', 'lines' => []])->assertForbidden();
        $this->asMember($this->people['approver'])->post(route('controls.approvals.approve', $request))->assertSessionHasNoErrors();
        $this->assertSame('open', $bill->fresh()->status);
    }

    public function test_a_role_change_takes_effect_on_the_next_request_and_is_audited_and_removal_ends_access_at_once(): void
    {
        $bookkeeper = $this->people['bookkeeper'];
        $this->asMember($bookkeeper)->post(route('transactions.store'), ['account_id' => $this->bank->id, 'description' => 'Before demotion', 'amount' => '1.00', 'type' => 'expense', 'date' => '2026-11-12'])->assertSessionHasNoErrors();

        $this->asOwner()->put(route('controls.members.update', $bookkeeper->id), ['role' => 'viewer'])->assertSessionHasNoErrors();
        $this->asMember($bookkeeper)->post(route('transactions.store'), ['account_id' => $this->bank->id, 'description' => 'After demotion', 'amount' => '1.00', 'type' => 'expense', 'date' => '2026-11-12'])->assertForbidden();
        $this->assertSame(0, Transaction::query()->forContext($this->ledger)->where('description', 'After demotion')->count());
        $audit = $this->auditRows(null, 'member_role');
        $this->assertCount(1, $audit);
        $this->assertSame([$this->owner->id, 'member:'.$bookkeeper->id], [(int) $audit[0]->actor_user_id, $audit[0]->context], 'who changed whose role is on the record');
        $this->assertSame('viewer', $audit[0]->changes['role']['after'] ?? json_encode($audit[0]->changes));
        $this->assertSame('viewer', LedgerMemberRole::query()->where('team_id', $this->company->id)->where('member_user_id', $bookkeeper->id)->first()->role->value);

        // nobody can change roles without manage-members, and the owner's own role cannot be demoted by a manager
        $this->asMember($this->people['accountant'])->put(route('controls.members.update', $this->people['viewer']->id), ['role' => 'owner'])->assertForbidden();
        $this->asMember($this->people['manager'])->put(route('controls.members.update', $this->owner->id), ['role' => 'viewer'])->assertSessionHasErrors('controls');

        // removed from the company: the ledger is no longer theirs
        $this->company->users()->detach($this->people['accountant']->id);
        $this->asMember($this->people['accountant'])->get(route('reports.profit-loss', ['from' => '2026-11-01', 'to' => '2026-11-30']))->assertDontSee('Harbour Trading');
        $this->asMember($this->people['accountant'])->post(route('accounting.chart.store'), ['code' => '6898', 'name' => 'Removed', 'class' => 'expense'])->assertStatus(302);
        $this->assertNull(GlAccount::query()->forContext($this->ledger)->where('code', '6898')->first());
    }

    public function test_an_unauthenticated_request_is_sent_to_sign_in_for_every_module(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        foreach ($this->actions() as $name => [$method, $url, $body]) {
            $this->{$method}($url, $body)->assertRedirect('/login');
        }
    }
}
