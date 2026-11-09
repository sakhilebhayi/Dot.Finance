<?php

namespace Database\Seeders;

use App\Models\Shared\TaxRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The few rules the Tax module needs that the shared seeder does not hold. Same discipline: every row cites the
 * research document (docs/R44_TAX_RESEARCH.md) or says plainly that it is a Dot.Finance policy; uncertain items are
 * stored with confidence medium or low; append-only and idempotent. Calls TaxRulesSeeder first, so this is the one
 * command for the owner:
 *
 *   php artisan db:seed --class=TaxModuleRulesSeeder --force
 */
class TaxModuleRulesSeeder extends Seeder
{
    private const EARLIEST = '2024-03-01';

    public function run(): void
    {
        $this->call(TaxRulesSeeder::class);

        DB::transaction(function () {
            // Trusts: the research lists "trusts (non-special) 45%" among the Budget 2026 Tax Guide items for individuals (3.1).
            $this->rule('PIT', 'pit.trust_rate_bp', 'basis_points', 4500, '2026-03-01', '2027-02-28',
                'https://archive.treasury.gov.za/documents/national%20budget/2026/sars/Budget%202026%20Tax%20guide.pdf', 'high',
                'R44_TAX_RESEARCH.md 3.1 (Budget 2026 Tax Guide)', 'Trusts (other than special trusts) pay a flat 45 percent. Clock: the tax year 1 March 2026 to 28 February 2027.');

            // EMP501: the standing pattern behind the 2026 notice, for years the 2026 notice does not cover.
            $this->rule('EMP501', 'emp501.interim_window', 'json', ['period_start_md' => '03-01', 'period_end_md' => '08-31', 'window_start_md' => '09-21', 'window_end_md' => '10-31'], self::EARLIEST, null,
                'https://www.sars.gov.za/latest-news/employer-interim-declarations-emp501-21-september-to-october-2026/', 'low',
                'R44_TAX_RESEARCH.md 1, 4.1, 9 (item 3)', 'The interim reconciliation covers 1 March to 31 August and is submitted 21 September to 31 October. Only the 2026 window is published; this repeats its month-days for other years. Confirm when SARS publishes the notice.');

            // COIDA: Return of Earnings window (2026 window), a reminder only.
            $this->rule('COIDA', 'coida.roe_window', 'json', ['window_start_md' => '04-01', 'window_end_md' => '06-30'], self::EARLIEST, null,
                'https://acts.co.za/news/blog/2026/04/coida-2026-roe-submission-dates', 'medium',
                'R44_TAX_RESEARCH.md 4.3, 5', 'Return of Earnings (W.As.8) window 1 April to 30 June (the 2026 window). COIDA is not a SARS tax; this is a reminder only.');

            // ITR12 filing season: SARS announces the dates each year. Only the 2026 season is in the research (medium).
            $this->rule('INCOME_TAX', 'itr12.filing_season', 'json', ['tax_year_end' => '2026-02-28', 'non_provisional_due' => '2026-10-23', 'provisional_and_trusts_due' => '2027-01-22'], self::EARLIEST, null,
                'https://www.sars.gov.za/individuals/i-need-help-with-my-tax/calendar/', 'medium', 'R44_TAX_RESEARCH.md 3.2 (SARS media release)',
                'Filing season for the tax year ended 28 February 2026: non-provisional taxpayers by 23 October 2026, provisional taxpayers and trusts by 22 January 2027. SARS announces each season; other years are not known to Dot.Finance and no ITR12 deadline is created for them.', 'enacted', '2026');

            // Reminder offsets: Dot.Finance policy, not law.
            $this->rule('POLICY', 'tax.reminder.days_before', 'json', [14, 7, 3, 1, 0], self::EARLIEST, null,
                'internal:docs/R44_DECISIONS.md#D-800', 'high', 'R44_DECISIONS.md D-800', 'Days before a due date at which a reminder record is created. A Dot.Finance default, not a legal requirement.', 'policy');
        });
    }

    private function rule(string $type, string $key, string $valueType, mixed $value, string $from, ?string $to, string $url, string $confidence, ?string $ref, ?string $note, string $status = 'enacted', string $qualifier = ''): void
    {
        $exists = TaxRule::globalOnly()->where('jurisdiction', 'ZA')->where('rule_key', $key)->where('qualifier', $qualifier)->where('valid_from', $from)->exists();
        if ($exists) {
            return;
        }

        TaxRule::query()->create([
            'jurisdiction' => 'ZA', 'tax_type' => $type, 'rule_key' => $key, 'qualifier' => $qualifier, 'value_type' => $valueType, 'value' => $value,
            'valid_from' => $from, 'valid_to' => $to, 'legal_status' => $status, 'source_url' => $url, 'source_ref' => $ref,
            'accessed_at' => '2026-11-09', 'confidence' => $confidence, 'note' => $note,
        ]);
    }
}
