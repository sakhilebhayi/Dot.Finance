<?php

namespace Tests\Feature\Acceptance;

use App\Models\Bank\BankReconciliation;
use Illuminate\Support\Facades\File;

/**
 * Owner criterion 32: mobile financial workflows are usable. The workflows themselves (create and issue an invoice, capture a bill,
 * record a payment, reconcile bank lines, open the VAT201 working paper, read the Command centre, use the command bar) were driven
 * in the built-in browser at 375x812, light and dark, with real screenshots and a measuring script; see docs/R44_QA_acceptance.md for
 * what was seen. A PHPUnit test cannot measure pixels, so what is asserted here is what the browser pass found and fixed, at the source
 * level, so it cannot quietly come back: line editors are not horizontally scrolling tables, the 44px touch-target rules exist for
 * phone widths, the working paper wraps long cells, and every page of the workflows renders the markup those rules hang on.
 */
class Acceptance32_MobileTest extends AcceptanceCase
{
    public function test_the_phone_touch_target_rules_exist_and_cover_tabs_links_controls_and_disclosures(): void
    {
        $css = File::get(resource_path('css/components.css'));
        $this->assertStringContainsString('Touch targets on a phone', $css);
        preg_match('/@media \(max-width: 759\.98px\) \{\s*:root \{\s*--control-h: 2\.75rem;.*?\n\}\n/s', $css, $block);
        $this->assertNotEmpty($block, 'the phone block sets the control height to 44px');
        foreach (['.fx-top__brand', '.fx-tab', '.fx-linkbtn', 'a.fx-link', '.fx-trace::after', '.fx-check', '.tax-drill > summary', '.sx-lead > a.fx-link'] as $selector) {
            $this->assertStringContainsString($selector, $block[0], "{$selector} is in the phone touch-target block");
        }
        $this->assertSame(2.75 * 16, 44.0, '2.75rem is 44px at the default size');
        $this->assertStringContainsString('min-height: 2.75rem', $block[0]);
    }

    public function test_line_editors_become_labelled_full_width_rows_on_a_phone_instead_of_a_sideways_scrolling_table(): void
    {
        foreach (['purchases/bills/form', 'purchases/claims/form', 'purchases/recurring/create'] as $view) {
            $source = File::get(resource_path("views/{$view}.blade.php"));
            $this->assertDoesNotMatchRegularExpression('/<x-fx\.table[^>]* scroll>/', $source, "{$view}: a table of inputs must use card mode, not sideways scrolling");
            $this->assertStringContainsString('data-label=', $source, "{$view}: every cell carries its label for card mode");
        }
        $css = File::get(resource_path('css/components.css'));
        $this->assertStringContainsString('td:has(> .fx-input, > .fx-select)', $css);
    }

    public function test_the_working_paper_and_the_return_page_wrap_long_cells_and_banners_so_the_page_never_scrolls_sideways(): void
    {
        $pages = File::get(resource_path('css/pages.css'));
        $this->assertMatchesRegularExpression('/\.tax-sheet__table th,\s*\.tax-sheet__table td \{[^}]*overflow-wrap: anywhere/s', $pages);
        $this->assertMatchesRegularExpression('/\.tax-banner \{[^}]*flex-wrap: wrap/s', $pages);
        $this->assertMatchesRegularExpression('/\.tax-rules td,\s*\.tax-rules th \{\s*overflow-wrap: anywhere/s', $pages);
    }

    public function test_every_workflow_page_renders_with_one_h1_the_viewport_meta_and_no_inline_style_or_script(): void
    {
        $this->runMonth();
        $invoice = $this->inv1;
        $urls = [
            route('dashboard'), route('sales.invoices.create'), route('sales.documents.show', $invoice), route('purchases.bills.create'), route('sales.payments.create', ['document' => $invoice->id]),
            route('bank.reconciliation.index'), route('bank.reconciliation.show', BankReconciliation::query()->forContext($this->ledger)->firstOrFail()), route('tax.vat.index'),
        ];
        foreach ($urls as $url) {
            $html = $this->asOwner()->get($url)->assertOk()->getContent();
            $this->assertSame(1, preg_match_all('/<h1[\s>]/', $html), "{$url}: one h1");
            $this->assertStringContainsString('name="viewport" content="width=device-width, initial-scale=1"', $html);
            $this->assertDoesNotMatchRegularExpression('/\sstyle="/', $html, "{$url}: no inline style");
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*(nonce|src))/i', $html, "{$url}: no un-nonced script");
        }
        $this->assertStringContainsString('data-label="Qty"', $this->asOwner()->get(route('purchases.bills.create'))->getContent());
    }

    public function test_the_command_bar_is_a_modal_dialog_with_a_labelled_search_field_and_it_lists_the_workflow_actions(): void
    {
        $this->setUpIntegration();
        $html = $this->asOwner()->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('<dialog', $html);
        $this->assertMatchesRegularExpression('/<dialog[^>]*id="fx-command"[^>]*aria-label="Quick actions and search"/', $html);
        foreach (['Create an invoice', 'Capture a bill', 'Import a bank statement'] as $action) {
            $this->assertStringContainsString($action, $html, $action);
        }
    }
}
