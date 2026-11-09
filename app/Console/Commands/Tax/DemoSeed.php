<?php

namespace App\Console\Commands\Tax;

use App\Console\Commands\DemoSeed as CashDemoSeed;
use App\Models\Accounting\GlAccount;
use App\Models\Tax\TaxPayrollRun;
use App\Models\Team;
use App\Models\User;
use App\Services\Accounting\ChartTemplates;
use App\Services\Accounting\GlBackfill;
use App\Services\Accounting\GlSync;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Shared\TaxRuleBook;
use App\Services\Tax\DeadlineGenerator;
use App\Services\Tax\PayrollJournal;
use App\Services\Tax\PayrollRuns;
use App\Services\Tax\TaxDocuments;
use App\Services\Tax\TaxProfiles;
use App\Services\Tax\TaxReminder;
use App\Services\Tax\TaxReturnService;
use App\Services\Tax\TaxRules;
use App\Services\Tax\VatPeriods;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TaxHolidaysSeeder;
use Database\Seeders\TaxModuleRulesSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * LOCAL DEMO DATA ONLY. Adds a believable tax life to a second fictional company, "Fynbos Print Works", owned by the person `finance:demo-seed` creates:
 * the South African rules, a VAT-registered company profile, seven months of VAT-bearing journal entries (standard, zero-rated,
 * export, capital, entertainment, a credit note, and two deliberate problems for the review queue), payroll summaries, the
 * generated calendar, and one older VAT201 prepared and recorded as submitted (with a fictional SARS reference, labelled demo).
 *
 * Everything goes through the real services. It refuses to run outside a local or testing environment. Run finance:demo-seed first.
 */
#[Signature('finance:tax:demo-seed')]
#[Description('Local only: add tax data (profile, VAT entries, payroll, calendar, one filed return) to the demo ledger')]
class DemoSeed extends Command
{
    public const COMPANY = 'Fynbos Print Works (Pty) Ltd';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('finance:tax:demo-seed only runs in a local or testing environment, never in production or staging.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', CashDemoSeed::EMAIL)->first();
        if (! $user) {
            $this->error('Run php artisan finance:demo-seed first: it creates the demo person this data belongs to.');

            return self::FAILURE;
        }

        // A second fictional company with no cash accounts, so switching accounting on does not depend on the cash demo's
        // foreign-currency account (which has no exchange rates and would stop the backfill from verifying).
        $team = Team::query()->where('user_id', $user->id)->where('name', self::COMPANY)->first()
            ?? Team::forceCreate(['user_id' => $user->id, 'name' => self::COMPANY, 'personal_team' => false, 'infodot_team_uuid' => (string) Str::uuid()]);

        $this->call('db:seed', ['--class' => TaxModuleRulesSeeder::class, '--force' => true]);
        (new TaxHolidaysSeeder)->run();

        $ctx = LedgerContext::company($team);
        $today = CarbonImmutable::today();

        LedgerContext::run($ctx, function () use ($ctx, $user, $today) {
            if (! app(GlSync::class)->isEnabled($ctx)) {
                $backfill = app(GlBackfill::class);
                $backfill->prepare($ctx, 'ZAR', 3, ChartTemplates::COMPANY);
                $backfill->apply($ctx);
                $backfill->enable($ctx);
                app(GlSync::class)->flush();
                $this->info('Accounting switched on for the demo ledger.');
            }

            $profile = app(TaxProfiles::class)->save($ctx, [
                'jurisdiction' => 'ZA', 'legal_entity' => 'company', 'tax_regime' => 'standard', 'vat_registered' => true, 'vat_number' => '4010000000',
                'vat_category' => 'B', 'vat_basis' => 'invoice', 'vat_registered_on' => $today->subMonths(20)->startOfMonth()->toDateString(), 'vat_filing_channel' => 'efiling',
                'income_tax_ref' => '9000000000', 'paye_ref' => '7000000000', 'sdl_ref' => 'L7000000000', 'uif_ref' => 'U7000000000',
                'financial_year_end_month' => 2, 'provisional_taxpayer' => true, 'accounting_basis' => 'accrual', 'employs_staff' => true,
            ], $user);

            $this->postVatLife($ctx, $today);
            $this->payroll($ctx, $user, $today);

            $r = app(DeadlineGenerator::class)->generate($ctx, $profile, $today);
            app(TaxReminder::class)->refresh($ctx, $today);
            $this->info("Calendar: {$r['created']} deadlines created.");

            $this->fileOneReturn($ctx, $profile, $user, $today);
            app(TaxDocuments::class)->add($ctx, ['category' => 'registration', 'title' => 'VAT registration letter (demo, no file attached)', 'document_date' => $today->subMonths(20)->toDateString()], null, $user);
        });

        $this->info('Tax demo data added. Sign in as '.CashDemoSeed::EMAIL.', switch to '.self::COMPANY.' and open /tax.');

        return self::SUCCESS;
    }

    private function postVatLife(LedgerContext $ctx, CarbonImmutable $today): void
    {
        $book = app(TaxRuleBook::class)->forContext(null);
        $poster = app(JournalPoster::class);
        $code = fn (string $c, CarbonImmutable $on) => $book->taxCode('ZA', $c, $on)->id;
        $seq = 0;
        $post = function (CarbonImmutable $on, string $type, string $memo, array $lines) use ($poster, &$seq) {
            $poster->post(new PostingRequest($on, $type, 'taxdemo'.(++$seq), 1, $memo, $lines));
        };
        $gl = fn (string $name) => GlAccount::query()->where('name', $name)->firstOrFail()->id;
        mt_srand(9214);

        for ($m = 7; $m >= 0; $m--) {
            $month = $today->startOfMonth()->subMonths($m);
            $d = fn (int $day) => $month->addDays($day - 1)->min($today);

            foreach ([5 => 48_000_00, 13 => 31_500_00, 22 => 62_250_00] as $day => $net) {
                $net = intdiv($net + mt_rand(0, 900_00), 100) * 100;
                $vat = intdiv($net * 15 + 50, 100);
                $c = $code('ZA_VAT_STD', $d($day));
                $post($d($day), 'invoice', 'Wholesale coffee invoice', [new PostingLine('ar', debitCents: $net + $vat), new PostingLine('sales', creditCents: $net, taxCodeId: $c), new PostingLine('vat_output', creditCents: $vat, taxCodeId: $c)]);
            }
            $z = $code('ZA_VAT_ZERO_EXPORT', $d(18));
            $post($d(18), 'invoice', 'Export order, Namibia', [new PostingLine('ar', debitCents: 22_000_00), new PostingLine('sales', creditCents: 22_000_00, taxCodeId: $z)]);

            foreach ([['Rent', 18_000_00, 3], ['Software and subscriptions', 2_400_00, 9], ['Repairs and maintenance', 5_200_00, 16]] as [$acct, $net, $day]) {
                $vat = intdiv($net * 15 + 50, 100);
                $c = $code('ZA_VAT_STD', $d($day));
                $post($d($day), 'bill', $acct.' bill', [new PostingLine($gl($acct), debitCents: $net, taxCodeId: $c), new PostingLine('vat_input', debitCents: $vat, taxCodeId: $c), new PostingLine('ap', creditCents: $net + $vat)]);
            }
            $e = $code('ZA_VAT_DENIED_ENTERTAINMENT', $d(20));
            $post($d(20), 'bill', 'Staff year-end function (input VAT not claimable)', [new PostingLine($gl('Advertising and marketing'), debitCents: 3_450_00, taxCodeId: $e), new PostingLine('ap', creditCents: 3_450_00)]);

            if ($m === 4) {
                $cap = $code('ZA_VAT_CAPITAL', $d(11));
                $post($d(11), 'bill', 'Roaster purchase (capital)', [new PostingLine($gl('Equipment and vehicles (cost)'), debitCents: 95_000_00, taxCodeId: $cap), new PostingLine('vat_input', debitCents: 14_250_00, taxCodeId: $cap), new PostingLine('ap', creditCents: 109_250_00)]);
            }
            if ($m === 2) {
                $c = $code('ZA_VAT_STD', $d(25));
                $post($d(25), 'credit_note', 'Credit note: damaged goods', [new PostingLine('sales', debitCents: 4_000_00, taxCodeId: $c), new PostingLine('vat_output', debitCents: 600_00, taxCodeId: $c), new PostingLine('ar', creditCents: 4_600_00)]);
            }
        }

        // two deliberate problems in the latest completed VAT period, so the review queue has something real to show
        $on = $today->subDays(12);
        $c = $code('ZA_VAT_STD', $on);
        $post($on, 'invoice', 'DEMO PROBLEM: standard-rated invoice posted without its VAT line', [new PostingLine('ar', debitCents: 12_000_00), new PostingLine('sales', creditCents: 12_000_00, taxCodeId: $c)]);
        $post($on->addDay(), 'manual', 'DEMO PROBLEM: VAT output posted by hand with no tax code', [new PostingLine('vat_output', creditCents: 1_800_00), new PostingLine('suspense', debitCents: 1_800_00)]);
    }

    private function payroll(LedgerContext $ctx, User $user, CarbonImmutable $today): void
    {
        $runs = app(PayrollRuns::class);
        $journal = app(PayrollJournal::class);
        foreach ([4, 3, 2, 1] as $back) {
            $month = $today->startOfMonth()->subMonths($back);
            $gross = 86_400_00 + $back * 1_200_00;
            $paye = intdiv($gross * 17, 100);
            $uif = min(3, 3) * 17_712; // three employees at or over the ceiling: 3 x R177.12
            $run = $runs->capture($ctx, $month, [
                'gross_pay_cents' => $gross, 'paye_cents' => $paye, 'uif_employee_cents' => $uif, 'uif_employer_cents' => $uif,
                'sdl_cents' => intdiv($gross, 100), 'eti_claimed_cents' => 0, 'employee_count' => 3, 'notes' => 'Demo figures, entered by hand.',
            ], $user);
            if ($back >= 2) {
                $journal->post($ctx, TaxPayrollRun::query()->forContext($ctx)->find($run->id), $user);
            }
        }
    }

    private function fileOneReturn(LedgerContext $ctx, $profile, User $user, CarbonImmutable $today): void
    {
        $svc = app(TaxReturnService::class);
        $periods = app(VatPeriods::class);
        $rules = app(TaxRules::class)->session($ctx);
        $p = $periods->containing($rules, $today->subMonths(5), 'B', 2);
        if ($p['end']->gte($today)) {
            return;
        }

        $return = $svc->start($ctx, 'vat201', $p['start'], $p['end'], $periods->label($p, 'B'), $profile, $user);
        try {
            $return = $svc->prepare($ctx, $return, $profile, $user);
            $return = $svc->recordSubmission($ctx, $return, 'DEMO-VAT-0001', $p['end']->addDays(20)->min($today), $user, null, 'Fictional reference for the demo ledger.');
            $this->info('One earlier VAT201 prepared and recorded as submitted (fictional reference DEMO-VAT-0001).');
        } catch (\Throwable $e) {
            $this->warn('The earlier VAT201 was left as a draft: '.$e->getMessage());
        }
    }
}
