<?php

namespace Tests\Feature\Integrations;

use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Accounting\LedgerSetting;
use App\Models\Integrations\EmallSaleLine;
use App\Models\Integrations\InboundEvent;
use App\Services\Integrations\SignedRequest;
use App\Support\Ledger\LedgerContext;
use Database\Seeders\TaxRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAccounting;
use Tests\Concerns\BuildsIntelFixtures;
use Tests\TestCase;

class EmallSalesTest extends TestCase
{
    use BuildsAccounting;
    use BuildsIntelFixtures;
    use RefreshDatabase;

    private const SECRET = 'emall-shared-secret';

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixTime();
        $this->seed(TaxRulesSeeder::class);
        config(['integrations.emall.secret' => self::SECRET]);
        $this->b = $this->business();
    }

    /** @return array<string, mixed> */
    private function line(array $override = []): array
    {
        return $override + [
            'order_item_id' => 'oi-1001', 'order_ref' => 'ORD-77', 'sold_on' => '2026-11-12', 'description' => 'Handwoven basket x2',
            'quantity' => 2, 'unit_price_cents' => 11500, 'discount_cents' => 0, 'total_cents' => 23000, 'tax_code' => 'ZA_VAT_STD', 'vat_cents' => 3000, 'fee_cents' => 2300,
        ];
    }

    private function delivery(array $lines, string $id = 'emall-0001', ?string $uuid = null): array
    {
        return ['delivery_id' => $id, 'company_uuid' => $uuid ?? $this->b['team']->infodot_team_uuid, 'currency' => 'ZAR', 'lines' => $lines];
    }

    private function deliver(array $payload, string $secret = self::SECRET, ?int $at = null, ?string $body = null)
    {
        $body ??= json_encode($payload);
        $at ??= time();

        return $this->call('POST', '/api/integrations/emall/sales', [], [], [], $this->transformHeadersToServerVars([
            'X-InfoDot-Timestamp' => (string) $at, 'X-InfoDot-Signature' => SignedRequest::sign($secret, $at, $body), 'CONTENT_TYPE' => 'application/json',
        ]), $body);
    }

    private function lines()
    {
        return LedgerContext::run($this->b['context'], fn () => EmallSaleLine::query()->orderBy('id')->get());
    }

    public function test_a_signed_delivery_for_a_known_company_is_staged_with_recomputed_amounts_and_nothing_is_journalled(): void
    {
        $this->enableAccounting($this->b['context']);
        $entries = JournalEntry::query()->forContext($this->b['context'])->count();

        $this->deliver($this->delivery([$this->line()]))->assertStatus(201)->assertJson(['status' => 'stored', 'received' => 1, 'staged' => 1, 'needs_attention' => 0, 'already_imported' => 0]);

        $l = $this->lines()->first();
        $this->assertSame('staged', $l->status);
        $this->assertSame(23000, $l->gross_cents);
        $this->assertSame(3000, $l->vat_cents);          // 23000 x 15 / 115 = 3000 exactly
        $this->assertSame(20000, $l->netCents());
        $this->assertSame(2300, $l->fee_cents);
        $this->assertSame($entries, JournalEntry::query()->forContext($this->b['context'])->count(), 'staging never posts');
    }

    public function test_the_same_delivery_again_is_a_replay_and_a_changed_one_is_a_conflict(): void
    {
        $payload = $this->delivery([$this->line()]);

        $this->deliver($payload)->assertStatus(201);
        $this->deliver($payload)->assertStatus(200)->assertJson(['status' => 'replayed']);
        $this->deliver($this->delivery([$this->line(['description' => 'Changed'])]))->assertStatus(409);

        $this->assertCount(1, $this->lines());
        $this->assertSame(1, LedgerContext::run($this->b['context'], fn () => InboundEvent::query()->count()));
    }

    public function test_an_order_item_is_imported_once_even_in_a_later_delivery_with_different_amounts(): void
    {
        $this->deliver($this->delivery([$this->line()], 'del-d1'))->assertStatus(201);
        $this->deliver($this->delivery([$this->line(['unit_price_cents' => 999999, 'total_cents' => 1999998, 'vat_cents' => 260869]), $this->line(['order_item_id' => 'oi-1002'])], 'del-d2'))
            ->assertStatus(201)->assertJson(['staged' => 1, 'already_imported' => 1]);

        $this->assertCount(2, $this->lines());
        $this->assertSame(23000, $this->lines()->firstWhere('order_item_id', 'oi-1001')->gross_cents, 'the first import stands');
    }

    public function test_amounts_are_recomputed_and_a_difference_puts_the_line_in_needs_attention(): void
    {
        $this->deliver($this->delivery([
            $this->line(['order_item_id' => 'bad-total', 'total_cents' => 99999]),                       // total does not match qty x price
            $this->line(['order_item_id' => 'bad-vat', 'vat_cents' => 5000]),                            // VAT does not match the code
            $this->line(['order_item_id' => 'no-code', 'tax_code' => null, 'vat_cents' => 3000]),        // VAT with nothing to verify it by
            $this->line(['order_item_id' => 'big-fee', 'fee_cents' => 99999]),
            $this->line(['order_item_id' => 'bad-code', 'tax_code' => 'NOPE_CODE']),
            $this->line(['order_item_id' => 'clean']),
        ]))->assertStatus(201)->assertJson(['staged' => 1, 'needs_attention' => 5]);

        $by = $this->lines()->keyBy('order_item_id');
        $this->assertSame(23000, $by['bad-total']->gross_cents, 'the recomputed total is used, not the one claimed');
        $this->assertSame(99999, $by['bad-total']->claimed['total_cents'], 'what was claimed is kept');
        $this->assertSame(3000, $by['bad-vat']->vat_cents);
        $this->assertStringContainsString('differs', implode(' ', $by['bad-vat']->problems));
        $this->assertStringContainsString('cannot be verified', implode(' ', $by['no-code']->problems));
        $this->assertStringContainsString('fee is larger', implode(' ', $by['big-fee']->problems));
        $this->assertStringContainsString('could not be worked out', implode(' ', $by['bad-code']->problems));
        $this->assertSame('staged', $by['clean']->status);
    }

    public function test_a_zero_rated_line_has_no_vat_to_post(): void
    {
        $this->deliver($this->delivery([$this->line(['tax_code' => 'ZA_VAT_ZERO', 'vat_cents' => 0])]))->assertStatus(201)->assertJson(['staged' => 1]);

        $this->assertSame(0, $this->lines()->first()->vat_cents);
    }

    public function test_the_signature_the_company_and_the_shape_are_all_checked(): void
    {
        $payload = $this->delivery([$this->line()]);

        $this->deliver($payload, 'wrong')->assertStatus(401);
        $this->deliver($payload, self::SECRET, time() - 4000)->assertStatus(401);
        $this->postJson('/api/integrations/emall/sales', $payload)->assertStatus(401);
        $this->deliver($this->delivery([$this->line()], 'del-x1', '22222222-2222-4222-8222-222222222222'))->assertStatus(404);
        $this->deliver($this->delivery([$this->line(['quantity' => 0])], 'del-x2'))->assertStatus(422);
        $this->deliver($this->delivery([$this->line(['sold_on' => '12/11/2026'])], 'del-x3'))->assertStatus(422);
        $this->deliver($this->delivery([$this->line(), $this->line()], 'del-x4'))->assertStatus(422);   // same order item twice
        $this->deliver(['delivery_id' => 'del-x5', 'company_uuid' => $this->b['team']->infodot_team_uuid, 'lines' => []])->assertStatus(422);
        $this->deliver([$this->line()])->assertStatus(422);                                          // a list, not an object

        $this->assertCount(0, $this->lines());
    }

    public function test_with_no_secret_configured_the_endpoint_is_off(): void
    {
        config(['integrations.emall.secret' => null]);

        $this->deliver($this->delivery([$this->line()]), '')->assertStatus(401);
        $this->deliver($this->delivery([$this->line()]), 'anything')->assertStatus(401);
    }

    public function test_a_company_only_ever_receives_its_own_lines_and_a_personal_ledger_cannot_be_targeted(): void
    {
        $other = $this->business('Other Ltd');
        $this->deliver($this->delivery([$this->line()], 'del-a1', $other['team']->infodot_team_uuid))->assertStatus(201);

        $this->assertCount(0, $this->lines());
        $this->assertSame(1, LedgerContext::run($other['context'], fn () => EmallSaleLine::query()->count()));

        // The same order item id may exist in two companies: the key is per ledger.
        $this->deliver($this->delivery([$this->line()], 'del-a2'))->assertStatus(201);
        $this->assertCount(1, $this->lines());

        $personal = $this->user();
        $this->deliver($this->delivery([$this->line()], 'del-a3', $personal->currentTeam->infodot_team_uuid ?? '33333333-3333-4333-8333-333333333333'))->assertStatus(404);
    }

    public function test_a_clean_line_is_posted_by_someone_who_may_post_journals_as_one_balanced_entry(): void
    {
        $this->enableAccounting($this->b['context']);
        $this->deliver($this->delivery([$this->line()]))->assertStatus(201);
        $line = $this->lines()->first();

        $this->inCompany($this->b['owner'], $this->b['team'])->get(route('intel.integrations.emall.index'))->assertOk()->assertSee('Handwoven basket x2')->assertSee('ZAR 230.00');
        $this->post(route('intel.integrations.emall.post', $line))->assertRedirect();

        $line->refresh();
        $this->assertSame('posted', $line->status);
        $entry = JournalEntry::query()->forContext($this->b['context'])->findOrFail($line->journal_entry_id);
        $this->assertSame('import_batch', $entry->source_type);
        $this->assertSame('emall:oi-1001', $entry->source_id);
        $this->assertSame('2026-11-12', $entry->entry_date->toDateString());

        $lines = JournalLine::query()->forContext($this->b['context'])->where('journal_entry_id', $entry->id)->with('glAccount')->get();
        $byKey = $lines->mapWithKeys(fn ($l) => [$l->glAccount->system_key => $l])->all();
        $this->assertSame(20700, $byKey['ar']->debit_cents);            // gross 23,000 less the 2,300 fee
        $this->assertSame(2300, $byKey['cost_of_sales']->debit_cents);
        $this->assertSame(20000, $byKey['sales']->credit_cents);
        $this->assertSame(3000, $byKey['vat_output']->credit_cents);
        $this->assertSame($lines->sum('debit_cents'), $lines->sum('credit_cents'));
        $this->assertNotNull($byKey['vat_output']->tax_code_id, 'marked as VAT-bearing for the return');

        // Posting again changes nothing.
        $this->post(route('intel.integrations.emall.post', $line))->assertRedirect();
        $this->assertSame(1, JournalEntry::query()->forContext($this->b['context'])->where('source_id', 'emall:oi-1001')->count());
        $this->assertTrue(\App\Models\FinanceAuditLog::query()->forContext($this->b['context'])->where('subject_type', 'emall_sale')->where('action', 'posted')->exists());
    }

    public function test_posting_is_refused_without_the_capability_without_accounting_for_a_problem_line_or_a_foreign_currency(): void
    {
        $this->deliver($this->delivery([$this->line(), $this->line(['order_item_id' => 'bad', 'total_cents' => 1]), $this->line(['order_item_id' => 'usd', 'currency' => 'USD'])]))->assertStatus(201);
        $by = $this->lines()->keyBy('order_item_id');
        $viewer = $this->memberOf($this->b['team'], 'viewer');
        $bookkeeper = $this->memberOf($this->b['team'], 'editor');

        $this->inCompany($viewer, $this->b['team'])->post(route('intel.integrations.emall.post', $by['oi-1001']))->assertForbidden();
        $this->inCompany($bookkeeper, $this->b['team'])->post(route('intel.integrations.emall.post', $by['oi-1001']))->assertForbidden();   // no journal capability

        $this->inCompany($this->b['owner'], $this->b['team'])->post(route('intel.integrations.emall.post', $by['oi-1001']))->assertSessionHasErrors('line');   // accounting is off
        $this->assertSame('staged', $by['oi-1001']->fresh()->status);

        $this->enableAccounting($this->b['context']);
        $this->post(route('intel.integrations.emall.post', $by['bad']))->assertSessionHasErrors('line');
        $this->post(route('intel.integrations.emall.post', $by['usd']))->assertSessionHasErrors('line');
        $this->assertSame(0, JournalEntry::query()->forContext($this->b['context'])->where('source_type', 'import_batch')->count());
    }

    public function test_a_closed_period_refuses_the_post_and_a_reject_is_final(): void
    {
        $this->deliver($this->delivery([$this->line()]))->assertStatus(201);
        $line = $this->lines()->first();
        $this->enableAccounting($this->b['context']);
        LedgerSetting::query()->forContext($this->b['context'])->first()->forceFill(['books_closed_through' => '2026-11-30'])->save();

        $this->inCompany($this->b['owner'], $this->b['team'])->post(route('intel.integrations.emall.post', $line))->assertSessionHasErrors('line');
        $this->assertSame('staged', $line->fresh()->status);

        $this->post(route('intel.integrations.emall.reject', $line))->assertRedirect();
        $this->assertSame('rejected', $line->fresh()->status);
        $this->post(route('intel.integrations.emall.post', $line))->assertSessionHasErrors('line');
    }

    public function test_the_fee_account_must_be_an_expense_account_of_this_ledger(): void
    {
        $this->enableAccounting($this->b['context']);
        $other = $this->business('Other Ltd');
        $this->enableAccounting($other['context']);
        $this->deliver($this->delivery([$this->line()]))->assertStatus(201);
        $line = $this->lines()->first();
        $foreign = \App\Models\Accounting\GlAccount::query()->forContext($other['context'])->where('class', 'expense')->first();
        $revenue = \App\Models\Accounting\GlAccount::query()->forContext($this->b['context'])->where('class', 'income')->first();

        $this->inCompany($this->b['owner'], $this->b['team']);
        $this->post(route('intel.integrations.emall.post', $line), ['fee_account_id' => $foreign->id])->assertSessionHasErrors('fee_account_id');
        $this->post(route('intel.integrations.emall.post', $line), ['fee_account_id' => $revenue->id])->assertSessionHasErrors('fee_account_id');
        $this->assertSame('staged', $line->fresh()->status);
    }
}
