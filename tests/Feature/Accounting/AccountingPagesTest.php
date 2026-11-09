<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\LedgerSetting;
use App\Models\Category;
use App\Models\FinanceAuditLog;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\ChartTemplates;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAccounting;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

class AccountingPagesTest extends TestCase
{
    use BuildsAccounting;
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private User $owner;

    private Team $company;

    private LedgerContext $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->user();
        $this->company = $this->company($this->owner);
        $this->ctx = $this->companyContext($this->company);

        $account = $this->companyAccount($this->company, ['name' => 'Business cheque', 'opening_balance_cents' => 200_000, 'opening_balance_date' => '2026-01-01']);
        $sales = $this->companyCategory($this->company, ['name' => 'Consulting', 'type' => 'income']);
        Transaction::factory()->create(['account_id' => $account->id, 'category_id' => $sales->id, 'type' => 'income', 'amount_cents' => 50_000, 'date' => '2026-02-02']);
        $this->enableAccounting($this->ctx, 'ZAR', ChartTemplates::COMPANY);
    }

    private function asRole(string $role): static
    {
        $user = $role === 'owner' ? $this->owner : $this->memberOf($this->company, $role);

        return $this->inCompany($user, $this->company);
    }

    private function glId(string $name): int
    {
        return GlAccount::withoutGlobalScopes()->where('team_id', $this->company->id)->where('name', $name)->firstOrFail()->id;
    }

    private function manual(array $overrides = []): array
    {
        return array_merge([
            'entry_date' => '2026-03-01', 'memo' => 'Owner loan',
            'lines' => [
                ['account_id' => $this->glId('Share capital'), 'debit' => '', 'credit' => '1000.00', 'description' => 'Capital'],
                ['account_id' => $this->glId('Bank charges'), 'debit' => '1000.00', 'credit' => '', 'description' => ''],
                ['account_id' => '', 'debit' => '', 'credit' => '', 'description' => ''],
            ],
        ], $overrides);
    }

    public function test_every_role_can_read_every_accounting_page(): void
    {
        $entry = JournalEntry::withoutGlobalScopes()->where('team_id', $this->company->id)->firstOrFail();

        foreach (['owner', 'admin', 'editor', 'viewer'] as $role) {
            foreach ([
                route('accounting.trial-balance'), route('accounting.general-ledger'), route('accounting.journals.index'), route('accounting.journals.show', $entry),
                route('accounting.chart.index'), route('accounting.periods.index'), route('accounting.general-ledger', ['account' => $this->glId('Sales')]),
            ] as $url) {
                $this->asRole($role)->get($url)->assertOk();
            }
        }
    }

    public function test_the_trial_balance_shows_the_books_and_that_they_balance(): void
    {
        $this->asRole('owner')->get(route('accounting.trial-balance'))
            ->assertOk()
            ->assertSee('Trial balance')
            ->assertSee('in balance')
            ->assertSee('Consulting')
            ->assertSee('Business cheque')->assertDontSee('OUT OF BALANCE');
    }

    public function test_the_general_ledger_traces_a_line_to_its_entry_and_the_entry_to_its_transaction(): void
    {
        $consulting = $this->glId('Consulting');
        $page = $this->asRole('owner')->get(route('accounting.general-ledger', ['account' => $consulting]));
        $page->assertOk()->assertSee('JE-0000');

        $entry = JournalEntry::withoutGlobalScopes()->where('team_id', $this->company->id)->where('source_type', 'transaction')->firstOrFail();
        $this->asRole('owner')->get(route('accounting.journals.show', $entry))->assertOk()->assertSee('Transaction #'.$entry->source_id)->assertSee(route('transactions.edit', $entry->source_id), false);
    }

    public function test_when_accounting_is_off_a_manager_is_sent_to_the_wizard_and_others_are_told_why(): void
    {
        $bare = $this->user();
        $ctx = LedgerContext::personal($bare);

        $this->inPersonal($bare)->get(route('accounting.trial-balance'))->assertRedirect(route('accounting.setup'));

        $team = $this->company($this->owner, 'Second Ltd');
        $viewer = $this->memberOf($team, 'viewer');
        $this->inCompany($viewer, $team)->get(route('accounting.trial-balance'))->assertOk()->assertSee('not switched on');
        $this->inCompany($viewer, $team)->get(route('accounting.setup'))->assertForbidden();
        $this->assertFalse(JournalEntry::withoutGlobalScopes()->where('team_id', $team->id)->exists());
        $this->assertNotNull($ctx);
    }

    // ── manual journals ─────────────────────────────────────────────────────

    public function test_an_editor_can_post_a_balanced_manual_entry(): void
    {
        $this->asRole('editor')->post(route('accounting.journals.store'), $this->manual())->assertSessionHasNoErrors()->assertRedirect();

        $entry = JournalEntry::withoutGlobalScopes()->where('team_id', $this->company->id)->where('source_type', 'manual')->firstOrFail();
        $this->assertSame(100_000, $entry->total_cents);
        $this->assertCount(2, $entry->lines()->withoutGlobalScopes()->get());
        $this->assertNotNull($entry->posted_by_user_id);
    }

    public function test_an_unbalanced_manual_entry_is_refused_by_the_server_with_nothing_written(): void
    {
        $before = JournalEntry::withoutGlobalScopes()->count();
        $payload = $this->manual();
        $payload['lines'][1]['debit'] = '999.99';

        $this->asRole('owner')->post(route('accounting.journals.store'), $payload)->assertSessionHasErrors('entry');

        $this->assertSame($before, JournalEntry::withoutGlobalScopes()->count());
    }

    public function test_a_line_with_both_sides_or_neither_is_refused(): void
    {
        $payload = $this->manual();
        $payload['lines'][0]['debit'] = '5.00';

        $this->asRole('owner')->post(route('accounting.journals.store'), $payload)->assertSessionHasErrors();
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->where('source_type', 'manual')->count());
    }

    public function test_a_single_line_entry_is_refused(): void
    {
        $payload = $this->manual();
        $payload['lines'] = [$payload['lines'][0]];

        $this->asRole('owner')->post(route('accounting.journals.store'), $payload)->assertSessionHasErrors();
    }

    public function test_money_fields_reject_exponents_and_extra_decimals(): void
    {
        foreach (['1e3', '12.345', '-5', 'abc'] as $bad) {
            $payload = $this->manual();
            $payload['lines'][0]['credit'] = $bad;
            $this->asRole('owner')->post(route('accounting.journals.store'), $payload)->assertSessionHasErrors('lines.0.credit');
        }
    }

    public function test_a_manual_entry_in_a_closed_period_is_refused(): void
    {
        $this->asRole('owner')->post(route('accounting.periods.close-through'), ['through' => '2026-02'])->assertRedirect(route('accounting.periods.index'));

        $this->asRole('owner')->post(route('accounting.journals.store'), $this->manual(['entry_date' => '2026-02-15']))->assertSessionHasErrors('entry');
        $this->asRole('owner')->post(route('accounting.journals.store'), $this->manual(['entry_date' => '2026-03-15']))->assertSessionHasNoErrors();
    }

    public function test_a_manual_line_on_a_cash_accounts_gl_account_is_refused(): void
    {
        $payload = $this->manual();
        $payload['lines'][1]['account_id'] = $this->glId('Business cheque');

        $this->asRole('owner')->post(route('accounting.journals.store'), $payload)->assertSessionHasErrors('lines');
    }

    public function test_a_manual_entry_can_be_reversed_but_a_transaction_entry_cannot(): void
    {
        $this->asRole('owner')->post(route('accounting.journals.store'), $this->manual());
        $manual = JournalEntry::withoutGlobalScopes()->where('source_type', 'manual')->firstOrFail();
        $fromTransaction = JournalEntry::withoutGlobalScopes()->where('source_type', 'transaction')->firstOrFail();

        $this->asRole('owner')->post(route('accounting.journals.reverse', $fromTransaction), ['reason' => 'nope'])->assertSessionHasErrors('reason');
        $this->assertSame('posted', $fromTransaction->refresh()->status);

        $this->asRole('owner')->post(route('accounting.journals.reverse', $manual), ['reason' => 'Typo'])->assertSessionHasNoErrors();
        $this->assertSame('reversed', $manual->refresh()->status);

        $this->asRole('owner')->post(route('accounting.journals.reverse', $manual), ['reason' => 'Again'])->assertSessionHasErrors('reason');
        $this->asRole('owner')->post(route('accounting.journals.reverse', $manual), [])->assertSessionHasErrors('reason');
    }

    public function test_there_is_no_route_that_edits_or_deletes_a_journal_entry(): void
    {
        $entry = JournalEntry::withoutGlobalScopes()->firstOrFail();

        foreach (['put', 'patch', 'delete'] as $verb) {
            $this->asRole('owner')->{$verb}(route('accounting.journals.show', $entry))->assertStatus(405);
        }
    }

    // ── who may do what ─────────────────────────────────────────────────────

    public function test_a_viewer_can_change_nothing(): void
    {
        $period = AccountingPeriod::withoutGlobalScopes()->where('team_id', $this->company->id)->firstOrFail();
        $account = GlAccount::withoutGlobalScopes()->where('team_id', $this->company->id)->firstOrFail();
        $entry = JournalEntry::withoutGlobalScopes()->where('team_id', $this->company->id)->where('source_type', 'transaction')->firstOrFail();

        $this->asRole('viewer')->get(route('accounting.journals.create'))->assertForbidden();
        $this->asRole('viewer')->post(route('accounting.journals.store'), $this->manual())->assertForbidden();
        $this->asRole('viewer')->post(route('accounting.journals.reverse', $entry), ['reason' => 'x'])->assertForbidden();
        $this->asRole('viewer')->post(route('accounting.periods.close', $period))->assertForbidden();
        $this->asRole('viewer')->post(route('accounting.periods.close-through'), ['through' => '2026-02'])->assertForbidden();
        $this->asRole('viewer')->post(route('accounting.periods.reopen', $period), ['reason' => 'x'])->assertForbidden();
        $this->asRole('viewer')->get(route('accounting.chart.create'))->assertForbidden();
        $this->asRole('viewer')->post(route('accounting.chart.store'), ['code' => '6999', 'name' => 'X', 'class' => 'expense'])->assertForbidden();
        $this->asRole('viewer')->put(route('accounting.chart.update', $account), ['code' => '1', 'name' => 'x'])->assertForbidden();
        $this->asRole('viewer')->post(route('accounting.chart.deactivate', $account))->assertForbidden();
        $this->asRole('viewer')->post(route('accounting.setup.dry-run'))->assertForbidden();
        $this->asRole('viewer')->post(route('accounting.setup.apply'))->assertForbidden();
    }

    public function test_an_editor_posts_entries_but_cannot_manage_the_chart_or_close_periods(): void
    {
        $period = AccountingPeriod::withoutGlobalScopes()->where('team_id', $this->company->id)->firstOrFail();
        $account = GlAccount::withoutGlobalScopes()->where('team_id', $this->company->id)->firstOrFail();

        $this->asRole('editor')->get(route('accounting.journals.create'))->assertOk();
        $this->asRole('editor')->post(route('accounting.chart.store'), ['code' => '6999', 'name' => 'X', 'class' => 'expense'])->assertForbidden();
        $this->asRole('editor')->post(route('accounting.chart.deactivate', $account))->assertForbidden();
        $this->asRole('editor')->post(route('accounting.periods.close', $period))->assertForbidden();
        $this->asRole('editor')->post(route('accounting.periods.close-through'), ['through' => '2026-02'])->assertForbidden();
    }

    public function test_the_nav_and_chart_pages_do_not_offer_a_viewer_actions_they_cannot_use(): void
    {
        $this->asRole('viewer')->get(route('accounting.chart.index'))->assertOk()->assertDontSee('Add an account')->assertDontSee('Deactivate');
        $this->asRole('owner')->get(route('accounting.chart.index'))->assertOk()->assertSee('Add an account');
        $this->asRole('viewer')->get(route('accounting.journals.index'))->assertOk()->assertDontSee('New manual entry');
        $this->asRole('viewer')->get(route('accounting.periods.index'))->assertOk()->assertDontSee('Close every month through');
    }

    // ── chart ───────────────────────────────────────────────────────────────

    public function test_a_manager_adds_edits_and_deactivates_accounts_and_it_is_audited(): void
    {
        $this->asRole('owner')->post(route('accounting.chart.store'), ['code' => '6999', 'name' => 'Training', 'class' => 'expense'])->assertSessionHasNoErrors()->assertRedirect(route('accounting.chart.index'));
        $account = GlAccount::withoutGlobalScopes()->where('team_id', $this->company->id)->where('code', '6999')->firstOrFail();
        $this->assertSame('debit', $account->normal_side);

        $this->asRole('owner')->put(route('accounting.chart.update', $account), ['code' => '6998', 'name' => 'Staff training', 'class' => 'expense'])->assertSessionHasNoErrors();
        $this->assertSame('Staff training', $account->refresh()->name);

        $this->asRole('owner')->post(route('accounting.chart.deactivate', $account))->assertSessionHasNoErrors();
        $this->assertFalse($account->refresh()->is_active);
        $this->asRole('owner')->post(route('accounting.chart.activate', $account));
        $this->assertTrue($account->refresh()->is_active);

        $actions = FinanceAuditLog::withoutGlobalScopes()->where('subject_type', 'gl_account')->orderBy('id')->pluck('action')->all();
        $this->assertSame(['created', 'updated', 'deactivated', 'activated'], $actions);
    }

    public function test_a_duplicate_code_and_a_bad_class_are_refused(): void
    {
        $this->asRole('owner')->post(route('accounting.chart.store'), ['code' => '4000', 'name' => 'Dup', 'class' => 'income'])->assertSessionHasErrors('code');
        $this->asRole('owner')->post(route('accounting.chart.store'), ['code' => '6997', 'name' => 'Odd', 'class' => 'banana'])->assertSessionHasErrors('class');
        $this->asRole('owner')->post(route('accounting.chart.store'), ['code' => 'bad code!', 'name' => 'Odd', 'class' => 'expense'])->assertSessionHasErrors('code');
    }

    public function test_system_and_mapped_accounts_cannot_be_deactivated_and_used_accounts_keep_their_class(): void
    {
        $vat = GlAccount::withoutGlobalScopes()->where('team_id', $this->company->id)->where('system_key', 'vat_output')->firstOrFail();
        $this->asRole('owner')->post(route('accounting.chart.deactivate', $vat))->assertSessionHasErrors('account');
        $this->assertTrue($vat->refresh()->is_active);

        $consulting = GlAccount::withoutGlobalScopes()->where('team_id', $this->company->id)->where('name', 'Consulting')->firstOrFail();
        $this->asRole('owner')->post(route('accounting.chart.deactivate', $consulting))->assertSessionHasErrors('account'); // mapped to a category

        $this->asRole('owner')->put(route('accounting.chart.update', $consulting), ['code' => $consulting->code, 'name' => 'Consulting', 'class' => 'expense'])->assertSessionHasErrors('class');
        $this->assertSame('income', $consulting->refresh()->class);
    }

    public function test_a_parent_from_another_ledger_is_refused(): void
    {
        $stranger = $this->user();
        $ctx = LedgerContext::personal($stranger);
        $this->enableAccounting($ctx);
        $foreign = GlAccount::withoutGlobalScopes()->where('user_id', $stranger->id)->firstOrFail();

        $this->asRole('owner')->post(route('accounting.chart.store'), ['code' => '6996', 'name' => 'Child', 'class' => 'expense', 'parent_id' => $foreign->id])->assertSessionHasErrors('parent_id');
    }

    // ── periods page ────────────────────────────────────────────────────────

    public function test_a_manager_closes_and_reopens_from_the_periods_page(): void
    {
        $this->asRole('owner')->post(route('accounting.periods.close-through'), ['through' => '2026-02'])->assertSessionHasNoErrors();
        $this->asRole('owner')->get(route('accounting.periods.index'))->assertOk()->assertSee('2026-02-28')->assertSee('Closed');

        $feb = AccountingPeriod::withoutGlobalScopes()->where('team_id', $this->company->id)->where('end_date', '2026-02-28')->firstOrFail();
        $this->asRole('owner')->post(route('accounting.periods.reopen', $feb), [])->assertSessionHasErrors('reason');
        $this->asRole('owner')->post(route('accounting.periods.reopen', $feb), ['reason' => 'Missed an invoice'])->assertSessionHasNoErrors();
        $this->assertSame('open', $feb->refresh()->status);
    }

    public function test_the_current_or_a_future_month_cannot_be_closed(): void
    {
        $this->asRole('owner')->post(route('accounting.periods.close-through'), ['through' => now()->format('Y-m')])->assertSessionHasErrors('through');
        $this->asRole('owner')->post(route('accounting.periods.close-through'), ['through' => 'garbage'])->assertSessionHasErrors('through');
    }

    // ── cross-ledger, explicit ──────────────────────────────────────────────

    public function test_another_ledgers_ids_are_a_404_on_every_accounting_route(): void
    {
        $stranger = $this->user();
        $theirs = LedgerContext::personal($stranger);
        $acct = Account::factory()->create(['user_id' => $stranger->id, 'opening_balance_cents' => 1_000, 'opening_balance_date' => '2026-01-01']);
        Transaction::factory()->create(['account_id' => $acct->id, 'amount_cents' => 100, 'date' => '2026-02-01']);
        $this->enableAccounting($theirs);

        $entry = JournalEntry::withoutGlobalScopes()->where('user_id', $stranger->id)->firstOrFail();
        $gl = GlAccount::withoutGlobalScopes()->where('user_id', $stranger->id)->firstOrFail();
        $period = AccountingPeriod::withoutGlobalScopes()->where('user_id', $stranger->id)->firstOrFail();

        $this->asRole('owner')->get(route('accounting.journals.show', $entry))->assertNotFound();
        $this->asRole('owner')->post(route('accounting.journals.reverse', $entry), ['reason' => 'x'])->assertNotFound();
        $this->asRole('owner')->get(route('accounting.chart.edit', $gl))->assertNotFound();
        $this->asRole('owner')->put(route('accounting.chart.update', $gl), ['code' => '1', 'name' => 'x'])->assertNotFound();
        $this->asRole('owner')->post(route('accounting.chart.deactivate', $gl))->assertNotFound();
        $this->asRole('owner')->post(route('accounting.chart.activate', $gl))->assertNotFound();
        $this->asRole('owner')->post(route('accounting.periods.close', $period))->assertNotFound();
        $this->asRole('owner')->post(route('accounting.periods.reopen', $period), ['reason' => 'x'])->assertNotFound();
        $this->asRole('owner')->get(route('accounting.general-ledger', ['account' => $gl->id]))->assertNotFound();

        // And a posted manual line cannot name the other ledger's account.
        $payload = $this->manual();
        $payload['lines'][0]['account_id'] = $gl->id;
        $this->asRole('owner')->post(route('accounting.journals.store'), $payload)->assertSessionHasErrors('lines.0.account_id');
    }

    public function test_a_person_outside_the_company_sees_nothing_of_its_books(): void
    {
        $outsider = $this->user();
        $entry = JournalEntry::withoutGlobalScopes()->where('team_id', $this->company->id)->firstOrFail();

        // The session claims the company, but the person is not in it: the context cannot be established.
        $this->inCompany($outsider, $this->company)->get(route('accounting.journals.show', $entry))->assertNotFound();
        // ...so the request falls back to the person's own ledger, where accounting is off, and shows none of the company's books.
        $this->inCompany($outsider, $this->company)->get(route('accounting.trial-balance'))->assertRedirect(route('accounting.setup'));
        $this->inCompany($outsider, $this->company)->get(route('accounting.journals.index'))->assertRedirect(route('accounting.setup'));
    }

    public function test_the_personal_pages_never_list_the_companys_accounts(): void
    {
        $this->inPersonal($this->owner)->get(route('accounting.chart.index'))->assertRedirect(route('accounting.setup'));
        $this->enableAccounting(LedgerContext::personal($this->owner));

        $this->inPersonal($this->owner)->get(route('accounting.chart.index'))->assertOk()->assertDontSee('Consulting')->assertDontSee('Share capital');
        $this->inPersonal($this->owner)->get(route('accounting.journals.index'))->assertOk()->assertDontSee('Business cheque');
    }

    // ── the wizard ──────────────────────────────────────────────────────────

    public function test_the_wizard_takes_a_new_ledger_from_off_to_on(): void
    {
        $user = $this->user();
        $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Everyday', 'opening_balance_cents' => 90_000, 'opening_balance_date' => '2026-01-01']);
        $food = Category::factory()->create(['user_id' => $user->id, 'name' => 'Food', 'type' => 'expense']);
        Transaction::factory()->create(['account_id' => $account->id, 'category_id' => $food->id, 'type' => 'expense', 'amount_cents' => 12_000, 'date' => '2026-02-02']);

        $this->inPersonal($user)->get(route('accounting.setup'))->assertOk()->assertSee('Switch on accounting')->assertSee('value="ZAR"', false);
        $this->inPersonal($user)->post(route('accounting.setup.prepare'), ['base_currency' => 'zar', 'fiscal_year_start_month' => 3, 'chart_template' => 'personal'])->assertRedirect(route('accounting.setup.review'));

        $this->inPersonal($user)->get(route('accounting.setup'))->assertRedirect(route('accounting.setup.review'));
        $this->inPersonal($user)->get(route('accounting.setup.review'))->assertOk()->assertSee('Everyday')->assertSee('Food');

        $this->inPersonal($user)->post(route('accounting.setup.dry-run'))->assertRedirect(route('accounting.setup.review'));
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->where('user_id', $user->id)->count(), 'the dry run keeps nothing');
        $this->inPersonal($user)->get(route('accounting.setup.review'))->assertOk()->assertSee('Last dry run');

        $this->inPersonal($user)->post(route('accounting.setup.apply'))->assertRedirect(route('accounting.trial-balance'));
        $this->assertSame(2, JournalEntry::withoutGlobalScopes()->where('user_id', $user->id)->count());
        $this->inPersonal($user)->get(route('accounting.trial-balance'))->assertOk()->assertSee('in balance');
        $this->inPersonal($user)->get(route('accounting.setup'))->assertRedirect(route('accounting.trial-balance'));
    }

    public function test_the_wizard_rejects_a_bad_currency_month_or_template(): void
    {
        $user = $this->user();

        foreach ([['base_currency' => 'RANDS', 'fiscal_year_start_month' => 1, 'chart_template' => 'personal'], ['base_currency' => 'ZAR', 'fiscal_year_start_month' => 13, 'chart_template' => 'personal'], ['base_currency' => 'ZAR', 'fiscal_year_start_month' => 1, 'chart_template' => 'made-up'], ['base_currency' => 'Z1R', 'fiscal_year_start_month' => 1, 'chart_template' => 'personal']] as $bad) {
            $this->inPersonal($user)->post(route('accounting.setup.prepare'), $bad)->assertSessionHasErrors();
        }
        $this->assertSame(0, LedgerSetting::withoutGlobalScopes()->where('user_id', $user->id)->count());
    }

    public function test_mapping_a_cash_account_to_a_liability_or_an_expense_account_is_refused_sensibly(): void
    {
        $user = $this->user();
        $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Everyday']);
        $this->inPersonal($user)->post(route('accounting.setup.prepare'), ['base_currency' => 'ZAR', 'fiscal_year_start_month' => 1, 'chart_template' => 'personal']);
        $expense = GlAccount::withoutGlobalScopes()->where('user_id', $user->id)->where('system_key', 'bank_charges')->firstOrFail();

        $this->inPersonal($user)->post(route('accounting.setup.mapping'), ['accounts' => [$account->id => $expense->id]])->assertSessionHasErrors('mapping');
        $this->assertNotSame($expense->id, $account->refresh()->gl_account_id);

        $other = GlAccount::withoutGlobalScopes()->where('user_id', $user->id)->where('system_key', 'cash')->firstOrFail();
        $this->inPersonal($user)->post(route('accounting.setup.mapping'), ['accounts' => [$account->id => $other->id]])->assertSessionHasNoErrors();
        $this->assertSame($other->id, $account->refresh()->gl_account_id);
    }

    public function test_the_wizard_mapping_refuses_another_ledgers_gl_account(): void
    {
        $user = $this->user();
        $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Everyday']);
        $this->inPersonal($user)->post(route('accounting.setup.prepare'), ['base_currency' => 'ZAR', 'fiscal_year_start_month' => 1, 'chart_template' => 'personal']);
        $before = $account->refresh()->gl_account_id;
        $foreign = GlAccount::withoutGlobalScopes()->where('team_id', $this->company->id)->where('system_key', 'cash')->firstOrFail();

        $this->inPersonal($user)->post(route('accounting.setup.mapping'), ['accounts' => [$account->id => $foreign->id]])->assertSessionHasErrors('accounts.'.$account->id);
        $this->assertSame($before, $account->refresh()->gl_account_id);
    }

    public function test_remapping_is_audited(): void
    {
        $user = $this->user();
        $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Everyday']);
        $this->inPersonal($user)->post(route('accounting.setup.prepare'), ['base_currency' => 'ZAR', 'fiscal_year_start_month' => 1, 'chart_template' => 'personal']);
        $cash = GlAccount::withoutGlobalScopes()->where('user_id', $user->id)->where('system_key', 'cash')->firstOrFail();

        $this->inPersonal($user)->post(route('accounting.setup.mapping'), ['accounts' => [$account->id => $cash->id]])->assertSessionHasNoErrors();

        $row = FinanceAuditLog::withoutGlobalScopes()->where('user_id', $user->id)->where('action', 'mapped')->firstOrFail();
        $this->assertSame('gl_account', $row->subject_type);
        $this->assertSame($cash->id, $row->changes['gl_account_id']['after']);
    }
}
