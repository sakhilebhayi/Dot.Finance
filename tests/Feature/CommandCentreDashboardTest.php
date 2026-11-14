<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Support\Ui\DashboardPanels;
use App\Support\Ui\Navigation;
use App\Support\Ui\StateLine;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * R-51: the Command centre as ruled rows. The accounts ledger (grouped by currency, cash added up apart from cards), the account
 * panel beside it, the statement per currency, and the rules that keep every figure honest: one query per figure and all of them
 * confined to the active ledger, currencies never added together, a selection that must be one of this ledger's own accounts.
 */
class CommandCentreDashboardTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        StateLine::flush();
        DashboardPanels::flush();
        Navigation::flush();
        parent::tearDown();
    }

    private function page(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    private function text(DOMXPath $x, string $query): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $x->query($query)->item(0)?->textContent ?? ''));
    }

    /** A company with ZAR cash, a ZAR card and a USD account, and entries on the first. */
    private function world(): array
    {
        $owner = $this->user();
        $company = $this->company($owner, 'Karoo Roasters (Pty) Ltd');
        $operating = $this->companyAccount($company, ['name' => 'Operating', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance_cents' => 1_000_000, 'institution' => 'First Bank']);
        $savings = $this->companyAccount($company, ['name' => 'Reserve', 'type' => 'savings', 'currency' => 'ZAR', 'opening_balance_cents' => 500_000, 'institution' => 'First Bank']);
        $card = $this->companyAccount($company, ['name' => 'Company card', 'type' => 'credit', 'currency' => 'ZAR', 'opening_balance_cents' => -250_000]);
        $dollars = $this->companyAccount($company, ['name' => 'Dollar account', 'type' => 'checking', 'currency' => 'USD', 'opening_balance_cents' => 120_000]);
        $this->entryOn($operating, ['description' => 'Wholesale order', 'type' => 'income', 'amount_cents' => 340_000, 'date' => now()->startOfMonth()->toDateString()]);
        $this->entryOn($operating, ['description' => 'Green coffee', 'type' => 'expense', 'amount_cents' => 120_000, 'date' => now()->startOfMonth()->toDateString()]);

        return compact('owner', 'company', 'operating', 'savings', 'card', 'dollars');
    }

    public function test_the_ledger_groups_by_currency_adds_the_cash_apart_from_the_card_and_never_mixes_currencies(): void
    {
        $w = $this->world();
        $x = $this->page($this->inCompany($w['owner'], $w['company'])->get(route('dashboard'))->assertOk()->getContent());

        $groups = [];
        foreach ($x->query('//table[@id="fxd-accounts"]//tr[contains(@class,"fxd-grp")]/th') as $g) {
            $groups[] = strtok(trim($g->textContent), ' ');
        }
        $this->assertSame(['ZAR', 'USD'], $groups, 'the lead currency first, one group each');

        // The tie-out: the subtotal is the sum of the cash rows above it. 10,000.00 + 5,000.00 + 3,400.00 - 1,200.00 = 17,200.00
        $this->assertSame('ZAR 17,200.00', $this->text($x, '(//table[@id="fxd-accounts"]//tr[contains(@class,"fxd-sub")])[1]/td//*[contains(@class,"fx-money")]'));
        $this->assertSame('USD 1,200.00', $this->text($x, '(//table[@id="fxd-accounts"]//tr[contains(@class,"fxd-sub")])[2]/td//*[contains(@class,"fx-money")]'));
        $rows = [];
        foreach ($x->query('//table[@id="fxd-accounts"]//tr[@data-fxd-pick]') as $r) {
            $rows[] = $r->getAttribute('data-name');
        }
        $this->assertSame(['Operating', 'Reserve', 'Company card', 'Dollar account'], $rows, 'cash rows, then the card below the subtotal');
        $this->assertStringContainsString('owed', $this->text($x, '//tr[@data-name="Company card"]'));

        // The headline is the lead currency's cash alone: nothing here adds ZAR and USD together.
        $this->assertSame('ZAR 17,200.00', $this->text($x, '//section[@data-landing]//h2//*[contains(@class,"fx-money")]'));
        $html = $x->document->saveHTML();
        $this->assertStringNotContainsString('18,400.00', $html, 'ZAR cash plus USD would be 18,400.00: it is never drawn');
    }

    public function test_the_statement_has_a_column_per_currency_and_the_month_net_is_income_less_spending(): void
    {
        $w = $this->world();
        $x = $this->page($this->inCompany($w['owner'], $w['company'])->get(route('dashboard'))->assertOk()->getContent());

        $this->assertSame(['ZAR', 'USD'], array_map(fn ($n) => trim($n->textContent), iterator_to_array($x->query('//section[@id="cash-position"]//thead//th[contains(@class,"is-num")]'))));
        $this->assertStringContainsString('3,400.00', $this->text($x, '//section[@id="cash-position"]//tbody/tr[1]'));
        $this->assertStringContainsString('1,200.00', $this->text($x, '//section[@id="cash-position"]//tbody/tr[2]'));
        $this->assertStringContainsString('+2,200.00', $this->text($x, '//section[@id="cash-position"]//tbody/tr[3]'));
        $this->assertStringContainsString('+ZAR 2,200.00 net', $this->text($x, '//section[@data-landing]//h2'));
    }

    public function test_more_than_three_currencies_put_the_rest_in_a_disclosure_with_the_same_rows(): void
    {
        $owner = $this->user();
        foreach (['ZAR', 'USD', 'EUR', 'GBP'] as $i => $code) {
            Account::factory()->create(['user_id' => $owner->id, 'currency' => $code, 'type' => 'checking', 'name' => 'Acct '.$code, 'opening_balance_cents' => 100_000]);
        }

        $x = $this->page($this->inPersonal($owner)->get(route('dashboard'))->assertOk()->getContent());

        $this->assertSame(3, $x->query('//section[@id="cash-position"]/div/table//thead//th[contains(@class,"is-num")]')->length);
        $this->assertSame(1, $x->query('//section[@id="cash-position"]//details//thead//th[contains(@class,"is-num")]')->length);
        $this->assertStringContainsString('Other currencies (1)', $this->text($x, '//section[@id="cash-position"]//details/summary'));
    }

    public function test_the_selected_account_panel_facts_are_the_rows_of_that_account(): void
    {
        $w = $this->world();
        $x = $this->page($this->inCompany($w['owner'], $w['company'])->get(route('dashboard'))->assertOk()->getContent());

        $this->assertSame('Operating', $this->text($x, '//*[@data-fxd-stage]//*[@data-s="name"]'));
        $this->assertSame((string) Transaction::query()->where('account_id', $w['operating']->id)->count(), $this->text($x, '//*[@data-fxd-stage]//*[@data-s="n"]'));
        $this->assertSame(now()->startOfMonth()->toDateString(), $this->text($x, '//*[@data-fxd-stage]//*[@data-s="last"]'));
        $this->assertSame('ZAR 10,000.00', $this->text($x, '//*[@data-fxd-stage]//*[@data-s="open"]'));
        $this->assertSame('ZAR 12,200.00', $this->text($x, '//*[@data-fxd-stage]//*[@data-s="bal"]'));

        // An account with no entries says so in words, not as a zero date.
        $x = $this->page($this->inCompany($w['owner'], $w['company'])->get(route('dashboard', ['account' => $w['savings']->id]))->getContent());
        $this->assertSame('Reserve', $this->text($x, '//*[@data-fxd-stage]//*[@data-s="name"]'));
        $this->assertSame('0', $this->text($x, '//*[@data-fxd-stage]//*[@data-s="n"]'));
        $this->assertSame('None yet', $this->text($x, '//*[@data-fxd-stage]//*[@data-s="last"]'));
        $this->assertSame('true', $x->query('//tr[@data-name="Reserve"]')->item(0)?->getAttribute('aria-selected'));
    }

    public function test_the_selection_must_be_one_of_this_ledgers_own_accounts(): void
    {
        $w = $this->world();
        $other = $this->company($w['owner'], 'Other Co (Pty) Ltd');
        $foreign = $this->companyAccount($other, ['name' => 'Secret account', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance_cents' => 999_999_900]);
        $personal = Account::factory()->create(['user_id' => $w['owner']->id, 'name' => 'My personal account']);

        foreach ([(string) $foreign->id, (string) $personal->id, '999999', 'abc', '1; drop table accounts', '-1'] as $bad) {
            $response = $this->inCompany($w['owner'], $w['company'])->get(route('dashboard', ['account' => $bad]))->assertOk();
            $html = $response->getContent();
            $x = $this->page($html);
            $this->assertSame('Operating', $this->text($x, '//*[@data-fxd-stage]//*[@data-s="name"]'), "account={$bad} falls back to the first row");
            $this->assertStringNotContainsString('Secret account', $html);
            $this->assertStringNotContainsString('My personal account', $html);
            $this->assertStringNotContainsString('9,999,999.00', $html);
        }
    }

    public function test_nothing_from_another_company_or_the_personal_ledger_is_counted_or_listed(): void
    {
        $w = $this->world();
        $other = $this->company($w['owner'], 'Other Co (Pty) Ltd');
        $otherAccount = $this->companyAccount($other, ['name' => 'Other operating', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance_cents' => 777_700_00]);
        $this->entryOn($otherAccount, ['description' => 'Other co sale', 'type' => 'income', 'amount_cents' => 5_555_00, 'date' => now()->toDateString()]);

        $html = $this->inCompany($w['owner'], $w['company'])->get(route('dashboard'))->assertOk()->getContent();

        foreach (['Other operating', 'Other co sale', '77,770,000', '7,777,000'] as $leak) {
            $this->assertStringNotContainsString($leak, $html);
        }
        $x = $this->page($html);
        $this->assertSame('4', $this->text($x, '//*[@data-landing-count="accounts"]'));
        $this->assertSame('2', $this->text($x, '//*[@data-landing-count="entries"]'));
    }

    public function test_the_primary_action_is_still_one_and_a_viewer_is_offered_no_edit(): void
    {
        $w = $this->world();
        $viewer = $this->memberOf($w['company'], 'viewer');

        foreach ([[$w['owner'], 'Add transaction', true], [$viewer, 'Read the entries', false]] as [$user, $label, $canEdit]) {
            $x = $this->page($this->inCompany($user, $w['company'])->get(route('dashboard'))->assertOk()->getContent());
            $this->assertSame(1, $x->query('//*[@data-primary-action]')->length);
            $this->assertSame($label, $this->text($x, '//*[@data-primary-action]'));
            $this->assertSame($canEdit ? 1 : 0, $x->query('//*[@data-fxd-stage]//a[@data-s-href="edit"]')->length, 'only people who may edit an account are offered it');
            $this->assertSame($canEdit ? 4 : 0, $x->query('//tr[@data-fxd-pick][@data-edit]')->length);
        }
    }

    public function test_every_account_row_is_a_link_to_that_accounts_entries(): void
    {
        $w = $this->world();
        $x = $this->page($this->inCompany($w['owner'], $w['company'])->get(route('dashboard'))->getContent());

        $this->assertSame(route('transactions.index', ['account_id' => $w['card']->id]), $x->query('//tr[@data-name="Company card"]//a')->item(0)?->getAttribute('href'));
        $this->assertSame(4, $x->query('//tr[@data-fxd-pick]')->length);
    }

    public function test_empty_ledger_draws_no_figures_and_each_section_says_what_is_true(): void
    {
        $user = $this->user();
        $html = $this->inPersonal($user)->get(route('dashboard'))->assertOk()->getContent();
        $x = $this->page($html);

        $this->assertSame(1, $x->query('//*[@data-landing-empty]')->length);
        $this->assertSame(0, $x->query('//*[@data-fxd-stage]')->length, 'no account, no panel');
        $this->assertStringContainsString('No accounts yet.', $html);
        $this->assertStringContainsString('No budgets set up yet.', $html);
        $this->assertStringContainsString('Nothing is due in the next 14 days.', $html);
        $this->assertStringContainsString('No transactions yet.', $html);
        $this->assertStringContainsString('No trend to draw yet.', $html);
        $this->assertStringContainsString('Nothing needs you right now.', $html);
        $this->assertStringContainsString('Accounting is off for this ledger', $html);
        $this->assertSame(0, $x->query('//section[@id="cash-position"]')->length, 'no statement without an account');
    }

    public function test_a_ledger_with_accounts_but_no_cash_account_says_so_instead_of_a_figure(): void
    {
        $user = $this->user();
        Account::factory()->create(['user_id' => $user->id, 'name' => 'Visa', 'type' => 'credit', 'currency' => 'ZAR', 'opening_balance_cents' => -50_000]);

        $x = $this->page($this->inPersonal($user)->get(route('dashboard'))->assertOk()->getContent());

        $this->assertStringContainsString('No cash accounts in ZAR.', $this->text($x, '//section[@data-landing]//h2'));
        $this->assertStringContainsString('No cash accounts in ZAR', $this->text($x, '//table[@id="fxd-accounts"]//tr[contains(@class,"fxd-sub")]'));
    }

    public function test_the_page_has_no_inline_style_and_no_count_up_script(): void
    {
        $w = $this->world();
        $html = $this->inCompany($w['owner'], $w['company'])->get(route('dashboard'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/<(?!svg)[a-z][^>]*\sstyle=/i', $html, 'the CSP forbids style attributes');
        $this->assertStringNotContainsString('requestAnimationFrame', $html);

        // Motion is declared only for people who have not asked for less, and money is never counted up: no keyframe moves a figure's value.
        $css = (string) file_get_contents(resource_path('css/dashboard.css'));
        $this->assertStringContainsString('prefers-reduced-motion: no-preference', $css);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $css);
        $this->assertDoesNotMatchRegularExpression('/@property|counter-set|counter-increment/', $css);
        $this->assertDoesNotMatchRegularExpression('/setInterval|requestAnimationFrame|toLocaleString|textContent\s*=\s*.*\+\+/', (string) file_get_contents(resource_path('js/fx/dashboard.js')), 'the script never counts a number up');
    }

    public function test_the_cash_split_draws_the_real_shares_of_the_lead_currencys_cash_and_only_when_it_is_true(): void
    {
        $w = $this->world();
        $c = $this->inCompany($w['owner'], $w['company'])->get(route('dashboard'))->assertOk()->getContent();
        $x = $this->page($c);

        // Operating 10,000.00 + 3,400.00 - 1,200.00 = 12,200.00 and Reserve 5,000.00: 12,200 / 17,200 = 71%, 5,000 / 17,200 = 29%.
        $this->assertSame(1, $x->query('//figure[@data-fxd-split]')->length);
        $this->assertSame(2, $x->query('//figure[@data-fxd-split]//svg//rect')->length, 'one segment per cash account, the card and the USD account are not in it');
        $label = (string) $x->query('//figure[@data-fxd-split]//svg')->item(0)->getAttribute('aria-label');
        $this->assertStringContainsString('Operating 71 percent, Reserve 29 percent', $label);
        $this->assertSame(0, $x->query('//svg[contains(@class,"fxd-sheet")]')->length, 'the decorative sheet gives way to the real figure');
    }

    public function test_the_cash_split_is_not_drawn_for_one_cash_account_or_an_overdrawn_one(): void
    {
        $user = $this->user();
        Account::factory()->create(['user_id' => $user->id, 'name' => 'Only', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance_cents' => 100_000]);
        $x = $this->page($this->inPersonal($user)->get(route('dashboard'))->assertOk()->getContent());
        $this->assertSame(0, $x->query('//figure[@data-fxd-split]')->length);
        $this->assertSame(1, $x->query('//svg[contains(@class,"fxd-sheet")]')->length, 'the ruled sheet stays');

        Account::factory()->create(['user_id' => $user->id, 'name' => 'Overdrawn', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance_cents' => -20_000]);
        $x = $this->page($this->inPersonal($user)->get(route('dashboard'))->assertOk()->getContent());
        $this->assertSame(0, $x->query('//figure[@data-fxd-split]')->length, 'shares of a total that includes an overdraft would not be true');
    }

    public function test_the_headline_keeps_its_words_and_the_current_month_in_the_chart_is_marked_as_still_running(): void
    {
        $w = $this->world();
        $html = $this->inCompany($w['owner'], $w['company'])->get(route('dashboard'))->assertOk()->getContent();
        $x = $this->page($html);

        $this->assertStringContainsString('in cash across 2 accounts.', $this->text($x, '//section[@data-landing]//h2'));
        $this->assertSame(1, $x->query('//*[contains(@class,"fxd-say__cash")]//*[contains(@class,"fx-money")]')->length);
        $this->assertSame(1, $x->query('//svg[contains(@class,"fxd-chart")]//g[contains(@class,"is-running")]')->length, 'only the newest month is hatched');
        $this->assertStringContainsString('hatched pair is the current month', $html);
        // every bar still carries its exact amount
        $this->assertGreaterThanOrEqual(2, $x->query('//svg[contains(@class,"fxd-chart")]//rect/title')->length);
    }
}
