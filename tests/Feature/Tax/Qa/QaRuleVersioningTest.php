<?php

namespace Tests\Feature\Tax\Qa;

use App\Models\Shared\TaxCode;
use App\Models\Shared\TaxRate;
use App\Models\Shared\TaxRule;
use App\Services\Shared\TaxRuleBook;
use App\Support\Shared\Exceptions\NoValidTaxRule;
use App\Support\Shared\Exceptions\VersionedRowViolation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsSharedFixtures;
use Tests\TestCase;

/**
 * QA: when a rule changes, the new value is a new row with a later valid_from and the old row is preserved and still answers for its
 * dates, on every route (model, query builder, raw SQL). Dates before, on and after the change resolve to the right version.
 */
class QaRuleVersioningTest extends TestCase
{
    use BuildsSharedFixtures;
    use RefreshDatabase;

    private function book(): TaxRuleBook
    {
        return app(TaxRuleBook::class)->forContext(null);
    }

    public function test_a_change_is_appended_the_old_version_is_kept_and_each_date_gets_its_own(): void
    {
        $this->seedZa();
        $old = TaxRule::globalOnly()->where('jurisdiction', 'ZA')->where('rule_key', 'uif.ceiling_monthly_cents')->firstOrFail();
        $this->assertNull($old->valid_to);
        $this->assertSame(1_771_200, $this->book()->resolve('ZA', 'uif.ceiling_monthly_cents', '2026-11-09')->cents());

        // a made-up ceiling from 1 June 2027: close the old version the day before and append the new one
        $old->valid_to = '2027-05-31';
        $old->save();
        TaxRule::query()->create([
            'jurisdiction' => 'ZA', 'tax_type' => 'UIF', 'rule_key' => 'uif.ceiling_monthly_cents', 'qualifier' => '', 'value_type' => 'money_cents', 'value' => 2_000_000,
            'valid_from' => '2027-06-01', 'legal_status' => 'enacted', 'source_url' => 'https://example.test/qa', 'accessed_at' => '2026-11-09', 'confidence' => 'low',
        ]);

        $at = fn (string $d) => $this->book()->resolve('ZA', 'uif.ceiling_monthly_cents', $d);
        $this->assertSame(1_771_200, $at('2021-06-01')->cents());
        $this->assertSame(1_771_200, $at('2027-05-31')->cents(), 'the last day of the old version');
        $this->assertSame(2_000_000, $at('2027-06-01')->cents(), 'the first day of the new version');
        $this->assertSame(2_000_000, $at('2031-01-01')->cents());
        $this->assertNotSame($at('2027-05-31')->version(), $at('2027-06-01')->version(), 'a return names which version it used');
        $this->assertSame(2, TaxRule::globalOnly()->where('rule_key', 'uif.ceiling_monthly_cents')->count(), 'the old row is still there');
        $this->expectException(NoValidTaxRule::class);
        $at('2021-05-31'); // before the first version: refused, not guessed
    }

    public function test_overlapping_versions_and_in_place_edits_are_refused_by_the_model(): void
    {
        $this->seedZa();
        $rule = TaxRule::globalOnly()->where('rule_key', 'sdl.rate_bp')->firstOrFail();

        foreach (['value' => 200, 'valid_from' => '2020-01-01', 'confidence' => 'low', 'legal_status' => 'draft'] as $column => $value) {
            $fresh = $rule->fresh();
            $fresh->{$column} = $value;
            try {
                $fresh->save();
                $this->fail("{$column} was edited in place");
            } catch (VersionedRowViolation) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            TaxRule::query()->create([
                'jurisdiction' => 'ZA', 'tax_type' => 'SDL', 'rule_key' => 'sdl.rate_bp', 'qualifier' => '', 'value_type' => 'basis_points', 'value' => 150,
                'valid_from' => '2026-06-01', 'legal_status' => 'enacted', 'source_url' => 'https://example.test/qa', 'accessed_at' => '2026-11-09', 'confidence' => 'low',
            ]);
            $this->fail('an overlapping version was accepted');
        } catch (VersionedRowViolation) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(VersionedRowViolation::class);
        $rule->delete();
    }

    public function test_the_database_refuses_to_change_what_a_published_rule_rate_or_code_says(): void
    {
        $this->seedZa();
        $rule = TaxRule::globalOnly()->where('rule_key', 'vat.late_payment_penalty_bp')->firstOrFail();
        $rate = TaxRate::globalOnly()->where('rate_key', 'ZA_VAT_STD')->firstOrFail();
        $code = TaxCode::globalOnly()->where('code', 'ZA_VAT_DENIED_MOTOR_CAR')->firstOrFail();

        $attacks = [
            ['tax_rules', $rule->id, ['value' => json_encode(2000)]],
            ['tax_rules', $rule->id, ['valid_from' => '2000-01-01']],
            ['tax_rules', $rule->id, ['confidence' => 'low']],
            ['tax_rules', $rule->id, ['legal_status' => 'draft']],
            ['tax_rates', $rate->id, ['rate_bp' => 1600]],
            ['tax_rates', $rate->id, ['valid_from' => '2000-01-01']],
            ['tax_codes', $code->id, ['box_input' => '15']],
            ['tax_codes', $code->id, ['deductible_policy' => 'allowed']],
            ['tax_codes', $code->id, ['kind' => 'zero']],
        ];
        foreach ($attacks as [$table, $id, $set]) {
            try {
                DB::transaction(fn () => DB::table($table)->where('id', $id)->update($set));
                $this->fail("raw SQL changed {$table}: ".json_encode($set));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        // closing a version and the bookkeeping fields stay possible
        DB::table('tax_rules')->where('id', $rule->id)->update(['valid_to' => '2030-12-31', 'reviewed_by' => 'QA', 'note' => 'edited note']);
        DB::table('tax_codes')->where('id', $code->id)->update(['name' => 'Renamed', 'is_active' => false]);
        $this->assertSame('2030-12-31', substr((string) DB::table('tax_rules')->where('id', $rule->id)->value('valid_to'), 0, 10));
        $this->assertSame(1000, (int) json_decode((string) DB::table('tax_rules')->where('id', $rule->id)->value('value'), true), 'the number is unchanged');
    }
}
