<?php

namespace Tests\Feature\Shared;

use App\Models\FinanceAuditLog;
use App\Models\Shared\Attachment;
use App\Models\Shared\Party;
use App\Models\User;
use App\Services\Shared\Attachments;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

class PartyTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->me = $this->user();
    }

    private function valid(array $overrides = []): array
    {
        return $overrides + ['type' => 'customer', 'name' => 'Karoo Foods (Pty) Ltd'];
    }

    private function pdf(string $body = 'x', string $name = 'contract.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% {$body}\n%%EOF\n");
    }

    // ── create, read, update, archive ─────────────────────────────────

    public function test_create_form_and_a_minimal_party(): void
    {
        $this->inPersonal($this->me)->get(route('parties.create'))->assertOk()->assertSee('Add a customer or supplier');

        $response = $this->post(route('parties.store'), $this->valid());

        $party = Party::query()->firstOrFail();
        $response->assertRedirect(route('parties.show', $party));
        $this->assertSame($this->me->id, $party->user_id);
        $this->assertNull($party->team_id);
        $this->assertSame('active', $party->status);
        $this->assertSame('Karoo Foods (Pty) Ltd', $party->name);
    }

    public function test_a_full_party_is_stored_tidied(): void
    {
        $this->inPersonal($this->me)->post(route('parties.store'), $this->valid([
            'type' => 'both',
            'legal_name' => ' Karoo Foods Holdings ',
            'email' => ' accounts@karoo.example ',
            'phone' => '021 555 0101',
            'tax_number' => '4123 456 789',
            'registration_number' => '2019/123456/07',
            'currency' => 'usd',
            'payment_terms_days' => '30',
            'credit_limit' => '25000.50',
            'external_ref' => 'CUST-9',
            'notes' => 'Pays on the 25th.',
            'billing_address' => ['line1' => '1 Long Street', 'city' => 'Cape Town', 'postal_code' => '8001', 'country' => 'South Africa', 'line2' => ''],
            'shipping_address' => ['line1' => '', 'city' => ''],
        ]))->assertSessionHasNoErrors();

        $party = Party::query()->firstOrFail();

        $this->assertSame('both', $party->type);
        $this->assertSame('Karoo Foods Holdings', $party->legal_name);
        $this->assertSame('accounts@karoo.example', $party->email);
        $this->assertSame('4123456789', $party->tax_number, 'spaces are not part of a VAT number');
        $this->assertSame('USD', $party->currency);
        $this->assertSame(30, $party->payment_terms_days);
        $this->assertSame(2_500_050, $party->credit_limit_cents);
        $this->assertSame(['line1' => '1 Long Street', 'city' => 'Cape Town', 'postal_code' => '8001', 'country' => 'South Africa'], $party->billing_address);
        $this->assertNull($party->shipping_address, 'an address with nothing in it is not stored');
        $this->assertTrue($party->isCustomer() && $party->isSupplier());
    }

    public function test_validation(): void
    {
        $this->inPersonal($this->me);

        $bad = [
            'no name' => [['name' => ''], 'name'],
            'no type' => [['type' => ''], 'type'],
            'unknown type' => [['type' => 'friend'], 'type'],
            'bad email' => [['email' => 'not-an-email'], 'email'],
            'unknown currency' => [['currency' => 'XYZ'], 'currency'],
            'lower-case garbage currency' => [['currency' => 'rand'], 'currency'],
            'negative terms' => [['payment_terms_days' => '-1'], 'payment_terms_days'],
            'terms too long' => [['payment_terms_days' => '3651'], 'payment_terms_days'],
            'fractional terms' => [['payment_terms_days' => '30.5'], 'payment_terms_days'],
            'three decimals' => [['credit_limit' => '10.123'], 'credit_limit'],
            'negative limit' => [['credit_limit' => '-5'], 'credit_limit'],
            'exponent limit' => [['credit_limit' => '1e3'], 'credit_limit'],
            'tax number with symbols' => [['tax_number' => '41<script>'], 'tax_number'],
            'name too long' => [['name' => str_repeat('a', 256)], 'name'],
            'notes too long' => [['notes' => str_repeat('a', 5001)], 'notes'],
            'address part too long' => [['billing_address' => ['line1' => str_repeat('a', 121)]], 'billing_address.line1'],
            'address as text' => [['billing_address' => 'somewhere'], 'billing_address'],
        ];

        foreach ($bad as $case => [$override, $field]) {
            $this->post(route('parties.store'), $this->valid($override))->assertSessionHasErrors($field);
            $this->assertSame(0, Party::query()->count(), $case);
        }
    }

    public function test_zero_is_a_valid_credit_limit_and_blank_means_none(): void
    {
        $this->inPersonal($this->me);

        $this->post(route('parties.store'), $this->valid(['name' => 'A', 'credit_limit' => '0']))->assertSessionHasNoErrors();
        $this->post(route('parties.store'), $this->valid(['name' => 'B', 'credit_limit' => '']))->assertSessionHasNoErrors();

        $this->assertSame(0, Party::query()->where('name', 'A')->value('credit_limit_cents'));
        $this->assertNull(Party::query()->where('name', 'B')->value('credit_limit_cents'));
    }

    public function test_the_owner_and_status_cannot_be_set_from_the_form(): void
    {
        $other = $this->user();
        $company = $this->company($other);

        $this->inPersonal($this->me)->post(route('parties.store'), $this->valid(['user_id' => $other->id, 'team_id' => $company->id, 'status' => 'archived', 'id' => 999]));

        $party = Party::query()->firstOrFail();
        $this->assertSame($this->me->id, $party->user_id);
        $this->assertNull($party->team_id);
        $this->assertSame('active', $party->status);
        $this->assertNotSame(999, $party->id);
    }

    public function test_a_tax_number_cannot_be_used_twice_in_a_ledger(): void
    {
        $this->inPersonal($this->me);
        $this->post(route('parties.store'), $this->valid(['name' => 'First', 'tax_number' => '4123456789']))->assertSessionHasNoErrors();

        $this->post(route('parties.store'), $this->valid(['name' => 'Second', 'tax_number' => '4123 456 789']))->assertSessionHasErrors('tax_number');
        $this->post(route('parties.store'), $this->valid(['name' => 'Third', 'external_ref' => 'R1']))->assertSessionHasNoErrors();
        $this->post(route('parties.store'), $this->valid(['name' => 'Fourth', 'external_ref' => 'R1']))->assertSessionHasErrors('external_ref');

        $this->assertSame(['First', 'Third'], Party::query()->orderBy('id')->pluck('name')->all());

        // the same number in another person's ledger is another ledger's business
        $this->inPersonal($this->user())->post(route('parties.store'), $this->valid(['name' => 'Elsewhere', 'tax_number' => '4123456789']))->assertSessionHasNoErrors();
    }

    public function test_editing_a_party_keeps_its_own_tax_number(): void
    {
        $party = Party::factory()->create(['user_id' => $this->me->id, 'tax_number' => '4123456789']);

        $this->inPersonal($this->me)->put(route('parties.update', $party), $this->valid(['name' => 'Renamed', 'tax_number' => '4123456789']))->assertRedirect(route('parties.show', $party));

        $this->assertSame('Renamed', $party->fresh()->name);
    }

    public function test_show_and_edit_pages(): void
    {
        $party = Party::factory()->create(['user_id' => $this->me->id, 'name' => 'Karoo', 'email' => 'a@karoo.example', 'credit_limit_cents' => 2_500_050, 'currency' => 'ZAR', 'payment_terms_days' => 14, 'billing_address' => ['line1' => '1 Long Street']]);

        $this->inPersonal($this->me)->get(route('parties.show', $party))
            ->assertOk()->assertSee('Karoo')->assertSee('a@karoo.example')->assertSee('ZAR 25,000.50')->assertSee('14 days')->assertSee('1 Long Street')->assertSee('Archive');

        $this->get(route('parties.edit', $party))->assertOk()->assertSee('value="Karoo"', false)->assertSee('25000.50', false);
    }

    public function test_update_changes_the_party(): void
    {
        $party = Party::factory()->create(['user_id' => $this->me->id, 'name' => 'Old']);

        $this->inPersonal($this->me)->put(route('parties.update', $party), $this->valid(['name' => 'New', 'type' => 'supplier', 'payment_terms_days' => '45']))->assertSessionHasNoErrors();

        $party->refresh();
        $this->assertSame(['New', 'supplier', 45], [$party->name, $party->type, $party->payment_terms_days]);
    }

    public function test_archiving_hides_a_party_and_restoring_brings_it_back(): void
    {
        $party = Party::factory()->create(['user_id' => $this->me->id, 'name' => 'Karoo', 'tax_number' => '4123456789']);
        $this->inPersonal($this->me);

        $this->delete(route('parties.destroy', $party))->assertRedirect(route('parties.index'));

        $this->assertSame(0, Party::query()->count());
        $this->assertSame('archived', Party::withTrashed()->find($party->id)->status);
        $this->get(route('parties.index'))->assertDontSee('href="'.route('parties.show', $party).'"', false);
        $this->get(route('parties.index', ['archived' => 1]))->assertSee('href="'.route('parties.show', $party).'"', false);
        $this->get(route('parties.show', $party))->assertOk()->assertSee('Archived')->assertSee('Restore');

        $this->post(route('parties.restore', $party))->assertRedirect(route('parties.show', $party));

        $restored = Party::query()->find($party->id);
        $this->assertNotNull($restored);
        $this->assertSame('active', $restored->status);
    }

    public function test_restoring_into_a_tax_number_clash_is_refused_cleanly(): void
    {
        $old = Party::factory()->create(['user_id' => $this->me->id, 'name' => 'Old', 'tax_number' => '4123456789']);
        $this->inPersonal($this->me)->delete(route('parties.destroy', $old));
        Party::factory()->create(['user_id' => $this->me->id, 'name' => 'New', 'tax_number' => '4123456789']);

        $this->post(route('parties.restore', $old))->assertSessionHasErrors('party');

        $this->assertNotNull(Party::withTrashed()->find($old->id)->deleted_at);
        $this->assertSame('archived', Party::withTrashed()->find($old->id)->status);
    }

    // ── listing and search ────────────────────────────────────────────

    public function test_the_list_search_and_filters(): void
    {
        Party::factory()->create(['user_id' => $this->me->id, 'name' => 'Alpha Traders', 'type' => 'customer', 'email' => 'sales@alpha.example']);
        Party::factory()->create(['user_id' => $this->me->id, 'name' => 'Beta Supplies', 'type' => 'supplier', 'tax_number' => '4999999999']);
        Party::factory()->create(['user_id' => $this->me->id, 'name' => 'Gamma Both', 'type' => 'both', 'legal_name' => 'Gamma Holdings']);
        $this->inPersonal($this->me);

        $this->get(route('parties.index'))->assertOk()->assertSeeInOrder(['Alpha Traders', 'Beta Supplies', 'Gamma Both']);
        $this->get(route('parties.index', ['q' => 'alpha']))->assertSee('Alpha Traders')->assertDontSee('Beta Supplies');
        $this->get(route('parties.index', ['q' => 'sales@alpha']))->assertSee('Alpha Traders');
        $this->get(route('parties.index', ['q' => '4999']))->assertSee('Beta Supplies')->assertDontSee('Alpha Traders');
        $this->get(route('parties.index', ['q' => 'holdings']))->assertSee('Gamma Both');
        $this->get(route('parties.index', ['type' => 'customer']))->assertSee('Alpha Traders')->assertSee('Gamma Both')->assertDontSee('Beta Supplies');
        $this->get(route('parties.index', ['type' => 'supplier']))->assertSee('Beta Supplies')->assertSee('Gamma Both')->assertDontSee('Alpha Traders');
        $this->get(route('parties.index', ['q' => 'nothing like this']))->assertSee('Nothing matches');
    }

    public function test_search_treats_percent_and_underscore_literally(): void
    {
        Party::factory()->create(['user_id' => $this->me->id, 'name' => '100% Pure']);
        Party::factory()->create(['user_id' => $this->me->id, 'name' => 'Plain Co']);
        Party::factory()->create(['user_id' => $this->me->id, 'name' => 'A_B Traders']);
        Party::factory()->create(['user_id' => $this->me->id, 'name' => 'AxB Traders']);
        $this->inPersonal($this->me);

        $this->get(route('parties.index', ['q' => '%']))->assertSee('100% Pure')->assertDontSee('Plain Co');
        $this->get(route('parties.index', ['q' => 'A_B']))->assertSee('A_B Traders')->assertDontSee('AxB Traders');
    }

    public function test_the_list_is_paginated(): void
    {
        Party::factory()->count(30)->create(['user_id' => $this->me->id]);

        $this->inPersonal($this->me)->get(route('parties.index'))->assertOk()->assertSee('page=2', false);
        $this->get(route('parties.index', ['page' => 2]))->assertOk();
    }

    public function test_an_empty_ledger_says_so_and_offers_the_next_step(): void
    {
        $this->inPersonal($this->me)->get(route('parties.index'))->assertOk()->assertSee('No customers or suppliers yet')->assertSee('Add the first one');
    }

    // ── roles ─────────────────────────────────────────────────────────

    public function test_roles_in_a_company_ledger(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $admin = $this->memberOf($company, 'admin');
        $editor = $this->memberOf($company, 'editor');
        $viewer = $this->memberOf($company, 'viewer');
        $unknown = $this->memberOf($company, 'mystery');
        $party = Party::factory()->forCompany($company)->create(['name' => 'Company customer']);

        foreach ([$owner, $admin, $editor] as $writer) {
            $this->inCompany($writer, $company)->get(route('parties.create'))->assertOk();
            $this->put(route('parties.update', $party), $this->valid(['name' => 'By '.$writer->id]))->assertRedirect();
            $this->assertSame('By '.$writer->id, $party->fresh()->name);
        }

        foreach ([$viewer, $unknown] as $reader) {
            $this->inCompany($reader, $company)->get(route('parties.index'))->assertOk();
            $this->get(route('parties.show', $party))->assertOk()->assertDontSee('Archive')->assertDontSee('Edit details')->assertDontSee('Upload');

            $this->get(route('parties.create'))->assertForbidden();
            $this->post(route('parties.store'), $this->valid(['name' => 'Nope']))->assertForbidden();
            $this->get(route('parties.edit', $party))->assertForbidden();
            $this->put(route('parties.update', $party), $this->valid(['name' => 'Nope']))->assertForbidden();
            $this->delete(route('parties.destroy', $party))->assertForbidden();
            $this->post(route('parties.restore', $party))->assertForbidden();
            $this->post(route('parties.attachments.store', $party), ['file' => $this->pdf()])->assertForbidden();
        }

        $this->assertSame(1, Party::query()->forContext(LedgerContext::company($company))->count());
        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $party = Party::factory()->create(['user_id' => $this->me->id]);

        foreach ([route('parties.index'), route('parties.create'), route('parties.show', $party), route('parties.edit', $party)] as $url) {
            $this->get($url)->assertRedirect();
        }
        $this->post(route('parties.store'), $this->valid())->assertRedirect();
        $this->delete(route('parties.destroy', $party))->assertRedirect();
    }

    // ── isolation between ledgers, on every endpoint ──────────────────

    public function test_every_endpoint_treats_another_ledgers_party_as_missing_and_changes_nothing(): void
    {
        $stranger = $this->user();
        $theirCompany = $this->company($stranger);
        $myCompany = $this->company($this->me);

        $foreign = [
            'their personal' => Party::factory()->create(['user_id' => $stranger->id, 'name' => 'Foreign personal']),
            'their company' => Party::factory()->forCompany($theirCompany)->create(['name' => 'Foreign company']),
        ];
        $mine = [
            'my personal' => Party::factory()->create(['user_id' => $this->me->id, 'name' => 'My personal']),
            'my company' => Party::factory()->forCompany($myCompany)->create(['name' => 'My company']),
        ];
        // a file on a foreign party, with a valid signed URL
        $url = [];
        $attachmentIds = [];
        foreach ($foreign as $label => $party) {
            $attachment = app(Attachments::class)->store($this->pdf($label), $party, null, context: $party->ledgerContext());
            $url[$label] = app(Attachments::class)->url($attachment);
            $attachmentIds[$label] = $attachment->id;
        }

        $before = md5(json_encode([DB::table('parties')->orderBy('id')->get(), DB::table('attachments')->orderBy('id')->get(), DB::table('finance_audit_log')->count()]));

        $callers = ['personal' => fn () => $this->inPersonal($this->me), 'company' => fn () => $this->inCompany($this->me, $myCompany)];

        foreach ($callers as $callerLabel => $enter) {
            foreach ($foreign as $label => $party) {
                $enter();
                $this->get(route('parties.show', $party))->assertNotFound();
                $this->get(route('parties.edit', $party))->assertNotFound();
                $this->put(route('parties.update', $party), $this->valid(['name' => 'Hijacked']))->assertNotFound();
                $this->delete(route('parties.destroy', $party))->assertNotFound();
                $this->post(route('parties.restore', $party))->assertNotFound();
                $this->post(route('parties.attachments.store', $party), ['file' => $this->pdf('attack')])->assertNotFound();
                $this->get($url[$label])->assertNotFound();
                $this->get(route('attachments.show', ['attachment' => $attachmentIds[$label]]))->assertNotFound();   // not signed, and not in this ledger either: the ledger answers first
            }
        }

        $this->assertSame($before, md5(json_encode([DB::table('parties')->orderBy('id')->get(), DB::table('attachments')->orderBy('id')->get(), DB::table('finance_audit_log')->count()])));

        // and the other ledger's name never shows on a list
        foreach ($callers as $enter) {
            $enter();
            $this->get(route('parties.index'))->assertOk()->assertDontSee('Foreign personal')->assertDontSee('Foreign company');
            $this->get(route('parties.index', ['q' => 'Foreign']))->assertDontSee('Foreign personal')->assertDontSee('Foreign company');
            $this->get(route('parties.index', ['archived' => 1]))->assertDontSee('Foreign');
        }

        // my own are reachable from the right ledger, not from the wrong one
        $this->inPersonal($this->me)->get(route('parties.show', $mine['my personal']))->assertOk();
        $this->get(route('parties.show', $mine['my company']))->assertNotFound();
        $this->inCompany($this->me, $myCompany)->get(route('parties.show', $mine['my company']))->assertOk();
        $this->get(route('parties.show', $mine['my personal']))->assertNotFound();
    }

    public function test_a_session_claiming_a_company_the_person_is_not_in_reaches_nothing(): void
    {
        $stranger = $this->user();
        $theirCompany = $this->company($stranger);
        $party = Party::factory()->forCompany($theirCompany)->create(['name' => 'Their customer']);

        $this->inCompany($this->me, $theirCompany);

        $this->get(route('parties.show', $party))->assertNotFound();
        $this->get(route('parties.index'))->assertDontSee('Their customer');
        $this->post(route('parties.store'), $this->valid(['name' => 'Smuggled']));
        $this->assertSame(0, Party::query()->forContext(LedgerContext::company($theirCompany))->where('name', 'Smuggled')->count());
    }

    // ── audit ─────────────────────────────────────────────────────────

    public function test_party_changes_are_written_to_the_audit_log_without_the_free_text(): void
    {
        $this->inPersonal($this->me)->post(route('parties.store'), $this->valid(['name' => 'Karoo', 'email' => 'secret@karoo.example', 'tax_number' => '4123456789', 'notes' => 'private note', 'credit_limit' => '500.00']));
        $party = Party::query()->firstOrFail();
        $this->put(route('parties.update', $party), $this->valid(['name' => 'Karoo Renamed', 'email' => 'other@karoo.example', 'tax_number' => '4123456789', 'notes' => 'changed note', 'credit_limit' => '750.00']));
        $this->delete(route('parties.destroy', $party));

        $rows = FinanceAuditLog::query()->where('subject_type', 'party')->orderBy('id')->get();

        $this->assertSame(['created', 'updated', 'updated', 'deleted'], $rows->pluck('action')->all(), 'created, the edit, the status flip to archived, then the archive');
        $this->assertSame($this->me->id, $rows[0]->actor_user_id);
        $this->assertSame('Karoo', $rows[0]->changes['name']['after']);
        $this->assertSame(['before' => 50_000, 'after' => 75_000], $rows[1]->changes['credit_limit_cents']);
        $this->assertSame(['changed' => true], $rows[1]->changes['email']);

        $everything = json_encode($rows->map->getAttributes()->all());
        foreach (['secret@karoo.example', 'other@karoo.example', 'private note', 'changed note', '4123456789'] as $leak) {
            $this->assertStringNotContainsString($leak, $everything, 'free text and identifiers are logged as "changed", never in full');
        }
    }

    // ── documents on a party ──────────────────────────────────────────

    public function test_a_document_can_be_uploaded_listed_and_opened(): void
    {
        $party = Party::factory()->create(['user_id' => $this->me->id, 'name' => 'Karoo']);
        $this->inPersonal($this->me);

        $this->post(route('parties.attachments.store', $party), ['file' => $this->pdf('one')])->assertRedirect(route('parties.show', $party));

        $attachment = Attachment::query()->firstOrFail();
        $this->assertSame($party->id, $attachment->attachable_id);
        $this->assertSame($this->me->id, $attachment->uploaded_by);

        $html = $this->get(route('parties.show', $party))->assertOk()->assertSee('contract.pdf')->getContent();
        preg_match('/href="([^"]*attachments\/'.$attachment->id.'[^"]*)"/', $html, $m);
        $this->assertNotEmpty($m, 'the page links to the signed URL');
        $this->get(html_entity_decode($m[1]))->assertOk();
    }

    public function test_upload_errors_are_shown_not_thrown(): void
    {
        $party = Party::factory()->create(['user_id' => $this->me->id]);
        $other = Party::factory()->create(['user_id' => $this->me->id]);
        $this->inPersonal($this->me);

        $this->post(route('parties.attachments.store', $party), ['file' => $this->pdf('same')])->assertSessionHasNoErrors();

        // the same file again on the same record: nothing new, and it says so
        $this->post(route('parties.attachments.store', $party), ['file' => $this->pdf('same', 'copy.pdf')])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'That document was already on file for this record, so nothing new was added.');
        $this->assertSame(1, Attachment::query()->count());

        // the same file on a different record is a duplicate, which is refused unless asked for
        $this->post(route('parties.attachments.store', $other), ['file' => $this->pdf('same', 'copy.pdf')])->assertSessionHasErrors('file');
        $this->assertSame(1, Attachment::query()->count());

        $this->post(route('parties.attachments.store', $party), ['file' => UploadedFile::fake()->createWithContent('page.html', '<html></html>')])->assertSessionHasErrors('file');
        $this->post(route('parties.attachments.store', $party), [])->assertSessionHasErrors('file');

        // a deliberate second copy
        $this->post(route('parties.attachments.store', $other), ['file' => $this->pdf('same', 'copy.pdf'), 'allow_duplicate' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(2, Attachment::query()->count());
    }
}
