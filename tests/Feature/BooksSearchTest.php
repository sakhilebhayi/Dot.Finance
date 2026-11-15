<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Purchases\Bill;
use App\Models\Sales\SalesDocument;
use App\Models\Shared\Party;
use App\Models\Tax\TaxReturn;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * R-52: the header search finds things in the books that are OPEN (JSON for the shared dropdown, /search/suggest). It adds accounts,
 * customers, suppliers, invoices, bills, tax periods and the sidebar's pages to the transactions search Finance already had, and it
 * never leaves the active ledger and never offers a group the person's role could not open.
 */
class BooksSearchTest extends TestCase
{
    use BuildsCompanyLedgers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // the platform switcher asks InfoDot on every page; no test here needs it
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 500)]);
    }

    /** One of every searchable kind in a company, all carrying $word. @return array<string, int> */
    private function seedBooks(Team $company, string $word): array
    {
        $owner = ['user_id' => null, 'team_id' => $company->id];
        $account = Account::factory()->forCompany($company)->create(['name' => "{$word} cheque", 'currency' => 'ZAR']);
        $this->entryOn($account, ['description' => "{$word} rent payment", 'type' => 'expense', 'date' => '2026-11-01']);
        $customer = Party::factory()->forCompany($company)->create(['name' => "{$word} customer", 'type' => 'customer']);
        $supplier = Party::factory()->forCompany($company)->supplier()->create(['name' => "{$word} supplier"]);
        $invoice = SalesDocument::create($owner + ['kind' => 'tax_invoice', 'party_id' => $customer->id, 'reference' => "PO-{$word}", 'currency' => 'ZAR', 'base_currency' => 'ZAR', 'status' => 'draft', 'issue_date' => '2026-11-01']);
        $bill = Bill::create($owner + ['party_id' => $supplier->id, 'number' => "BILL-{$word}", 'bill_date' => '2026-11-01', 'currency' => 'ZAR', 'status' => 'draft', 'kind' => 'bill']);
        $return = TaxReturn::create($owner + ['kind' => 'vat201', 'period_start' => '2026-09-01', 'period_end' => '2026-10-31', 'period_label' => "{$word} Sep-Oct 2026", 'status' => 'draft']);

        return ['account' => $account->id, 'customer' => $customer->id, 'supplier' => $supplier->id, 'invoice' => $invoice->id, 'bill' => $bill->id, 'return' => $return->id];
    }

    /** @return array<string, list<array<string, mixed>>> head => rows */
    private function found($response): array
    {
        $response->assertOk();
        $out = [];
        foreach ($response->json('groups') as $group) {
            $out[$group['head']] = $group['rows'];
        }

        return $out;
    }

    private function labels(array $groups): array
    {
        // the "Search all transactions for ..." action row repeats the typed words by design; it is not a result
        return collect($groups)->flatten(1)->reject(fn ($row) => ($row['action'] ?? false) === true)->map(fn ($row) => ($row['code'] ?? '').' '.$row['label'])->values()->all();
    }

    public function test_it_finds_every_kind_of_record_in_the_open_company_and_links_to_pages_that_exist(): void
    {
        $owner = $this->user();
        $acme = $this->company($owner, 'Acme (Pty) Ltd');
        $this->seedBooks($acme, 'Zebra');

        $groups = $this->found($this->inCompany($owner, $acme)->getJson(route('search.suggest', ['q' => 'zebra'])));

        foreach (['Transactions', 'Accounts', 'Customers', 'Suppliers', 'Invoices and quotes', 'Bills', 'Tax periods'] as $head) {
            $this->assertArrayHasKey($head, $groups, "{$head} is searched");
            $this->assertNotEmpty($groups[$head]);
        }
        $this->assertStringContainsString('Zebra', $groups['Customers'][0]['label']);
        $this->assertSame('Draft', $groups['Invoices and quotes'][0]['code'], 'a draft has no number yet');
        $this->assertSame('VAT201', $groups['Tax periods'][0]['code']);

        // every row links inside this application, to a route it has (no invented URL, no other host)
        foreach (collect($groups)->flatten(1) as $row) {
            $this->assertStringStartsWith(config('app.url'), $row['href'], 'a link on this site');
        }
        $this->assertStringContainsString('account_id=', $groups['Accounts'][0]['href'], 'an account opens its transactions');
    }

    public function test_it_never_returns_a_row_of_another_ledger(): void
    {
        $owner = $this->user();
        $acme = $this->company($owner, 'Acme (Pty) Ltd');
        $globex = $this->company($owner, 'Globex (Pty) Ltd');
        $this->seedBooks($acme, 'Acmeword');
        $this->seedBooks($globex, 'Globexword');
        $personal = Account::factory()->create(['user_id' => $owner->id, 'team_id' => null, 'name' => 'Personalword wallet']);
        $this->entryOn($personal, ['description' => 'Personalword coffee', 'type' => 'expense', 'date' => '2026-11-02']);

        // Acme sees Acme only, even for words of the others
        $mine = $this->labels($this->found($this->inCompany($owner, $acme)->getJson(route('search.suggest', ['q' => 'Acmeword']))));
        $this->assertNotEmpty($mine);
        foreach (['Globexword', 'Personalword'] as $other) {
            $hits = $this->labels($this->found($this->inCompany($owner, $acme)->getJson(route('search.suggest', ['q' => $other]))));
            $this->assertSame([], $hits, "{$other} is not in Acme's books");
        }

        // and the personal ledger sees only the personal one
        $personalHits = $this->labels($this->found($this->inPersonal($owner)->getJson(route('search.suggest', ['q' => 'word']))));
        $this->assertNotEmpty(array_filter($personalHits, fn ($l) => str_contains($l, 'Personalword')));
        $this->assertSame([], array_filter($personalHits, fn ($l) => str_contains($l, 'Acmeword') || str_contains($l, 'Globexword')));
    }

    public function test_a_person_who_is_not_a_member_of_the_company_gets_nothing_from_it(): void
    {
        $owner = $this->user();
        $acme = $this->company($owner, 'Acme (Pty) Ltd');
        $this->seedBooks($acme, 'Secretword');

        $stranger = $this->user();
        // a forged session pointing at a company the person does not belong to must fall back to their own ledger, never reach Acme
        $labels = $this->labels($this->found($this->inCompany($stranger, $acme)->getJson(route('search.suggest', ['q' => 'Secretword']))));

        $this->assertSame([], array_filter($labels, fn ($l) => str_contains($l, 'Secretword')));
    }

    public function test_a_viewer_sees_what_a_viewer_may_open_and_the_groups_follow_the_role(): void
    {
        $owner = $this->user();
        $acme = $this->company($owner, 'Acme (Pty) Ltd');
        $this->seedBooks($acme, 'Viewword');
        $viewer = $this->memberOf($acme, 'viewer');
        $unknown = $this->memberOf($acme, 'some-role-this-app-does-not-define');

        // read-only people read the books: the same groups, and nothing that needs a recording right is offered as a record to change
        foreach ([$viewer, $unknown] as $person) {
            $groups = $this->found($this->inCompany($person, $acme)->getJson(route('search.suggest', ['q' => 'Viewword'])));
            $this->assertArrayHasKey('Transactions', $groups);
            $this->assertArrayHasKey('Accounts', $groups);
        }

        // pages are filtered by the same gates as the sidebar: a viewer is not offered "Transfers" (record-entries), the owner is
        $viewerPages = $this->found($this->inCompany($viewer, $acme)->getJson(route('search.suggest', ['q' => 'transfers'])))['Pages'] ?? [];
        $ownerPages = $this->found($this->inCompany($owner, $acme)->getJson(route('search.suggest', ['q' => 'transfers'])))['Pages'] ?? [];
        $this->assertSame([], array_values(array_filter($viewerPages, fn ($r) => $r['label'] === 'Transfers')));
        $this->assertNotEmpty(array_filter($ownerPages, fn ($r) => $r['label'] === 'Transfers'));
    }

    public function test_the_words_are_data_wildcards_are_literal_and_short_or_empty_queries_return_nothing(): void
    {
        $owner = $this->user();
        $acme = $this->company($owner, 'Acme (Pty) Ltd');
        $account = $this->companyAccount($acme, ['name' => 'Main account']);
        $this->entryOn($account, ['description' => '100% safe', 'type' => 'expense', 'date' => '2026-11-01']);
        $this->entryOn($account, ['description' => '100 sheep', 'type' => 'expense', 'date' => '2026-11-01']);

        $labels = $this->labels($this->found($this->inCompany($owner, $acme)->getJson(route('search.suggest', ['q' => '100%']))));
        $this->assertContains(' 100% safe', $labels);
        $this->assertNotContains(' 100 sheep', $labels, 'a percent sign is a character, not a wildcard');

        foreach (['', 'a', ' ', "'; drop table accounts; --"] as $q) {
            $this->inCompany($owner, $acme)->getJson(route('search.suggest', ['q' => $q]))->assertOk();
        }
        $this->assertSame([], $this->inCompany($owner, $acme)->getJson(route('search.suggest', ['q' => 'a']))->json('groups'));
        $this->assertSame([], $this->inCompany($owner, $acme)->getJson(route('search.suggest'))->json('groups'));
        $this->assertDatabaseCount('accounts', 1);
        $this->inCompany($owner, $acme)->getJson(route('search.suggest', ['q' => str_repeat('x', 500)]))->assertOk();
        $this->inCompany($owner, $acme)->getJson(route('search.suggest', ['q' => ['a' => 'b']]))->assertOk();
    }

    public function test_no_match_returns_only_the_action_row_and_each_group_is_limited(): void
    {
        $owner = $this->user();
        $acme = $this->company($owner, 'Acme (Pty) Ltd');
        $account = $this->companyAccount($acme, ['name' => 'Main account']);
        foreach (range(1, 9) as $n) {
            $this->entryOn($account, ['description' => "Limitword {$n}", 'type' => 'expense', 'date' => '2026-11-0'.$n]);
        }

        $groups = $this->found($this->inCompany($owner, $acme)->getJson(route('search.suggest', ['q' => 'Limitword'])));
        $this->assertCount(5, $groups['Transactions'], 'a group shows five rows, the full list is one click away');

        $none = $this->found($this->inCompany($owner, $acme)->getJson(route('search.suggest', ['q' => 'Nomatchwordxyz'])));
        $this->assertSame(['' => [['href' => route('transactions.index', ['search' => 'Nomatchwordxyz']), 'label' => 'Search all transactions for "Nomatchwordxyz"', 'action' => true]]], $none, 'the empty state is the one honest action: search the transactions');
    }

    public function test_a_guest_is_sent_to_sign_in_and_the_response_is_never_cached(): void
    {
        $this->get(route('search.suggest', ['q' => 'rent']))->assertRedirect(route('login'));

        $owner = $this->user();
        $response = $this->inPersonal($owner)->getJson(route('search.suggest', ['q' => 'rent']))->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_header_search_is_on_every_signed_in_page_type_with_its_suggestions_endpoint(): void
    {
        $owner = $this->user();
        $acme = $this->company($owner, 'Acme (Pty) Ltd');
        $this->companyAccount($acme, ['name' => 'Main account']);

        // a dashboard, a list, a form, a report, a settings page and the profile: one header, one pill, at every width (CSS decides the layout)
        foreach (['/dashboard', '/transactions', '/transactions/create', '/accounts', '/budgets', '/reconciliation', '/user/profile'] as $url) {
            $html = $this->inCompany($owner, $acme)->get($url)->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'class="dsr-input"'), "{$url}: exactly one search pill");
            $this->assertStringContainsString('data-fx-search="'.route('search.suggest').'"', $html, "{$url}: it asks for suggestions");
            $this->assertStringContainsString('data-dh-search-toggle', $html, "{$url}: the phone gets the search sheet button");
        }
    }
}
