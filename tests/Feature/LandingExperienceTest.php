<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Support\Ui\DashboardPanels;
use App\Support\Ui\Navigation;
use App\Support\Ui\StateLine;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * R-50: the landing a person sees on arriving from InfoDot (the top of /dashboard). It shows the original logo, a welcome
 * made from the person's own name and ledger, exactly one primary action that leads somewhere this person can work, the
 * way back to InfoDot, and what the ledger holds in counts read from its own rows. An empty ledger says it is empty; it
 * never prints a zero that could be read as a result.
 */
class LandingExperienceTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    /** SHA-256 of the logo files as they were before this work: the landing shows them, it never alters them. */
    private const LOGO_HASHES = [
        'images/logo.png' => '8c7bee7e661e302d9e012b658d57b14815707191794107a72db62a70f1d4ca91',
        'images/logo-light.png' => 'a2fc9cdb86f260733760b8eead2d62d9783399d80b42995ceb1251e7382b33ac',
    ];

    protected function tearDown(): void
    {
        StateLine::flush();
        DashboardPanels::flush();
        Navigation::flush();
        parent::tearDown();
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    /** The landing section of a rendered dashboard. */
    private function landing(DOMXPath $page): DOMElement
    {
        $found = $page->query('//section[@data-landing]');
        $this->assertSame(1, $found->length, 'the page has one landing');
        $section = $found->item(0);
        $this->assertInstanceOf(DOMElement::class, $section);

        return $section;
    }

    /** The landing's words, without the screen-reader labels inside icons and without today's date line. */
    private function words(DOMElement $landing): string
    {
        $landing = $landing->cloneNode(true);
        $xp = new DOMXPath($landing->ownerDocument);
        foreach (iterator_to_array($xp->query('.//*[@data-landing-date]', $landing)) as $date) {
            $date->parentNode?->removeChild($date);
        }

        return trim((string) preg_replace('/\s+/', ' ', $landing->textContent));
    }

    /** The one primary action on the whole page: its label and where it leads. @return array{label: string, href: string} */
    private function primaryAction(DOMXPath $page): array
    {
        $marked = $page->query('//*[@data-primary-action]');
        $this->assertSame(1, $marked->length, 'exactly one element carries the primary-action marker');
        $action = $marked->item(0);
        $this->assertInstanceOf(DOMElement::class, $action);
        $this->assertSame(1, $page->query('//section[@data-landing]//*[@data-primary-action]')->length, 'and it is in the landing');
        $this->assertSame(1, $page->query('//main//*[contains(concat(" ", normalize-space(@class), " "), " fx-btn--primary ")]')->length, 'nothing else in the page body is styled as a primary button');

        return ['label' => trim($action->textContent), 'href' => $action->getAttribute('href')];
    }

    /** A company with one account and two entries, and the people who can arrive in it. */
    private function world(): array
    {
        $owner = $this->user();
        $owner->forceFill(['name' => 'Naledi Dlamini'])->save();
        $company = $this->company($owner, 'Karoo Roasters (Pty) Ltd');
        $account = $this->companyAccount($company, ['name' => 'Operating', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance_cents' => 500_000]);
        $this->entryOn($account, ['description' => 'Green coffee, Sidamo', 'type' => 'expense', 'amount_cents' => 120_000, 'date' => '2026-03-02']);
        $this->entryOn($account, ['description' => 'Wholesale order, Stellenbosch', 'type' => 'income', 'amount_cents' => 340_000, 'date' => '2026-03-09']);

        return ['owner' => $owner, 'company' => $company, 'account' => $account];
    }

    public function test_the_logo_files_are_the_originals_and_the_layout_shows_them(): void
    {
        foreach (self::LOGO_HASHES as $file => $hash) {
            $this->assertFileExists(public_path($file));
            $this->assertSame($hash, hash_file('sha256', public_path($file)), "{$file} is no longer the original artwork");
        }

        $page = $this->xpath($this->actingAs($this->user())->get(route('dashboard'))->assertOk()->getContent());
        // The dashboard no longer repeats the logo in its body (R-51): the sidebar carries it, and the top bar on a phone.
        $this->assertSame(0, $page->query('//section[@data-landing]//img')->length, 'the landing does not repeat the logo');
        $logos = $page->query('//aside//img');

        $this->assertSame(2, $logos->length, 'the day artwork and the night artwork');
        $sources = [];
        foreach ($logos as $logo) {
            $this->assertInstanceOf(DOMElement::class, $logo);
            $this->assertSame('Dot.Finance', $logo->getAttribute('alt'));
            $sources[] = parse_url($logo->getAttribute('src'), PHP_URL_PATH);
        }
        $this->assertSame(['/images/logo.png', '/images/logo-light.png'], $sources);
        $this->assertSame(0, $page->query('//main//*[contains(@class, "fx-mark")]')->length, 'the page does not draw the chrome mark in place of the logo');
    }

    public function test_a_company_owner_is_welcomed_by_name_into_the_company_books_with_one_primary_action_that_works(): void
    {
        $w = $this->world();

        $page = $this->xpath($this->inCompany($w['owner'], $w['company'])->get(route('dashboard'))->assertOk()->getContent());
        $words = $this->words($this->landing($page));

        $this->assertStringContainsString('Every figure here traces back to the entries behind it', $words);
        $this->assertStringContainsString('ZAR 7,200.00 in cash in one account.', $words);
        $this->assertStringContainsString('Karoo Roasters (Pty) Ltd', trim((string) $page->query('//section[@data-landing]//*[@data-landing-date]')->item(0)?->textContent));

        $action = $this->primaryAction($page);
        $this->assertSame('Add transaction', $action['label']);
        $this->assertSame(route('transactions.create'), $action['href']);
        $this->inCompany($w['owner'], $w['company'])->get($action['href'])->assertOk()->assertSee('Save transaction')->assertSee('Operating');
    }

    public function test_the_primary_action_answers_for_every_role_that_can_arrive(): void
    {
        $w = $this->world();
        $expected = [
            'admin' => ['Add transaction', route('transactions.create')],
            'editor' => ['Add transaction', route('transactions.create')],
            'viewer' => ['Read the entries', route('transactions.index')],
            'something-else' => ['Read the entries', route('transactions.index')],
        ];

        foreach ($expected as $role => [$label, $href]) {
            $member = $this->memberOf($w['company'], $role);
            $page = $this->xpath($this->inCompany($member, $w['company'])->get(route('dashboard'))->assertOk()->getContent());
            $action = $this->primaryAction($page);

            $this->assertSame($label, $action['label'], "{$role}: the label");
            $this->assertSame($href, $action['href'], "{$role}: the target");
            $this->inCompany($member, $w['company'])->get($action['href'])->assertOk();
        }
    }

    public function test_a_read_only_member_is_not_told_to_record_and_is_offered_nothing_they_cannot_open(): void
    {
        $w = $this->world();
        $viewer = $this->memberOf($w['company'], 'viewer');

        $html = $this->inCompany($viewer, $w['company'])->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('You have read-only access to Karoo Roasters', $html);
        $this->assertStringNotContainsString('Record what happened', $html);
        $this->assertStringNotContainsString(route('transactions.create'), $html);
    }

    public function test_a_new_member_with_nothing_sees_an_honest_empty_ledger_and_no_number_at_all(): void
    {
        $user = $this->user();
        $user->forceFill(['name' => 'Nomsa Khumalo'])->save();

        $page = $this->xpath($this->inPersonal($user)->get(route('dashboard'))->assertOk()->getContent());
        $landing = $this->landing($page);
        $words = $this->words($landing);

        $this->assertStringContainsString('Your own ledger.', (string) $page->query('//section[@data-landing]//*[@data-landing-date]')->item(0)?->textContent);
        $this->assertSame(1, $page->query('//section[@data-landing]//*[@data-landing-empty]')->length);
        $this->assertStringContainsString('Nothing has been recorded yet.', $words);
        $this->assertStringContainsString('no account and no entry', $words);
        $this->assertSame(0, $page->query('//section[@data-landing]//*[@data-landing-count]')->length, 'an empty ledger shows no counts');
        $this->assertDoesNotMatchRegularExpression('/\d/', $words, 'an empty ledger shows no digit in the landing, not even a zero');

        // A transaction needs an account, so the first action for an empty ledger is the account, and the page that opens can add one.
        $action = $this->primaryAction($page);
        $this->assertSame('Add your first account', $action['label']);
        $this->assertSame(route('accounts.index'), $action['href']);
        $this->inPersonal($user)->get($action['href'])->assertOk()->assertSee('Add account');
    }

    public function test_an_empty_company_ledger_gives_each_role_one_action_it_can_take(): void
    {
        $owner = $this->user();
        $company = $this->company($owner, 'Fynbos Print Works (Pty) Ltd');
        $viewer = $this->memberOf($company, 'viewer');

        $page = $this->xpath($this->inCompany($owner, $company)->get(route('dashboard'))->assertOk()->getContent());
        $action = $this->primaryAction($page);
        $this->assertSame('Add the first company account', $action['label']);
        $this->assertSame(route('accounts.index'), $action['href']);
        $this->assertStringContainsString('Fynbos Print Works (Pty) Ltd has no account and no entry', $this->words($this->landing($page)));

        $page = $this->xpath($this->inCompany($viewer, $company)->get(route('dashboard'))->assertOk()->getContent());
        $action = $this->primaryAction($page);
        $this->assertSame('Read the entries', $action['label']);
        $this->inCompany($viewer, $company)->get($action['href'])->assertOk();
        $this->assertStringContainsString('A company owner or admin needs to add the first account.', $this->words($this->landing($page)));
    }

    public function test_every_number_in_the_landing_is_read_from_the_ledgers_own_rows(): void
    {
        $w = $this->world();

        // Another company and the owner's personal ledger hold rows too: none of them may be counted here.
        $other = $this->company($w['owner'], 'Other Co (Pty) Ltd');
        $otherAccount = $this->companyAccount($other);
        for ($i = 0; $i < 7; $i++) {
            $this->entryOn($otherAccount, ['date' => '2026-04-0'.($i + 1)]);
        }
        Account::factory()->create(['user_id' => $w['owner']->id]);

        $page = $this->xpath($this->inCompany($w['owner'], $w['company'])->get(route('dashboard'))->assertOk()->getContent());
        $landing = $this->landing($page);

        $this->assertSame('1', trim($page->query('//section[@data-landing]//*[@data-landing-count="accounts"]')->item(0)?->textContent ?? ''));
        $this->assertSame('2', trim($page->query('//section[@data-landing]//*[@data-landing-count="entries"]')->item(0)?->textContent ?? ''));
        $this->assertSame('2026-03-09', trim($page->query('//section[@data-landing]//*[@data-landing-last]')->item(0)?->textContent ?? ''));

        // The cash figure in the headline is the company's own account only: 5,000.00 opening - 1,200.00 + 3,400.00. The other
        // company's entries and the personal ledger's account are in no figure here.
        $headline = trim((string) $page->query('//section[@data-landing]//h2//*[contains(@class, "fx-money")]')->item(0)?->textContent);
        $this->assertSame('ZAR 7,200.00', $headline);
        $this->assertStringNotContainsString('Courier', $this->words($landing));

        // One more entry, and the count follows it.
        $this->entryOn($w['account'], ['description' => 'Courier', 'date' => '2026-03-11']);
        $page = $this->xpath($this->inCompany($w['owner'], $w['company'])->get(route('dashboard'))->assertOk()->getContent());
        $this->assertSame((string) Transaction::query()->where('account_id', $w['account']->id)->count(), trim($page->query('//section[@data-landing]//*[@data-landing-count="entries"]')->item(0)?->textContent ?? ''));
    }

    public function test_an_account_with_no_entries_is_said_in_words_not_as_a_zero(): void
    {
        $user = $this->user();
        Account::factory()->create(['user_id' => $user->id, 'opening_balance_cents' => 250_000]);

        $page = $this->xpath($this->inPersonal($user)->get(route('dashboard'))->assertOk()->getContent());
        $words = $this->words($this->landing($page));

        $this->assertStringContainsString('no entries yet', $words);
        $this->assertSame(0, $page->query('//section[@data-landing]//*[@data-landing-count="entries"]')->length);
        $this->assertSame('Add transaction', $this->primaryAction($page)['label']);
    }

    public function test_arriving_in_the_personal_ledger_offers_the_companies_the_person_belongs_to(): void
    {
        $w = $this->world();

        $html = $this->inPersonal($w['owner'])->get(route('dashboard'))->assertOk()->getContent();
        $page = $this->xpath($html);
        $switch = $page->query('//section[@data-landing]//form[@action="'.route('ledger.context.switch').'"]//button[@name="context"]');

        $this->assertStringContainsString('Your own ledger.', (string) $page->query('//section[@data-landing]//*[@data-landing-date]')->item(0)?->textContent);
        $this->assertSame(1, $switch->length);
        $this->assertSame((string) $w['company']->id, $switch->item(0)?->getAttribute('value'));
        $this->assertSame('Karoo Roasters (Pty) Ltd', trim($switch->item(0)?->textContent ?? ''));
        $this->primaryAction($page);

        // In the company there is nothing to switch to from the landing; the sidebar keeps the full switcher.
        $inCompany = $this->xpath($this->inCompany($w['owner'], $w['company'])->get(route('dashboard'))->getContent());
        $this->assertSame(0, $inCompany->query('//section[@data-landing]//form')->length);
    }

    public function test_the_way_back_to_infodot_and_its_platforms_page_comes_from_the_ecosystem_configuration(): void
    {
        $user = $this->user();

        $page = $this->xpath($this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent());
        $links = [];
        foreach ($page->query('//section[@data-landing]//nav[@aria-label="Dot ecosystem"]//a') as $link) {
            $this->assertInstanceOf(DOMElement::class, $link);
            $links[trim($link->textContent)] = $link->getAttribute('href');
        }
        $this->assertSame(['InfoDot' => 'https://infodot.co.za/home', 'Platforms' => 'https://infodot.co.za/platforms'], $links);

        config(['ecosystem.platforms.infodot.url' => 'https://infodot.test/']);
        $this->actingAs($user)->get(route('dashboard'))->assertSee('href="https://infodot.test/home"', false)->assertSee('href="https://infodot.test/platforms"', false);
    }

    public function test_the_platform_list_holds_only_current_platforms(): void
    {
        $current = ['agents', 'analytics', 'auction', 'central', 'charts', 'design', 'docs', 'dopemine', 'ehail', 'emall', 'engage', 'files', 'finance',
            'forms', 'hr', 'memory', 'mines', 'notify', 'press', 'projects', 'pulse', 'sheet', 'tasks', 'tutor', 'infodot'];

        $this->assertSame([], array_values(array_diff(array_keys(config('ecosystem.platforms')), $current)));
        $this->assertSame('Dot.Finance', config('ecosystem.platforms.finance.name'));
    }

    public function test_everything_the_page_offered_before_is_still_reachable_from_it(): void
    {
        $w = $this->world();

        $html = $this->inCompany($w['owner'], $w['company'])->get(route('dashboard'))->assertOk()->getContent();

        foreach (['Command centre', 'Cash available', 'Coming up', 'Needs you', 'Budgets this month', 'Spending trend', 'Recent entries', 'Accounts', 'Selected account'] as $panel) {
            $this->assertStringContainsString($panel, $html);
        }
        foreach (['transactions.create', 'transactions.index', 'accounts.index', 'budgets.index'] as $route) {
            $this->assertStringContainsString('href="'.route($route).'"', $html, $route);
        }
    }
}
