<?php

namespace Database\Seeders;

use App\Services\Tax\SouthAfricanHolidays;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * South African public holidays 2026 to 2028 for the tax calendar. Idempotent (a date already present is left alone).
 * Run by the tax migration itself, so the table exists after `migrate`; run again any time:
 *   php artisan db:seed --class=TaxHolidaysSeeder --force
 *
 * UNVERIFIED by design: the rows are derived from the Public Holidays Act's rules (see SouthAfricanHolidays) and are
 * stored with verified = false. A one-off proclaimed holiday is not in the list.
 */
class TaxHolidaysSeeder extends Seeder
{
    public const FIRST_YEAR = 2026;

    public const LAST_YEAR = 2028;

    public function run(): void
    {
        self::seed();
    }

    public static function seed(): int
    {
        $created = 0;
        $now = now();

        foreach (range(self::FIRST_YEAR, self::LAST_YEAR) as $year) {
            foreach (SouthAfricanHolidays::forYear($year) as $holiday) {
                $exists = DB::table('tax_holidays')->where('jurisdiction', 'ZA')->where('holiday_date', $holiday['date'])->exists();
                if ($exists) {
                    continue;
                }

                DB::table('tax_holidays')->insert([
                    'jurisdiction' => 'ZA',
                    'holiday_date' => $holiday['date'],
                    'name' => $holiday['name'],
                    'source' => 'derived',
                    'verified' => false,
                    'note' => 'Derived from the Public Holidays Act 36 of 1994 rules by Dot.Finance, not from a published list. Proclaimed one-off holidays are not included.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $created++;
            }
        }

        return $created;
    }
}
