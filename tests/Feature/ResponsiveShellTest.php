<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards the markup hooks of the responsive app shell (off-canvas drawer, overflow wrappers,
 * stacking helpers). These assert structure, not pixels: the drawer behaviour itself is plain
 * CSS plus a small inline script, so what can regress silently is a hook being renamed or removed.
 */
class ResponsiveShellTest extends TestCase
{
    use RefreshDatabase;

    private function signedInPage(string $routeName): string
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create(['name' => 'Shell Test Cheque']);
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
    public function test_every_app_page_carries_the_drawer_shell(string $routeName): void
    {
        $html = $this->signedInPage($routeName);

        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1"', $html);

        // Visible, labelled menu button wired to the drawer.
        $this->assertMatchesRegularExpression('/<button[^>]*class="shell-menu"[^>]*data-shell-menu[^>]*aria-controls="app-sidebar"[^>]*aria-expanded="false"/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*data-shell-menu[^>]*>.*?<span>Menu<\/span>/s', $html);

        // The drawer is the existing sidebar, addressable by the id the button controls.
        $this->assertMatchesRegularExpression('/<aside id="app-sidebar" class="sidebar"[^>]*data-shell-drawer/', $html);
        $this->assertStringContainsString('data-shell-backdrop', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*data-shell-close[^>]*aria-label="Close menu"/', $html);

        // Behaviour hooks: scroll lock class, Escape handling and reduced-motion respect.
        $this->assertStringContainsString('shell-locked', $html);
        $this->assertStringContainsString("e.key === 'Escape'", $html);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $html);
        $this->assertStringContainsString('@media (max-width: 859.98px)', $html);
    }

    public function test_the_menu_button_comes_first_in_the_top_bar(): void
    {
        $html = $this->signedInPage('dashboard');

        $this->assertMatchesRegularExpression('/<header class="topbar">\s*<button[^>]*data-shell-menu/', $html);
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
            $this->assertStringContainsString('class="page-pad"', $this->signedInPage($routeName));
        }
    }
}
