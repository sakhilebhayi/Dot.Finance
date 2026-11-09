<?php

namespace Tests\Feature\Integration;

use App\Models\Shared\TaxRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** R-44 integration: `finance:seed-reference`, the step the deploy runs after migrate. */
class SeedReferenceCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'rules' => DB::table('tax_rules')->count(), 'rates' => DB::table('tax_rates')->count(),
            'codes' => DB::table('tax_codes')->count(), 'holidays' => DB::table('tax_holidays')->count(),
        ];
    }

    public function test_it_loads_the_rule_book_the_codes_and_the_holidays(): void
    {
        $this->artisan('finance:seed-reference')->assertExitCode(0)->expectsOutputToContain('Reference data is in place.');

        $counts = $this->counts();
        foreach ($counts as $name => $n) {
            $this->assertGreaterThan(0, $n, "no {$name} after seeding");
        }
        $this->assertTrue(DB::table('tax_codes')->where('code', 'ZA_VAT_STD')->exists());
        $this->assertTrue(TaxRule::query()->where('rule_key', 'vat.invoice.no_invoice_threshold_cents')->exists(), 'the rules purchases and sales read are there');
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->artisan('finance:seed-reference')->assertExitCode(0);
        $first = $this->counts();
        $fingerprint = DB::table('tax_rules')->orderBy('id')->get(['rule_key', 'valid_from', 'value'])->toJson();

        $this->artisan('finance:seed-reference')->assertExitCode(0)->expectsOutputToContain('already complete, nothing added');

        $this->assertSame($first, $this->counts());
        $this->assertSame($fingerprint, DB::table('tax_rules')->orderBy('id')->get(['rule_key', 'valid_from', 'value'])->toJson());
    }

    public function test_it_puts_back_what_is_missing_and_leaves_the_rest(): void
    {
        $this->artisan('finance:seed-reference')->assertExitCode(0);
        $full = $this->counts();

        DB::table('tax_holidays')->orderBy('id')->limit(3)->delete();
        $this->assertSame($full['holidays'] - 3, $this->counts()['holidays']);

        $this->artisan('finance:seed-reference')->assertExitCode(0);
        $this->assertSame($full, $this->counts());
    }

    public function test_it_fails_loudly_when_it_cannot_seed(): void
    {
        Schema::drop('tax_holidays');

        $this->artisan('finance:seed-reference')->assertExitCode(1)->expectsOutputToContain('tax_holidays does not exist');
    }
}
