<?php

namespace App\Console\Commands;

use Database\Seeders\TaxHolidaysSeeder;
use Database\Seeders\TaxModuleRulesSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Loads the reference data Dot.Finance cannot work without: the tax rule book (VAT, income tax, employment taxes, penalties,
 * deadlines, with their provenance), the seeded tax codes and rates, and the public-holiday list the tax calendar counts business days
 * with. Run by the deploy workflow right after `migrate`, so a release never goes live without them.
 *
 * Safe to run any number of times: every seeder here is append-only and skips what already exists, so a second run changes nothing
 * (the command says so by printing the same counts before and after). It FAILS (non-zero exit) when seeding throws or when the data
 * it should have produced is not there afterwards, so the deploy stops loudly instead of going live with an empty rule book.
 */
class SeedReferenceData extends Command
{
    protected $signature = 'finance:seed-reference';

    protected $description = 'Load (idempotently) the tax rules, tax codes, rates and public holidays the application needs';

    /** Tables the seeders write, and the least each must hold afterwards. */
    private const EXPECTED = ['tax_rules' => 1, 'tax_rates' => 1, 'tax_codes' => 1, 'tax_holidays' => 1];

    public function handle(): int
    {
        foreach (array_keys(self::EXPECTED) as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("The table {$table} does not exist. Run `php artisan migrate` first.");

                return self::FAILURE;
            }
        }

        $before = $this->counts();

        try {
            DB::transaction(function () {
                // TaxModuleRulesSeeder calls TaxRulesSeeder first; between them they hold every rule, rate and tax code.
                $this->seeder(TaxModuleRulesSeeder::class);
                $this->seeder(TaxHolidaysSeeder::class);
            });
        } catch (Throwable $e) {
            $this->error('Seeding the reference data failed, and nothing was changed: '.$e->getMessage());

            return self::FAILURE;
        }

        $after = $this->counts();

        foreach (self::EXPECTED as $table => $least) {
            if ($after[$table] < $least) {
                $this->error("After seeding, {$table} holds {$after[$table]} rows (expected at least {$least}). The reference data is incomplete.");

                return self::FAILURE;
            }
        }

        if (DB::table('tax_codes')->where('code', 'ZA_VAT_STD')->doesntExist()) {
            $this->error('After seeding, the standard-rated VAT code ZA_VAT_STD is missing. The reference data is incomplete.');

            return self::FAILURE;
        }

        foreach ($after as $table => $n) {
            $added = $n - $before[$table];
            $this->line(sprintf('%-14s %6d rows (%s)', $table, $n, $added === 0 ? 'already complete, nothing added' : "{$added} added"));
        }
        $this->info('Reference data is in place.');

        return self::SUCCESS;
    }

    /** @param class-string<Seeder> $class */
    private function seeder(string $class): void
    {
        $this->laravel->make($class)->setContainer($this->laravel)->setCommand($this)->__invoke();
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return array_map(fn (string $table) => DB::table($table)->count(), array_combine(array_keys(self::EXPECTED), array_keys(self::EXPECTED)));
    }
}
