<?php

namespace Tests\Feature;

use App\Console\Commands\DemoSeed;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Shared\Party;
use App\Models\Team;
use App\Models\User;
use App\Support\Ledger\LedgerContext;
use App\Support\Ledger\LedgerContextManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The accounting, controls and parties screens are built on the x-fx design system (R-44 restyle). Rendered against the
 * local demo company they must carry none of the pre-design-system markup, exactly one h1, a caption on every table, no
 * inline style or un-nonced script, and the statement behaviours (empty accounts left out, the steps, the lock state).
 */
class RestyleViewsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('finance:demo-seed')->assertExitCode(0);
        $this->artisan('finance:demo-controls')->assertExitCode(0);
        $this->owner = User::query()->where('email', DemoSeed::EMAIL)->firstOrFail();
        $this->company = Team::query()->where('name', DemoSeed::COMPANY)->firstOrFail();
    }

    private function asOwner(): static
    {
        return $this->actingAs($this->owner->fresh())->withSession([LedgerContextManager::SESSION_KEY => $this->company->id]);
    }

    /** @return list<string> */
    private function pages(): array
    {
        return LedgerContext::run(LedgerContext::company($this->company), function (): array {
            $party = Party::query()->firstOrFail();
            $entry = JournalEntry::query()->firstOrFail();
            $account = GlAccount::query()->where('is_postable', true)->firstOrFail();

            return [
                route('accounting.trial-balance'), route('accounting.general-ledger', ['account' => $account->id]),
                route('accounting.journals.index'), route('accounting.journals.show', $entry), route('accounting.journals.create'),
                route('accounting.chart.index'), route('accounting.chart.create'), route('accounting.chart.edit', $account),
                route('accounting.periods.index'),
                route('controls.index'), route('controls.members.index'), route('controls.policies.index'), route('controls.approvals.index'),
                route('controls.approvals.index', ['tab' => 'mine']), route('controls.approvals.index', ['tab' => 'history']),
                route('controls.limits.index'), route('controls.access-review.index'),
                route('parties.index'), route('parties.create'), route('parties.show', $party), route('parties.edit', $party),
                route('teams.show', $this->company->id), route('profile.show'),
            ];
        });
    }

    public function test_every_restyled_page_is_clean_design_system_markup(): void
    {
        foreach ($this->pages() as $url) {
            $html = $this->asOwner()->get($url)->assertOk()->getContent();

            $this->assertSame(1, preg_match_all('/<h1\b/', $html), "$url needs exactly one h1");
            $this->assertDoesNotMatchRegularExpression('/\b(dot-card|dot-btn|dot-input|acct-[a-z]+|controls-(page|nav|table|summary|status|errors|tabs|preset|custom|policy|request|roles))\b/', $html, "$url still carries pre-design-system classes");
            $this->assertDoesNotMatchRegularExpression('/class="[^"]*\b(col-span-\d|mt-\d|ms-\d|me-\d|text-gray-\d+|dark:[a-z]+)\b/', $html, "$url still carries stock utility classes");
            $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html, "$url carries a style attribute");
            $this->assertDoesNotMatchRegularExpression('/<script\b(?![^>]*\bnonce=)(?![^>]*\bsrc=)/i', $html, "$url has an un-nonced inline script");

            preg_match_all('/<table\b[^>]*>(.*?)<\/table>/s', $html, $tables);
            foreach ($tables[1] as $table) {
                $this->assertStringContainsString('<caption', $table, "$url has a table without a caption");
                $this->assertStringContainsString('scope="col"', $table, "$url has a table without column scopes");
            }
        }
    }

    public function test_the_trial_balance_leaves_out_accounts_with_nothing_posted_unless_asked(): void
    {
        $page = $this->asOwner()->get(route('accounting.trial-balance'))->assertOk();
        $page->assertSee('Business Cheque')->assertSee('in balance')->assertSee('left out')->assertSee('Show them')->assertDontSee('Deferred tax');

        $this->asOwner()->get(route('accounting.trial-balance', ['all' => 1]))->assertOk()->assertSee('Deferred tax')->assertSee('Hide accounts with no balance');
    }

    public function test_the_trial_balance_totals_are_ticked_only_when_they_balance(): void
    {
        $html = $this->asOwner()->get(route('accounting.trial-balance'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<tfoot>.*is-ticked.*<\/tfoot>/s', $html);
        $this->assertStringContainsString('fx-verdict', $html);
        $this->assertStringNotContainsString('fx-verdict--bad', $html);
    }

    public function test_the_periods_page_states_what_is_locked(): void
    {
        $this->asOwner()->get(route('accounting.periods.index'))->assertOk()
            ->assertSee('Books closed through')->assertSee('fx-lock is-locked', false)->assertSee('Closed')->assertSee('Open')
            ->assertSee('data-fx-confirm', false);
    }

    public function test_the_setup_steps_follow_where_the_person_is(): void
    {
        $this->actingAs($this->owner->fresh())->withSession([LedgerContextManager::SESSION_KEY => 'personal']);

        $this->get(route('accounting.setup'))->assertOk()
            ->assertSee('aria-current="step"', false)->assertSee('Set the basics')->assertSee('Switch it on');

        $this->post(route('accounting.setup.prepare'), ['base_currency' => 'ZAR', 'fiscal_year_start_month' => 3, 'chart_template' => 'personal'])->assertRedirect(route('accounting.setup.review'));
        $page = $this->get(route('accounting.setup.review'))->assertOk()->assertSee('Match your accounts')->assertSee('Try it first');
        $this->assertSame(1, substr_count($page->getContent(), 'aria-current="step"'));

        $this->post(route('accounting.setup.dry-run'))->assertRedirect();
        $this->get(route('accounting.setup.review'))->assertOk()->assertSee('Ties out')->assertSee('Last dry run');
    }

    public function test_the_members_page_shows_the_role_matrix_with_accessible_yes_and_no(): void
    {
        $page = $this->asOwner()->get(route('controls.members.index'))->assertOk();

        $page->assertSee('What each role can do')->assertSee('fx-yes', false)->assertSee('Pieter van Wyk')->assertSee('Finance manager');
        $this->assertStringContainsString('<span class="sr-only">Yes</span>', $page->getContent());
        $this->assertStringContainsString('<span class="sr-only">No</span>', $page->getContent());
    }

    public function test_a_party_page_gives_documents_signed_links(): void
    {
        $party = LedgerContext::run(LedgerContext::company($this->company), fn () => Party::query()->where('name', 'Salt River Deli')->firstOrFail());

        $html = $this->asOwner()->get(route('parties.show', $party))->assertOk()->assertSee('wholesale-agreement-2026.pdf')->assertSee('Edit details')->assertSee('Archive')->getContent();
        $this->assertMatchesRegularExpression('/href="[^"]*attachments\/\d+[^"]*signature=/', $html, 'the document links are signed');

    }

    public function test_a_viewer_sees_a_party_without_the_actions(): void
    {
        $party = LedgerContext::run(LedgerContext::company($this->company), fn () => Party::query()->where('name', 'Salt River Deli')->firstOrFail());
        $viewer = User::query()->where('email', 'sipho@karoo.test')->firstOrFail();

        $this->actingAs($viewer->fresh())->withSession([LedgerContextManager::SESSION_KEY => $this->company->id])
            ->get(route('parties.show', $party))->assertOk()->assertDontSee('Edit details')->assertDontSee('Upload')->assertDontSee('Archive');
    }
}
