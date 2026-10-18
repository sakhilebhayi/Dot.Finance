<?php

namespace Tests\Feature\Company;

use App\Models\Account;
use App\Support\Ledger\LedgerContextManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * The active ledger lives in the session, can be named by a deep link (?context=),
 * and is always validated against what the signed-in person belongs to.
 */
class ContextSwitcherTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    public function test_the_switcher_lists_personal_and_each_company_the_user_belongs_to(): void
    {
        $user = $this->user();
        $acme = $this->company($user, 'Acme Mining');
        $globex = $this->company($this->user(), 'Globex Holdings');
        $globex->users()->attach($user->id, ['role' => 'viewer']);
        $this->company($this->user(), 'Initech Stranger');

        $html = $this->inPersonal($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Acme Mining', $html);
        $this->assertStringContainsString('Globex Holdings', $html);
        $this->assertStringNotContainsString('Initech Stranger', $html);
        $this->assertStringContainsString(route('ledger.context.switch'), $html);
    }

    public function test_a_user_in_no_company_gets_no_switch_control_but_is_told_which_ledger_this_is(): void
    {
        $user = $this->user();

        $html = $this->inPersonal($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('ledger.context.switch'), $html);
        $this->assertStringContainsString('Personal ledger', $html);
    }

    public function test_the_active_context_is_labelled_in_the_shell(): void
    {
        $user = $this->user();
        $acme = $this->company($user, 'Acme Mining');

        $this->inCompany($user, $acme)->get(route('dashboard'))->assertOk()->assertSee('Company ledger')->assertSee('Acme Mining');
    }

    public function test_switching_to_a_company_is_remembered_for_the_following_requests(): void
    {
        $user = $this->user();
        $acme = $this->company($user, 'Acme Mining');
        $this->companyAccount($acme, ['name' => 'Acme Operating']);
        Account::factory()->create(['user_id' => $user->id, 'name' => 'My Own Cheque']);

        $this->inPersonal($user)
            ->post(route('ledger.context.switch'), ['context' => (string) $acme->id])
            ->assertRedirect(route('dashboard'));

        $this->assertSame($acme->id, session(LedgerContextManager::SESSION_KEY));

        $this->get(route('accounts.index'))->assertOk()->assertSee('Acme Operating')->assertDontSee('My Own Cheque');
    }

    public function test_switching_back_to_personal_shows_the_personal_ledger_again(): void
    {
        $user = $this->user();
        $acme = $this->company($user);
        $this->companyAccount($acme, ['name' => 'Acme Operating']);
        Account::factory()->create(['user_id' => $user->id, 'name' => 'My Own Cheque']);

        $this->inCompany($user, $acme)->post(route('ledger.context.switch'), ['context' => 'personal'])->assertRedirect(route('dashboard'));

        $this->get(route('accounts.index'))->assertOk()->assertSee('My Own Cheque')->assertDontSee('Acme Operating');
    }

    public function test_switching_to_a_company_the_user_is_not_in_is_refused_and_changes_nothing(): void
    {
        $user = $this->user();
        $foreign = $this->company($this->user(), 'Globex');

        $this->inPersonal($user)->post(route('ledger.context.switch'), ['context' => (string) $foreign->id])->assertNotFound();

        $this->assertSame('personal', session(LedgerContextManager::SESSION_KEY));
    }

    public function test_switching_to_a_personal_team_or_an_unlinked_team_is_refused(): void
    {
        $user = $this->user();
        $personalTeamId = $user->ownedTeams()->where('personal_team', true)->value('id');

        $this->inPersonal($user)->post(route('ledger.context.switch'), ['context' => (string) $personalTeamId])->assertNotFound();
        $this->post(route('ledger.context.switch'), ['context' => 'banana'])->assertNotFound();
        $this->post(route('ledger.context.switch'), ['context' => ''])->assertNotFound();
        $this->post(route('ledger.context.switch'), [])->assertNotFound();
    }

    public function test_the_switch_needs_a_signed_in_user(): void
    {
        $this->post(route('ledger.context.switch'), ['context' => 'personal'])->assertRedirect(route('login'));
    }

    public function test_a_deep_link_names_the_context_for_that_request_and_after(): void
    {
        $user = $this->user();
        $acme = $this->company($user, 'Acme Mining');
        $this->companyAccount($acme, ['name' => 'Acme Operating']);
        Account::factory()->create(['user_id' => $user->id, 'name' => 'My Own Cheque']);

        $this->inPersonal($user)->get(route('accounts.index', ['context' => $acme->id]))->assertOk()->assertSee('Acme Operating')->assertDontSee('My Own Cheque');
        $this->get(route('accounts.index'))->assertOk()->assertSee('Acme Operating');
        $this->get(route('accounts.index', ['context' => 'personal']))->assertOk()->assertSee('My Own Cheque')->assertDontSee('Acme Operating');
    }

    public function test_a_deep_link_to_a_company_the_user_is_not_in_is_a_404(): void
    {
        $user = $this->user();
        $foreign = $this->company($this->user(), 'Globex');
        $this->companyAccount($foreign, ['name' => 'Globex Operating']);

        $this->inPersonal($user)->get(route('accounts.index', ['context' => $foreign->id]))->assertNotFound();
        $this->get(route('accounts.index', ['context' => '9999']))->assertNotFound();
        $this->get(route('accounts.index', ['context' => 'rubbish']))->assertNotFound();
        $this->get(route('accounts.index', ['context' => ['personal']]))->assertNotFound();
    }

    public function test_a_stale_session_for_a_company_the_user_has_left_falls_back_to_personal(): void
    {
        $owner = $this->user();
        $acme = $this->company($owner);
        $member = $this->memberOf($acme, 'admin');
        $this->companyAccount($acme, ['name' => 'Acme Operating']);
        Account::factory()->create(['user_id' => $member->id, 'name' => 'My Own Cheque']);

        $this->inCompany($member, $acme);
        $acme->users()->detach($member->id);

        $this->get(route('accounts.index'))->assertOk()->assertSee('My Own Cheque')->assertDontSee('Acme Operating');
        $this->assertSame('personal', session(LedgerContextManager::SESSION_KEY));
    }

    public function test_switching_does_not_leak_into_another_users_session(): void
    {
        $user = $this->user();
        $acme = $this->company($user);
        $this->companyAccount($acme, ['name' => 'Acme Operating']);
        $other = $this->user();
        Account::factory()->create(['user_id' => $other->id, 'name' => 'Other Cheque']);

        $this->inCompany($user, $acme)->get(route('accounts.index'))->assertSee('Acme Operating');

        $this->flushSession();
        $this->actingAs($other)->get(route('accounts.index'))->assertOk()->assertSee('Other Cheque')->assertDontSee('Acme Operating');
    }
}
