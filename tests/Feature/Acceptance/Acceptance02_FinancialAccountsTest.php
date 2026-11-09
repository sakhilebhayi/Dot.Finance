<?php

namespace Tests\Feature\Acceptance;

use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalLine;
use App\Support\Ledger\LedgerContext;

/**
 * Owner criterion 2: an authorised user creates and manages financial accounts (cash, bank and the chart of accounts).
 */
class Acceptance02_FinancialAccountsTest extends AcceptanceCase
{
    public function test_an_authorised_user_creates_a_bank_account_and_a_cash_account_and_each_gets_its_ledger_account_and_opening_entry(): void
    {
        $this->setUpIntegration();
        $this->asOwner()->post(route('accounts.store'), ['name' => 'Savings', 'type' => 'savings', 'currency' => 'zar', 'opening_balance' => '2500.00', 'opening_balance_date' => '2026-11-01'])
            ->assertSessionHasNoErrors()->assertRedirect(route('accounts.index'));
        $this->asOwner()->post(route('accounts.store'), ['name' => 'Petty cash', 'type' => 'cash', 'currency' => 'ZAR', 'opening_balance' => '300.00', 'opening_balance_date' => '2026-11-01'])
            ->assertSessionHasNoErrors();

        $savings = Account::query()->forContext($this->ledger)->where('name', 'Savings')->firstOrFail();
        $cash = Account::query()->forContext($this->ledger)->where('name', 'Petty cash')->firstOrFail();
        $this->assertSame('ZAR', $savings->currency);
        $this->assertSame(250_000, (int) $savings->opening_balance_cents);
        $this->assertNotNull($savings->gl_account_id, 'accounting is on, so the account is mapped to the chart');
        $this->assertNotNull($cash->gl_account_id);

        // the opening entry is in the books: 2,500.00 + 300.00 of bank/cash against opening-balance equity
        $this->assertSame(250_000 + 30_000, $this->balanceOfGl($savings->gl_account_id) + $this->balanceOfGl($cash->gl_account_id));
        $this->assertBooksVerify();
        $this->asOwner()->get(route('accounts.index'))->assertOk()->assertSee('Savings')->assertSee('Petty cash');
        $this->assertCount(2, $this->auditRows('created', 'account')->filter(fn ($r) => in_array($r->subject_label, ['Savings', 'Petty cash'], true)), 'both creations are audited with who did it');
        $this->assertSame($this->owner->id, (int) $this->auditRows('created', 'account')->last()->actor_user_id);
    }

    public function test_account_input_is_validated(): void
    {
        $this->setUpIntegration();
        $bad = ['name' => '', 'type' => 'piggybank', 'currency' => 'RANDS', 'opening_balance' => '12.345'];
        $this->asOwner()->post(route('accounts.store'), $bad)->assertSessionHasErrors(['name', 'type', 'currency', 'opening_balance']);
        $this->asOwner()->post(route('accounts.store'), ['name' => 'X', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance' => '1e3'])->assertSessionHasErrors('opening_balance');
    }

    public function test_an_account_is_renamed_but_its_currency_is_locked_once_it_has_transactions_and_the_opening_balance_has_its_own_audited_action(): void
    {
        $this->runMonth(false);
        $this->asOwner()->put(route('accounts.update', $this->bank), ['name' => 'Main current account', 'type' => 'checking', 'currency' => 'ZAR', 'institution' => 'Test Bank'])->assertSessionHasNoErrors();
        $this->assertSame('Main current account', $this->bank->fresh()->name);

        $this->asOwner()->put(route('accounts.update', $this->bank), ['name' => 'Main current account', 'type' => 'checking', 'currency' => 'USD'])->assertSessionHasErrors('currency');
        $this->assertSame('ZAR', $this->bank->fresh()->currency);

        $this->asOwner()->post(route('accounts.opening-balance', $this->bank), ['opening_balance' => '120000.00', 'opening_balance_date' => '2026-10-01', 'reason' => 'Agreed to the 30 September statement'])->assertSessionHasNoErrors();
        $row = $this->auditRows('opening_balance_adjusted', 'account')->last();
        $this->assertSame(12_000_000, (int) $this->bank->fresh()->opening_balance_cents);
        $this->assertSame($this->owner->id, (int) $row->actor_user_id);
        $this->assertSame(10_000_000, (int) $row->changes['opening_balance_cents']['before']);
        $this->assertSame('reason: Agreed to the 30 September statement', $row->context);
        $this->assertBooksVerify();
    }

    public function test_an_account_that_holds_money_cannot_be_deleted_while_accounting_is_on(): void
    {
        $this->setUpIntegration();
        $this->asOwner()->delete(route('accounts.destroy', $this->bank), ['confirm' => 1])->assertSessionHasErrors('confirm');
        $this->assertNotNull(Account::query()->forContext($this->ledger)->find($this->bank->id));
    }

    public function test_the_chart_of_accounts_is_managed_by_those_with_the_capability_and_system_accounts_are_protected(): void
    {
        $this->runMonth(false);
        $this->asOwner()->post(route('accounting.chart.store'), ['code' => '6800', 'name' => 'Software subscriptions', 'class' => 'expense', 'subtype' => 'operating'])->assertSessionHasNoErrors();
        $new = GlAccount::query()->forContext($this->ledger)->where('code', '6800')->firstOrFail();
        $this->assertSame('expense', $new->class);
        $this->assertCount(1, $this->auditRows('created', 'gl_account'));

        // duplicate code refused
        $this->asOwner()->post(route('accounting.chart.store'), ['code' => '6800', 'name' => 'Duplicate', 'class' => 'expense'])->assertSessionHasErrors('code');
        // an unused account may be renamed and deactivated
        $this->asOwner()->put(route('accounting.chart.update', $new), ['code' => '6800', 'name' => 'Software and licences'])->assertSessionHasNoErrors();
        $this->assertSame('Software and licences', $new->fresh()->name);
        $this->asOwner()->post(route('accounting.chart.deactivate', $new))->assertSessionHasNoErrors();
        $this->assertFalse((bool) $new->fresh()->is_active);
        $this->asOwner()->post(route('accounting.chart.activate', $new));
        $this->assertTrue((bool) $new->fresh()->is_active);

        // the receivables control account is a system account with postings: it cannot be deactivated or re-classed
        $ar = $this->gl('ar');
        $this->asOwner()->post(route('accounting.chart.deactivate', $ar))->assertSessionHasErrors('account');
        $this->assertTrue((bool) $ar->fresh()->is_active);
        $this->asOwner()->put(route('accounting.chart.update', $ar), ['code' => $ar->code, 'name' => $ar->name, 'class' => 'expense'])->assertSessionHasErrors('class');
        $this->assertSame('asset', $ar->fresh()->class);
    }

    public function test_a_bookkeeper_may_add_a_bank_account_but_not_change_the_chart_and_a_viewer_neither(): void
    {
        $this->setUpIntegration();
        $bookkeeper = $this->memberAs('bookkeeper');
        $viewer = $this->memberAs('viewer');

        $this->asMember($bookkeeper)->post(route('accounts.store'), ['name' => 'Second bank', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance' => '0.00'])->assertSessionHasNoErrors();
        $this->asMember($bookkeeper)->post(route('accounting.chart.store'), ['code' => '6801', 'name' => 'Nope', 'class' => 'expense'])->assertForbidden();
        $this->asMember($viewer)->post(route('accounts.store'), ['name' => 'Third bank', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance' => '0.00'])->assertForbidden();
        $this->asMember($viewer)->post(route('accounting.chart.store'), ['code' => '6802', 'name' => 'Nope', 'class' => 'expense'])->assertForbidden();
        $this->asMember($viewer)->get(route('accounting.chart.index'))->assertOk();
        $this->assertNull(GlAccount::query()->forContext($this->ledger)->where('code', '6801')->first());
    }

    public function test_another_companys_account_cannot_be_reached(): void
    {
        $this->setUpIntegration();
        $other = $this->user();
        $team = $this->company($other, 'Other Co');
        $theirs = Account::factory()->forCompany($team)->create(['name' => 'Their bank']);
        $this->asOwner()->get(route('accounts.edit', $theirs))->assertNotFound();
        $this->asOwner()->put(route('accounts.update', $theirs), ['name' => 'Mine now', 'type' => 'checking', 'currency' => 'ZAR'])->assertNotFound();
        $this->assertSame('Their bank', $theirs->fresh()->name);
        $this->asOwner()->get(route('accounts.index'))->assertDontSee('Their bank');
    }

    private function balanceOfGl(int $glId): int
    {
        return (int) LedgerContext::run($this->ledger, fn () => JournalLine::query()->forContext($this->ledger)->where('gl_account_id', $glId)
            ->selectRaw('COALESCE(SUM(debit_cents),0) - COALESCE(SUM(credit_cents),0) as n')->value('n'));
    }
}
