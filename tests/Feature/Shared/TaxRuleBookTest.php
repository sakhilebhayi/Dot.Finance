<?php

namespace Tests\Feature\Shared;

use App\Models\Shared\TaxCode;
use App\Models\Shared\TaxRate;
use App\Models\Shared\TaxRule;
use App\Services\Shared\TaxRuleBook;
use App\Support\Ledger\LedgerContext;
use App\Support\Ledger\LedgerContextViolation;
use App\Support\Shared\Exceptions\NoValidTaxRule;
use App\Support\Shared\Exceptions\VersionedRowViolation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\Concerns\BuildsSharedFixtures;
use Tests\TestCase;

class TaxRuleBookTest extends TestCase
{
    use BuildsCompanyLedgers;
    use BuildsSharedFixtures;
    use RefreshDatabase;

    private function book(): TaxRuleBook
    {
        return app(TaxRuleBook::class);
    }

    public function test_it_resolves_the_version_in_force_on_the_date(): void
    {
        $this->rule(['rule_key' => 'sample.rate', 'value' => 1000, 'valid_to' => '2025-12-31']);
        $this->rule(['rule_key' => 'sample.rate', 'value' => 1200, 'valid_from' => '2026-01-01']);

        $this->assertSame(1000, $this->book()->resolve('XX', 'sample.rate', '2025-12-31')->basisPoints());
        $this->assertSame(1200, $this->book()->resolve('XX', 'sample.rate', '2026-01-01')->basisPoints());
        $this->assertSame(1200, $this->book()->resolve('XX', 'sample.rate', '2040-06-30')->basisPoints(), 'an open-ended version keeps applying');
    }

    public function test_it_refuses_a_date_before_the_first_version_and_says_why(): void
    {
        $this->rule(['rule_key' => 'sample.rate', 'value' => 1000, 'valid_from' => '2020-01-01']);

        try {
            $this->book()->resolve('XX', 'sample.rate', '2019-12-31');
            $this->fail('expected a refusal');
        } catch (NoValidTaxRule $e) {
            $this->assertStringContainsString('2019-12-31', $e->getMessage());
            $this->assertStringContainsString('2020-01-01', $e->getMessage());
            $this->assertStringContainsString('nothing was assumed', $e->getMessage());
        }
    }

    public function test_it_refuses_a_date_after_a_closed_version_and_a_gap_between_versions(): void
    {
        $this->rule(['rule_key' => 'sample.rate', 'value' => 1000, 'valid_to' => '2021-12-31']);
        $this->rule(['rule_key' => 'sample.rate', 'value' => 1100, 'valid_from' => '2022-02-01']);

        $this->expectException(NoValidTaxRule::class);
        $this->book()->resolve('XX', 'sample.rate', '2022-01-15');
    }

    public function test_it_refuses_a_rule_that_does_not_exist_at_all(): void
    {
        $this->expectException(NoValidTaxRule::class);
        $this->expectExceptionMessage('is on record');
        $this->book()->resolve('XX', 'no.such.rule', '2026-01-01');
    }

    public function test_a_different_jurisdiction_is_a_different_rule(): void
    {
        $this->rule(['rule_key' => 'sample.rate', 'value' => 1000]);

        $this->assertNull($this->book()->find('YY', 'sample.rate', '2026-01-01'));
        $this->assertNotNull($this->book()->find('XX', 'sample.rate', '2026-01-01'));
    }

    public function test_a_qualifier_selects_between_flavours_of_one_rule(): void
    {
        $this->rule(['rule_key' => 'sample.period', 'qualifier' => 'A', 'value_type' => 'integer', 'value' => 2]);
        $this->rule(['rule_key' => 'sample.period', 'qualifier' => 'C', 'value_type' => 'integer', 'value' => 1]);

        $this->assertSame(2, $this->book()->resolve('XX', 'sample.period', '2026-01-01', 'A')->integer());
        $this->assertSame(1, $this->book()->resolve('XX', 'sample.period', '2026-01-01', 'C')->integer());
        $this->assertNull($this->book()->find('XX', 'sample.period', '2026-01-01'), 'the unqualified rule does not exist');
    }

    public function test_proposed_draft_and_repealed_rules_are_never_resolved(): void
    {
        foreach (['proposed', 'draft', 'repealed'] as $i => $status) {
            $this->rule(['rule_key' => "sample.{$status}", 'value' => 1000, 'legal_status' => $status]);

            try {
                $this->book()->resolve('XX', "sample.{$status}", '2026-01-01');
                $this->fail("{$status} must not resolve");
            } catch (NoValidTaxRule $e) {
                $this->assertStringContainsString("legal status {$status}", $e->getMessage());
            }
        }
    }

    public function test_announced_and_policy_rules_do_resolve(): void
    {
        $this->rule(['rule_key' => 'sample.announced', 'value' => 100, 'legal_status' => 'announced']);
        $this->rule(['rule_key' => 'sample.policy', 'value' => 100, 'legal_status' => 'policy']);

        $this->assertSame('announced', $this->book()->resolve('XX', 'sample.announced', '2026-01-01')->legalStatus);
        $this->assertSame('policy', $this->book()->resolve('XX', 'sample.policy', '2026-01-01')->legalStatus);
    }

    public function test_the_answer_carries_its_provenance(): void
    {
        $this->rule([
            'rule_key' => 'sample.rate', 'value' => 1500, 'source_url' => 'https://example.test/a', 'source_ref' => 'Act s1',
            'accessed_at' => '2026-11-09', 'confidence' => 'medium', 'note' => 'check me',
        ]);

        $r = $this->book()->resolve('XX', 'sample.rate', '2026-01-01');

        $this->assertSame('https://example.test/a', $r->sourceUrl);
        $this->assertSame('Act s1', $r->sourceRef);
        $this->assertSame('2026-11-09', $r->accessedAt->toDateString());
        $this->assertSame('medium', $r->confidence);
        $this->assertSame('check me', $r->note);
        $this->assertFalse($r->isOverride);
        $this->assertStringStartsWith('tax_rules#', $r->version());
    }

    public function test_asking_for_the_wrong_value_type_is_an_error_not_a_guess(): void
    {
        $this->rule(['rule_key' => 'sample.rate', 'value' => 1500]);

        $this->expectException(\LogicException::class);
        $this->book()->resolve('XX', 'sample.rate', '2026-01-01')->cents();
    }

    // ── overrides and ledger isolation ────────────────────────────────

    public function test_a_ledgers_own_override_beats_the_global_rule_for_that_ledger_only(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        $this->rule(['rule_key' => 'sample.rate', 'value' => 1500]);
        $this->rule(['rule_key' => 'sample.rate', 'value' => 1400, 'user_id' => $alice->id, 'note' => 'practitioner override']);

        $forAlice = $this->book()->forContext(LedgerContext::personal($alice))->resolve('XX', 'sample.rate', '2026-01-01');
        $forBob = $this->book()->forContext(LedgerContext::personal($bob))->resolve('XX', 'sample.rate', '2026-01-01');
        $global = $this->book()->forContext(null)->resolve('XX', 'sample.rate', '2026-01-01');

        $this->assertSame(1400, $forAlice->basisPoints());
        $this->assertTrue($forAlice->isOverride);
        $this->assertSame(1500, $forBob->basisPoints(), "another ledger never sees Alice's override");
        $this->assertSame(1500, $global->basisPoints());
    }

    public function test_the_ambient_ledger_decides_when_none_is_named(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $this->rule(['rule_key' => 'sample.rate', 'value' => 1500]);
        $this->rule(['rule_key' => 'sample.rate', 'value' => 1300, 'team_id' => $company->id]);

        $this->inCompany($owner, $company);
        $this->assertSame(1300, app(TaxRuleBook::class)->resolve('XX', 'sample.rate', '2026-01-01')->basisPoints());

        $this->inPersonal($owner);
        $this->assertSame(1500, app(TaxRuleBook::class)->resolve('XX', 'sample.rate', '2026-01-01')->basisPoints());
    }

    public function test_the_model_scope_hides_another_ledgers_override_from_every_query(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        $this->rule(['rule_key' => 'sample.rate', 'value' => 1500]);
        $this->rule(['rule_key' => 'sample.rate', 'value' => 1400, 'user_id' => $alice->id]);

        $this->actingAs($bob->fresh());
        $this->assertSame([1500], TaxRule::query()->where('rule_key', 'sample.rate')->get()->map(fn ($r) => $r->value)->all());

        $this->actingAs($alice->fresh());
        $this->assertCount(2, TaxRule::query()->where('rule_key', 'sample.rate')->get());
    }

    // ── rates and codes ───────────────────────────────────────────────

    public function test_rates_resolve_by_date_and_refuse_outside_their_versions(): void
    {
        $this->testJurisdiction();

        $this->assertSame(1000, $this->book()->rate('XX', 'XX_STD', '2029-12-31')->rateBp);
        $this->assertSame(1200, $this->book()->rate('XX', 'XX_STD', '2030-01-01')->rateBp);

        $this->expectException(NoValidTaxRule::class);
        $this->book()->rate('XX', 'XX_STD', '2017-12-31');
    }

    public function test_tax_codes_resolve_and_an_override_replaces_the_global_code_in_pickers(): void
    {
        $owner = $this->user();
        $this->code(['code' => 'XX_STD', 'name' => 'Standard', 'rate_ref' => 'XX_STD']);
        $this->code(['code' => 'XX_STD', 'name' => 'Standard (our wording)', 'rate_ref' => 'XX_STD', 'user_id' => $owner->id]);
        $this->code(['code' => 'XX_ZERO', 'kind' => 'zero']);

        $book = $this->book()->forContext(LedgerContext::personal($owner));

        $this->assertSame('Standard (our wording)', $book->taxCode('XX', 'XX_STD', '2026-01-01')->name);
        $this->assertSame(['Standard (our wording)', 'XX_ZERO'], $book->taxCodesOn('XX', '2026-01-01')->pluck('name')->all());
        $this->assertSame(['Standard', 'XX_ZERO'], $this->book()->forContext(null)->taxCodesOn('XX', '2026-01-01')->pluck('name')->all());
    }

    public function test_an_unknown_or_expired_tax_code_is_refused(): void
    {
        $this->code(['code' => 'XX_OLD', 'valid_to' => '2020-12-31']);

        $this->assertNotNull($this->book()->taxCode('XX', 'XX_OLD', '2020-12-31'));

        $this->expectException(NoValidTaxRule::class);
        $this->book()->taxCode('XX', 'XX_OLD', '2021-01-01');
    }

    // ── append-only ───────────────────────────────────────────────────

    public function test_a_rule_value_can_never_be_edited(): void
    {
        $rule = $this->rule(['rule_key' => 'sample.rate', 'value' => 1500]);

        foreach (['value' => 1600, 'source_url' => 'https://elsewhere.test', 'confidence' => 'low', 'valid_from' => '2019-01-01', 'rule_key' => 'other'] as $column => $new) {
            try {
                $rule->{$column} = $column === 'value' ? $new : $new;
                $rule->save();
                $this->fail("{$column} must be immutable");
            } catch (VersionedRowViolation) {
                $rule->refresh();
            }
        }

        $this->assertSame(1500, $rule->fresh()->value);
    }

    public function test_a_rule_can_be_closed_but_not_reopened_or_extended(): void
    {
        $rule = $this->rule(['rule_key' => 'sample.rate', 'value' => 1500]);

        $rule->update(['valid_to' => '2026-12-31']);
        $this->assertSame('2026-12-31', $rule->fresh()->valid_to->toDateString());

        $rule->update(['valid_to' => '2026-06-30']);
        $this->assertSame('2026-06-30', $rule->fresh()->valid_to->toDateString());

        $this->expectException(VersionedRowViolation::class);
        $rule->update(['valid_to' => '2027-12-31']);
    }

    public function test_a_closed_rule_cannot_be_reopened(): void
    {
        $rule = $this->rule(['rule_key' => 'sample.rate', 'value' => 1500, 'valid_to' => '2026-12-31']);

        $this->expectException(VersionedRowViolation::class);
        $rule->update(['valid_to' => null]);
    }

    public function test_a_rule_is_never_deleted(): void
    {
        $rule = $this->rule(['rule_key' => 'sample.rate', 'value' => 1500]);

        $this->expectException(VersionedRowViolation::class);
        $rule->delete();
    }

    public function test_two_versions_cannot_cover_the_same_day(): void
    {
        $this->rule(['rule_key' => 'sample.rate', 'value' => 1500, 'valid_from' => '2024-01-01', 'valid_to' => '2025-12-31']);

        foreach ([['2025-12-31', null], ['2023-01-01', '2024-01-01'], ['2024-06-01', '2024-07-01'], ['2020-01-01', null]] as [$from, $to]) {
            try {
                $this->rule(['rule_key' => 'sample.rate', 'value' => 1600, 'valid_from' => $from, 'valid_to' => $to]);
                $this->fail("{$from} to ".($to ?? 'open').' overlaps and must be refused');
            } catch (VersionedRowViolation $e) {
                $this->assertStringContainsString('already covers', $e->getMessage());
            }
        }

        // adjacent is fine
        $this->rule(['rule_key' => 'sample.rate', 'value' => 1600, 'valid_from' => '2026-01-01']);
        $this->assertSame(2, TaxRule::query()->where('rule_key', 'sample.rate')->count());
    }

    public function test_closing_a_rule_cannot_make_it_overlap_its_neighbour(): void
    {
        // the second version starts first by date, then the first is closed too late
        $a = $this->rule(['rule_key' => 'sample.rate', 'value' => 1500, 'valid_from' => '2024-01-01', 'valid_to' => '2024-12-31']);
        $this->rule(['rule_key' => 'sample.rate', 'value' => 1600, 'valid_from' => '2025-01-01']);

        $this->assertTrue($a->fresh()->coversDate(CarbonImmutable::parse('2024-12-31')));
        $this->expectException(VersionedRowViolation::class);
        $a->update(['valid_to' => '2025-06-30']);
    }

    public function test_versions_of_different_owners_may_overlap_but_the_same_owner_may_not(): void
    {
        $alice = $this->user();
        $this->rule(['rule_key' => 'sample.rate', 'value' => 1500]);
        $this->rule(['rule_key' => 'sample.rate', 'value' => 1400, 'user_id' => $alice->id]);

        $this->expectException(VersionedRowViolation::class);
        $this->rule(['rule_key' => 'sample.rate', 'value' => 1300, 'user_id' => $alice->id, 'valid_from' => '2026-01-01']);
    }

    public function test_a_row_cannot_have_two_owners_or_change_owner(): void
    {
        $alice = $this->user();
        $bob = $this->user();

        try {
            $this->rule(['rule_key' => 'sample.rate', 'value' => 1500, 'user_id' => $alice->id, 'team_id' => 1]);
            $this->fail('two owners must be refused');
        } catch (LedgerContextViolation) {
            $this->assertTrue(true);
        }

        $rule = $this->rule(['rule_key' => 'sample.rate2', 'value' => 1500, 'user_id' => $alice->id]);
        $this->expectException(LedgerContextViolation::class);
        $rule->update(['user_id' => $bob->id]);
    }

    public function test_tax_rates_and_codes_are_append_only_too(): void
    {
        $rate = $this->rate(['rate_key' => 'XX_STD', 'rate_bp' => 1000]);
        $code = $this->code(['code' => 'XX_STD', 'rate_ref' => 'XX_STD']);

        $this->assertThrowsLogic(fn () => $rate->update(['rate_bp' => 1100]));
        $this->assertThrowsLogic(fn () => $rate->fresh()->delete());
        $this->assertThrowsLogic(fn () => $code->update(['rate_ref' => 'OTHER']));
        $this->assertThrowsLogic(fn () => $code->fresh()->update(['box_output' => 'Z']));
        $this->assertThrowsLogic(fn () => $code->fresh()->delete());
        $code = $code->fresh();

        // wording may be tidied
        $code->update(['name' => 'Standard rate', 'description' => 'Most goods', 'is_active' => false, 'sort_order' => 5]);
        $this->assertFalse($code->fresh()->is_active);
        $this->assertSame(TaxCode::class, $code::class);
        $this->assertSame(TaxRate::class, $rate::class);
    }

    private function assertThrowsLogic(callable $do): void
    {
        try {
            $do();
            $this->fail('expected a LogicException');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
    }
}
