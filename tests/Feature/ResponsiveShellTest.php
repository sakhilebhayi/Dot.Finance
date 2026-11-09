<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger\LedgerContextManager;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards the markup hooks of the responsive app shell (R-44 design system): the sidebar that becomes a bottom sheet
 * below 1024px, the bottom bar, the table wrapper and the form hooks. These assert structure, not pixels. The shell's
 * CSS and script are built files now (resources/css, resources/js/fx), so the guards on their behaviour read those
 * sources; the page itself must carry no inline style and no script without the per-request nonce (see
 * SecurityHeadersTest for the headers).
 */
class ResponsiveShellTest extends TestCase
{
    use RefreshDatabase;

    private function signedInPage(string $routeName): string
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create(['name' => 'Shell Test Cheque']);
        Account::factory()->for($user)->create(['name' => 'Shell Test Savings']);
        Transaction::factory()->create(['account_id' => $account->id, 'user_id' => $user->id, 'description' => 'Shell test purchase']);

        $response = $this->actingAs($user)->get(route($routeName));
        $response->assertOk();

        return $response->getContent();
    }

    /** @return array<string, array{0: string}> */
    public static function shellPages(): array
    {
        return [
            'dashboard' => ['dashboard'],
            'transactions' => ['transactions.index'],
            'new transaction form' => ['transactions.create'],
            'accounts' => ['accounts.index'],
            'budgets' => ['budgets.index'],
            'transfer form' => ['transfers.create'],
        ];
    }

    #[DataProvider('shellPages')]
    public function test_every_app_page_carries_the_sheet_shell(string $routeName): void
    {
        $html = $this->signedInPage($routeName);

        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1"', $html);

        // Visible, labelled "More" button wired to the sheet, in the bottom bar.
        $this->assertMatchesRegularExpression('/<button[^>]*class="[^"]*\bshell-menu\b[^"]*"[^>]*data-shell-menu[^>]*aria-controls="app-sidebar"[^>]*aria-expanded="false"/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*data-shell-menu[^>]*>.*?<span>More<\/span>/s', $html);
        $this->assertMatchesRegularExpression('/<nav class="fx-bar" aria-label="Quick navigation">/', $html);

        // The sheet is the sidebar, addressable by the id the button controls.
        $this->assertMatchesRegularExpression('/<aside id="app-sidebar" class="[^"]*\bfx-side\b[^"]*"[^>]*data-shell-drawer/', $html);
        $this->assertStringContainsString('data-shell-backdrop', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*data-shell-close[^>]*aria-label="Close menu"/', $html);

        // Landmarks and the skip link.
        $this->assertStringContainsString('<a href="#main" class="fx-skip">', $html);
        $this->assertStringContainsString('<main id="main"', $html);
        $this->assertStringContainsString('<nav class="fx-nav" aria-label="Main">', $html);
    }

    public function test_the_sheet_behaviour_is_in_the_built_script_and_respects_reduced_motion(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/resources/js/fx/shell.js');
        $css = file_get_contents(dirname(__DIR__, 2).'/resources/css/base.css').file_get_contents(dirname(__DIR__, 2).'/resources/css/shell.css');

        $this->assertStringContainsString("event.key === 'Escape'", $script);
        $this->assertStringContainsString('is-locked', $script);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
        $this->assertStringContainsString('@media (max-width: 1023.98px)', $css);
    }

    public function test_the_state_line_leads_the_top_bar(): void
    {
        $html = $this->signedInPage('dashboard');

        $this->assertMatchesRegularExpression('/<header class="fx-top topbar">.*?<section class="fx-state" aria-label="What needs you">/s', $html);
    }

    public function test_no_authenticated_page_carries_inline_style_or_an_unnonced_script(): void
    {
        foreach (['dashboard', 'transactions.index', 'accounts.index', 'budgets.index', 'transfers.create'] as $routeName) {
            $html = $this->signedInPage($routeName);

            $this->assertDoesNotMatchRegularExpression('/\sstyle="/i', $html, "[$routeName] has an inline style attribute");
            $this->assertDoesNotMatchRegularExpression('/<style(?![^>]*\bnonce=)/i', $html, "[$routeName] has a <style> without a nonce");
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\b(?:nonce|src)=)/i', $html, "[$routeName] has an inline <script> without a nonce");
            $this->assertDoesNotMatchRegularExpression('#https?://(?:cdn\.tailwindcss\.com|fonts\.googleapis\.com|fonts\.gstatic\.com)#', $html, "[$routeName] loads a third-party asset");
        }
    }

    public function test_data_tables_sit_inside_a_horizontal_scroll_wrapper(): void
    {
        foreach (['dashboard', 'transactions.index', 'accounts.index'] as $routeName) {
            $html = $this->signedInPage($routeName);

            $this->assertMatchesRegularExpression(
                '/<div class="[^"]*\btable-scroll\b[^"]*"[^>]*>\s*<table/',
                $html,
                "The main table on [$routeName] must be wrapped in .table-scroll so it never widens the page."
            );
        }
    }

    public function test_forms_and_split_layouts_use_the_stacking_hook(): void
    {
        foreach (['transactions.create', 'transfers.create', 'accounts.index', 'transactions.index'] as $routeName) {
            $this->assertStringContainsString('r-stack', $this->signedInPage($routeName), "[$routeName] needs .r-stack so it collapses to one column on phones.");
        }
    }

    public function test_page_wrappers_use_the_responsive_padding_hook(): void
    {
        foreach (['dashboard', 'transactions.index', 'transactions.create'] as $routeName) {
            $this->assertMatchesRegularExpression('/class="fx-page page-pad"/', $this->signedInPage($routeName));
        }
    }

    private const ACCOUNT_SECTION = '//aside[@id="app-sidebar"]//div[contains(@class,"shell-account")]';

    private function accountXpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    private function dashboardHtmlFor(User $user): string
    {
        return $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk()->getContent();
    }

    public function test_the_sidebar_account_section_carries_team_settings_create_team_and_log_out(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $xpath = $this->accountXpath($this->dashboardHtmlFor($user));
        $account = self::ACCOUNT_SECTION;

        $this->assertSame(1, $xpath->query($account)->length, 'The account section lives inside the sidebar drawer');
        $this->assertStringContainsString($team->name, $xpath->query($account.'//summary')->item(0)?->textContent ?? '', 'The current team is named in the account section');
        $this->assertSame(1, $xpath->query($account.'//a[@href="'.route('teams.show', $team->id).'"][span[@class="team-label"][normalize-space()="Team settings"]]')->length);
        $this->assertSame(1, $xpath->query($account.'//a[@href="'.route('teams.create').'"][span[@class="team-label"][normalize-space()="Create new team"]]')->length);

        $logout = $xpath->query($account.'//form[@method="POST"][@action="'.route('logout').'"]');
        $this->assertSame(1, $logout->length, 'Log out is a POST form');
        $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $logout->item(0))->length);
        $this->assertSame(1, $xpath->query('.//button[@type="submit"][span[@class="team-label"][normalize-space()="Log out"]]', $logout->item(0))->length);
        $this->assertSame(0, $xpath->query('//a[@href="'.route('logout').'"]')->length, 'There is no GET logout link');
    }

    public function test_create_team_is_left_out_when_the_user_may_not_create_one(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Gate::before(fn ($authUser, $ability, $arguments) => $ability === 'create' && ($arguments[0] ?? null) instanceof Team ? false : null);

        $xpath = $this->accountXpath($this->dashboardHtmlFor($user));

        $this->assertSame(1, $xpath->query(self::ACCOUNT_SECTION.'//a[@href="'.route('teams.show', $user->currentTeam->id).'"]')->length);
        $this->assertSame(0, $xpath->query('//a[@href="'.route('teams.create').'"]')->length);
    }

    public function test_a_user_in_several_teams_gets_one_switch_form_per_team_with_the_current_one_marked(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $current = $user->currentTeam;
        $other = Team::factory()->create(['user_id' => $user->id, 'name' => 'Second Account Team', 'personal_team' => false]);

        $xpath = $this->accountXpath($this->dashboardHtmlFor($user));
        $forms = $xpath->query(self::ACCOUNT_SECTION.'//form[@method="POST"][@action="'.route('current-team.update').'"]');

        $this->assertSame(2, $forms->length, 'One switch form per team the user belongs to');
        $this->assertSame(1, $xpath->query(self::ACCOUNT_SECTION.'//form[@action="'.route('current-team.update').'"][input[@name="_method"][@value="PUT"]][input[@name="_token"]][input[@name="team_id"][@value="'.$other->id.'"]]//button[@type="submit"][contains(.,"Second Account Team")][not(@aria-current)]')->length);
        $this->assertSame(1, $xpath->query(self::ACCOUNT_SECTION.'//form[@action="'.route('current-team.update').'"][input[@name="team_id"][@value="'.$current->id.'"]]//button[@type="submit"][@aria-current="true"]')->length, 'The current team is marked');
        $this->assertSame(1, $xpath->query(self::ACCOUNT_SECTION.'//*[normalize-space()="Switch teams"]')->length);
    }

    public function test_a_user_in_one_team_gets_no_switch_forms(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $xpath = $this->accountXpath($this->dashboardHtmlFor($user));

        $this->assertSame(1, $xpath->query(self::ACCOUNT_SECTION)->length);
        $this->assertSame(0, $xpath->query('//form[@action="'.route('current-team.update').'"]')->length);
        $this->assertSame(0, $xpath->query(self::ACCOUNT_SECTION.'//*[normalize-space()="Switch teams"]')->length);
    }

    public function test_the_legacy_jetstream_bar_is_not_rendered(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->dashboardHtmlFor($user);

        $this->assertDoesNotMatchRegularExpression('/<nav\b[^>]*\bwire:id=/', $html, 'The stock Jetstream bar (a Livewire nav) must not render at any width');
        $this->assertStringNotContainsString('Manage Account', $html, 'Text only the legacy bar rendered');
    }

    public function test_no_css_rule_hides_the_account_section_at_any_width(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $html = $this->dashboardHtmlFor($user);

        $this->assertSame(1, $this->accountXpath($html)->query(self::ACCOUNT_SECTION)->length);

        $css = file_get_contents(dirname(__DIR__, 2).'/resources/css/shell.css');
        $this->assertNotSame('', $css);

        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        $checked = 0;
        foreach ($rules as [, $selector, $declarations]) {
            // The disclosure triangle of <details> is the one thing in there that is meant to be hidden.
            if (preg_match('/fx-account|shell-account|team-switch|logout-btn|team-row/', $selector) !== 1 || str_contains($selector, 'details-marker')) {
                continue;
            }
            $checked++;
            $this->assertDoesNotMatchRegularExpression('/display\s*:\s*none|visibility\s*:\s*hidden/', $declarations, 'A rule hides the account section: '.trim($selector));
        }
        $this->assertGreaterThan(0, $checked, 'The account section is styled by rules this guard can see');
    }

    public function test_the_sheet_focus_trap_skips_the_collapsed_team_list(): void
    {

        $script = file_get_contents(dirname(__DIR__, 2).'/resources/js/fx/shell.js');
        $this->assertStringContainsString('a[href], button:not([disabled]), summary', $script);
        $this->assertStringContainsString('offsetParent !== null', $script);
    }

    public function test_the_ledger_switcher_and_the_team_account_section_sit_side_by_side_without_duplicating_each_other(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $company = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme Holdings', 'personal_team' => false, 'infodot_team_uuid' => (string) Str::uuid()]);

        $xpath = $this->accountXpath($this->actingAs($user->fresh())->withSession([LedgerContextManager::SESSION_KEY => $company->id])->get(route('dashboard'))->assertOk()->getContent());

        $this->assertSame(1, $xpath->query('//aside[@id="app-sidebar"]//div[contains(@class,"ledger-switch")]//form[@action="'.route('ledger.context.switch').'"]')->length, 'The ledger context switcher is kept');
        $this->assertSame(0, $xpath->query(self::ACCOUNT_SECTION.'//form[@action="'.route('ledger.context.switch').'"]')->length, 'The account section does not repeat the ledger switcher');
        $this->assertSame(0, $xpath->query('//div[contains(@class,"ledger-switch")]//form[@action="'.route('current-team.update').'"]')->length, 'The ledger switcher does not carry team switch forms');
        $this->assertSame(2, $xpath->query(self::ACCOUNT_SECTION.'//form[@action="'.route('current-team.update').'"]')->length);
        $this->assertSame(1, $xpath->query(self::ACCOUNT_SECTION.'//form[@action="'.route('logout').'"]')->length);
    }
}
