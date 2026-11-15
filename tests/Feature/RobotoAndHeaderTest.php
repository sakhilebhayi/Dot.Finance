<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * R-52 (dot-ui 0.6.0): Roboto is the one family, self-hosted; the shared header runs across every signed-in page with the sidebar as its
 * phone menu (adopt mode); the search is on every page; nothing external, nothing inline.
 */
class RobotoAndHeaderTest extends TestCase
{
    use BuildsCompanyLedgers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 500)]);
    }

    private function dashboard(?User $user = null): string
    {
        $user ??= $this->user();

        return $this->inPersonal($user)->get('/dashboard')->assertOk()->getContent();
    }

    // ── Typography ───────────────────────────────────────────────────────────

    public function test_roboto_is_declared_and_its_files_are_served_from_this_site(): void
    {
        $roboto = File::get(public_path('dot-ui/css/dot-roboto.css'));
        $this->assertStringContainsString("font-family: 'Roboto'", $roboto);
        $this->assertFileExists(public_path('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2'));
        $this->assertFileExists(public_path('dot-ui/fonts/roboto/roboto-latin-ext-wght-normal.woff2'));
        $this->assertFileExists(public_path('dot-ui/fonts/roboto/OFL.txt'));

        $html = $this->dashboard();
        $this->assertMatchesRegularExpression('/<html[^>]*class="dot-roboto"/', $html);
        $this->assertStringContainsString('/dot-ui/css/dot-roboto.css', $html);
        $this->assertStringContainsString('/dot-ui/css/dot-type.css', $html);
        $this->assertMatchesRegularExpression('#<link rel="preload" href="[^"]*/dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>#', $html);
    }

    public function test_every_finance_face_maps_to_roboto_and_only_code_keeps_the_monospace(): void
    {
        $tokens = File::get(resource_path('css/tokens.css'));
        foreach (['--font-display', '--font-text', '--font-figure'] as $token) {
            $this->assertMatchesRegularExpression('/'.preg_quote($token, '/').': var\(--dot-font-sans\);/', $tokens, "{$token} is Roboto");
        }
        $this->assertStringContainsString('--font-code: var(--dot-font-mono);', $tokens, 'account codes, tokens and references keep the shared monospace');

        // no other family is named anywhere in the product's own CSS
        foreach (glob(resource_path('css/*.css')) as $css) {
            $source = File::get($css);
            $this->assertDoesNotMatchRegularExpression('/Archivo|Public Sans|Chivo|Schibsted|Figtree/i', $source, basename($css).' names no other typeface');
            $this->assertStringNotContainsString('font-stretch', $source, basename($css).': Roboto has no width axis');
        }
        $this->assertFileDoesNotExist(resource_path('css/fonts.css'));

        $package = File::get(base_path('package.json'));
        $this->assertStringNotContainsString('fontsource', $package, 'the old font packages are gone');
        $this->assertStringContainsString('dot-ui/tailwind/tailwind-preset.cjs', File::get(base_path('tailwind.config.js')), 'Tailwind takes font-sans and the type scale from dot-ui');
    }

    public function test_no_page_or_stylesheet_reaches_an_external_font_host(): void
    {
        $html = $this->dashboard();
        foreach (['fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.jsdelivr', 'use.typekit', 'bunny.net'] as $host) {
            $this->assertStringNotContainsString($host, $html);
        }
        foreach (array_merge(glob(public_path('dot-ui/css/*.css')), glob(resource_path('css/*.css'))) as $css) {
            $this->assertDoesNotMatchRegularExpression('#url\(\s*[\'"]?(https?:)?//#', File::get($css), basename($css).' reaches outside this site');
        }
        foreach (['layouts/app', 'layouts/guest', 'layouts/partials-head', 'welcome', 'errors/_ecosystem-layout'] as $view) {
            $this->assertStringNotContainsString('googleapis', File::get(resource_path("views/{$view}.blade.php")));
        }
    }

    public function test_the_guest_and_error_pages_are_roboto_too(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('class="dot-roboto"', false)->assertSee('/dot-ui/css/dot-roboto.css', false);
        $this->get('/no-such-page-here')->assertNotFound()->assertSee('class="dot-roboto"', false)->assertSee('/dot-ui/css/dot-roboto.css', false);
    }

    // ── The header ───────────────────────────────────────────────────────────

    public function test_the_shared_header_runs_across_the_page_with_the_sidebar_as_its_phone_menu(): void
    {
        $html = $this->dashboard();

        $this->assertSame(1, preg_match('/<header class="dh dh--nonav dh--adopt"[^>]*id="dh" data-dh>/', $html), 'one header, no bar nav (the sidebar is the navigation), adopt mode');
        $this->assertStringContainsString('class="dh-skip skip-link" href="#main"', $html);
        $this->assertStringContainsString('aria-label="Dot.Finance, command centre"', $html, 'the logo link is named');
        $this->assertStringContainsString('class="dh-logo-day"', $html, 'the original day logo');
        $this->assertStringContainsString('class="dh-logo-night"', $html, 'the original night logo');
        $this->assertStringContainsString('/images/logo.png', $html);
        $this->assertStringContainsString('/images/logo-light.png', $html);
        $this->assertFileExists(public_path('images/logo.png'));

        // the phone menu button opens the sidebar itself (no second drawer is printed), and the sidebar can close itself
        $this->assertMatchesRegularExpression('/<button[^>]*data-dh-menu-button[^>]*aria-controls="app-sidebar"/', $html);
        $this->assertStringNotContainsString('class="dh-drawer"', $html);
        $this->assertMatchesRegularExpression('/<aside id="app-sidebar"[^>]*>.*?data-dh-close/s', $html);

        // account, notifications and the platform switcher are in the bar (they have no drawer to hide in)
        $this->assertStringContainsString('class="dh-account', $html);
        $this->assertStringContainsString('data-dsw-trigger', $html);
        $this->assertMatchesRegularExpression('/class="dh-icon-btn dh-bell dh-bell--always"[^>]*aria-label="Notifications"/', $html);
        $this->assertStringContainsString('data-dh-theme-set="dark"', $html, 'the theme switch is in the account menu');
    }

    public function test_the_account_menu_carries_the_sign_out_form_and_the_ledger_the_books_are_open_on(): void
    {
        $owner = $this->user();
        $acme = $this->company($owner, 'Acme (Pty) Ltd');

        $html = $this->inCompany($owner, $acme)->get('/dashboard')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<form class="dh-signout" method="post" action="[^"]*\/logout">\s*<input type="hidden" name="_token"/', $html);
        $this->assertStringContainsString('Profile and settings', $html);
        $this->assertMatchesRegularExpression('/<span class="dh-menu__sub" title="Acme \(Pty\) Ltd">/', $html, 'the open ledger is the account menu\'s second line');
    }

    public function test_the_theme_choice_keeps_its_old_key_and_is_applied_before_first_paint_by_an_external_file(): void
    {
        $html = $this->dashboard();

        $this->assertStringContainsString('data-dh-theme-key="dot-finance-theme"', $html, 'a choice made before R-52 still holds');
        $this->assertMatchesRegularExpression('#<script src="[^"]*/dot-ui/js/dot-theme-boot.js"></script>#', $html);
        $this->assertStringNotContainsString("getItem('dot-finance-theme')", $html, 'the old inline theme script is gone');
    }

    public function test_the_bottom_bar_has_four_places_and_the_menu_lives_in_the_header(): void
    {
        $html = $this->dashboard();
        preg_match('/<nav class="fx-bar".*?<\/nav>/s', $html, $bar);

        $this->assertSame(4, preg_match_all('/<a href=/', $bar[0]), 'Home, Activity, Add and Accounts: four links, each 44px or more');
        $this->assertStringNotContainsString('data-shell-menu', $html, 'no second menu button: it is the header\'s');
        $this->assertStringNotContainsString('<button', $bar[0]);
    }

    public function test_the_shell_has_no_inline_style_or_script_and_every_script_is_external_or_nonced(): void
    {
        $html = $this->dashboard();

        $this->assertStringNotContainsString(' style="', $html);
        $this->assertDoesNotMatchRegularExpression('/<style(?![^>]*nonce=)/', $html);
        preg_match_all('/<script(?<attrs>[^>]*)>/', $html, $scripts);
        foreach ($scripts['attrs'] as $attrs) {
            $this->assertTrue(str_contains($attrs, ' src=') || str_contains($attrs, 'src=') || str_contains($attrs, 'nonce='), "a script with neither src nor nonce: <script{$attrs}>");
        }
        $this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*\son[a-z]+="/', $html, 'no inline event handlers');
    }

    public function test_the_phone_rules_the_audit_asked_for_are_in_the_stylesheets(): void
    {
        $shell = File::get(resource_path('css/shell.css'));
        $this->assertStringContainsString('[data-dh-open]', $shell, 'the sidebar slides on the data attribute dot-ui sets');
        $this->assertMatchesRegularExpression('/visibility 0s linear 220ms/', $shell, 'the closed sidebar leaves the tab order with a delayed visibility');
        $this->assertStringContainsString('top: var(--dot-header-offset)', $shell, 'on a desktop the sidebar sits under the header');
        $this->assertStringContainsString('100dvh', $shell);

        $components = File::get(resource_path('css/components.css'));
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr);', $components, 'a form column can shrink below its widest child (the 320px overflow)');
        $this->assertStringContainsString('R-52: touch targets on tablets and phones', $components);
    }

    public function test_the_deploy_smoke_check_also_asks_for_the_header_files(): void
    {
        $deploy = File::get(base_path('.github/workflows/deploy.yml'));
        foreach (['dot-ui/css/dot-header.css', 'dot-ui/css/dot-roboto.css', 'dot-ui/js/dot-nav.js', 'dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2'] as $file) {
            $this->assertStringContainsString($file, $deploy, "{$file} is smoke-checked after a deploy");
        }
    }
}
