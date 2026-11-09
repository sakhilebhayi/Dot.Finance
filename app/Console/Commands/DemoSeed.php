<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Accounting\JournalEntry;
use App\Models\Bank\BankReconciliationEvent;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Insight;
use App\Models\Intelligence\AssistantDraft;
use App\Models\Intelligence\AssistantMessage;
use App\Models\Intelligence\FinanceInsight;
use App\Models\Intelligence\FinanceNotification;
use App\Models\Intelligence\NotificationPreference;
use App\Models\RecurringRule;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Intelligence\FinanceAssistant;
use App\Services\Intelligence\InsightEngine;
use App\Services\Intelligence\NotificationRules;
use App\Services\TransferService;
use App\Support\Ledger\LedgerContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * LOCAL DEMO DATA ONLY. Builds one believable, entirely fictional South African business ledger ("Karoo Roasters",
 * a small coffee roastery in Cape Town) with about twelve months of entries, so screens can be looked at in a
 * realistic state. Everything goes through the real models and TransferService (so the audit log, the ledger scope and
 * the integer-cents rules all apply). Nothing here is a real person, company, bank or customer.
 *
 * It refuses to run anywhere but a local or testing environment: the demo user has a published password, which would be
 * a hole on a real host. It is deterministic: the same day gives the same ledger (a fixed random seed, dates counted back
 * from today). Running it again on an existing demo ledger stops unless --fresh is given, which clears the demo ledger's rows and rebuilds
 * them (the demo user and company stay: the append-only audit log points at them).
 */
#[Signature('finance:demo-seed {--fresh : Remove the existing demo ledger first} {--sales : Also add a sales history (customers, invoices, payments). Turns accounting on, so the ledger cannot be rebuilt in place afterwards}')]
#[Description('Local only: create a fictional South African business ledger with twelve months of entries for looking at the screens')]
class DemoSeed extends Command
{
    public const EMAIL = 'demo@finance.test';

    /** A published, local-only password for the fictional demo user. The command will not run outside local/testing. */
    public const PASSWORD = 'demo-ledger-local';

    public const COMPANY = 'Karoo Roasters (Pty) Ltd';

    public function handle(TransferService $transfers): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('finance:demo-seed only runs in a local or testing environment, never in production or staging.');

            return self::FAILURE;
        }

        $existing = User::query()->where('email', self::EMAIL)->first();
        if ($existing && ! $this->option('fresh')) {
            $this->error('The demo ledger already exists. Run with --fresh to rebuild it.');

            return self::FAILURE;
        }

        // Bank history (statements, matches, reconciliations) is append-only and locks the entries it cleared, so a ledger that has it
        // cannot be wiped and rebuilt in place.
        $demoCompany = $existing ? Team::query()->where('user_id', $existing->id)->where('name', self::COMPANY)->first() : null;
        if ($existing && collect([LedgerContext::personal($existing), $demoCompany ? LedgerContext::company($demoCompany) : null])->filter()->contains(fn (LedgerContext $c) => LedgerContext::run($c, fn () => BankReconciliationEvent::query()->exists()))) {
            $this->error('The demo ledger has bank reconciliation history, which is append-only and cannot be rebuilt in place. Run `php artisan migrate:fresh` on the local database, then finance:demo-seed again.');

            return self::FAILURE;
        }

        // Books are append-only (a database trigger refuses to delete a journal row), so a demo company that has them cannot be
        // wiped in place. Say so instead of failing halfway on a foreign key.
        if ($existing && $this->option('fresh')) {
            $demoCompany = Team::query()->where('user_id', $existing->id)->where('name', self::COMPANY)->first();
            if ($demoCompany && JournalEntry::query()->forContext(LedgerContext::company($demoCompany))->exists()) {
                $this->error('The demo company has books (accounting is on), and journal rows can never be deleted. To rebuild it, recreate the local database: php artisan migrate:fresh && php artisan finance:demo-seed --sales');

                return self::FAILURE;
            }
        }

        mt_srand(4401);

        DB::transaction(function () use ($existing, $transfers): void {
            // --fresh clears the demo ledger's rows and rebuilds them. The demo user and company are kept: the audit log is
            // append-only and points at them, so they can never be deleted (which is the point of an audit log).
            $user = $existing ?? User::forceCreate([
                'name' => 'Naledi Dlamini',
                'email' => self::EMAIL,
                'email_verified_at' => now(),
                'password' => Hash::make(self::PASSWORD),
            ]);

            $personalTeam = Team::query()->where('user_id', $user->id)->where('personal_team', true)->first()
                ?? Team::forceCreate(['user_id' => $user->id, 'name' => 'Naledi\'s Team', 'personal_team' => true]);
            $user->forceFill(['current_team_id' => $personalTeam->id])->save();

            $company = Team::query()->where('user_id', $user->id)->where('name', self::COMPANY)->first()
                ?? Team::forceCreate([
                    'user_id' => $user->id,
                    'name' => self::COMPANY,
                    'personal_team' => false,
                    'infodot_team_uuid' => (string) Str::uuid(),
                ]);

            $this->clear(LedgerContext::company($company));
            $this->clear(LedgerContext::personal($user));

            LedgerContext::run(LedgerContext::company($company), fn () => $this->company($company, $user, $transfers));
            LedgerContext::run(LedgerContext::personal($user), fn () => $this->personal($user));
            $this->intelligence(LedgerContext::company($company), $user);
        });

        $this->info('Demo ledger created: '.self::COMPANY.' (company) and a small personal ledger.');
        if ($this->option('sales')) {
            $this->call('finance:demo-seed-sales');   // R-44 Sales: customers, invoices, payments (turns accounting on for the demo company)
        }
        $this->line('Sign in locally as '.self::EMAIL.' (the password is the PASSWORD constant of this command), then choose the company in the ledger switcher.');

        return self::SUCCESS;
    }

    /** Clear one ledger's rows, inside that ledger's own context so the ledger scope confines every delete to it. */
    private function clear(LedgerContext $context): void
    {
        LedgerContext::run($context, function (): void {
            // Children before parents. Removed for good: this is demo data in a local database.
            foreach ([AssistantDraft::class, AssistantMessage::class, FinanceNotification::class, NotificationPreference::class, FinanceInsight::class, Transaction::class, RecurringRule::class, Budget::class, Category::class, Account::class, Insight::class] as $model) {
                $query = in_array(SoftDeletes::class, class_uses_recursive($model), true) ? $model::withTrashed() : $model::query();
                $query->forceDelete();
            }
        });
    }

    private function company(Team $company, User $user, TransferService $transfers): void
    {
        $today = now()->startOfDay();
        $start = $today->copy()->subMonths(12)->startOfMonth();
        $owner = ['user_id' => null, 'team_id' => $company->id];

        $account = fn (string $name, string $type, string $currency, int $opening, ?string $institution = null) => Account::create($owner + [
            'name' => $name, 'type' => $type, 'currency' => $currency, 'institution' => $institution,
            'opening_balance_cents' => $opening, 'opening_balance_date' => $start->toDateString(),
        ]);

        $cheque = $account('Business Cheque', 'checking', 'ZAR', 18_425_000, 'FNB Business');
        $reserve = $account('Savings Reserve', 'savings', 'ZAR', 12_000_000, 'FNB Business');
        $card = $account('Business Card', 'credit', 'ZAR', 0, 'FNB Business');
        $petty = $account('Petty Cash', 'cash', 'ZAR', 600_000);
        $usd = $account('Supplier USD', 'checking', 'USD', 320_000, 'Wise');

        $cat = fn (string $name, string $type, string $color) => Category::create($owner + ['name' => $name, 'type' => $type, 'color' => $color, 'icon' => null]);
        $c = [
            'wholesale' => $cat('Wholesale', 'income', '#2f5d4e'),
            'counter' => $cat('Roastery counter', 'income', '#4a6b8a'),
            'online' => $cat('Online shop', 'income', '#6b5b95'),
            'coffee' => $cat('Green coffee', 'expense', '#7a4b2a'),
            'packaging' => $cat('Packaging', 'expense', '#8a7a4a'),
            'rent' => $cat('Rent', 'expense', '#555b66'),
            'salaries' => $cat('Salaries and wages', 'expense', '#3e5a7a'),
            'utilities' => $cat('Water, electricity and utilities', 'expense', '#5e7a8a'),
            'marketing' => $cat('Advertising and marketing', 'expense', '#9a4b6a'),
            'software' => $cat('Software and subscriptions', 'expense', '#4b5a9a'),
            'fuel' => $cat('Fuel and deliveries', 'expense', '#8a5a3a'),
            'bank' => $cat('Bank charges', 'expense', '#666666'),
            'insurance' => $cat('Insurance', 'expense', '#4a7a6a'),
            'vat' => $cat('VAT paid to SARS', 'expense', '#444a55'),
            'equipment' => $cat('Equipment', 'expense', '#6a6a3a'),
        ];

        $tx = function (Account $acct, string $type, string $desc, int $cents, Carbon $date, ?Category $category = null, ?string $notes = null) use ($owner, $today): void {
            if ($date->gt($today)) {
                return;
            }
            Transaction::create($owner + [
                'account_id' => $acct->id, 'category_id' => $category?->id, 'description' => $desc,
                'amount_cents' => $cents, 'currency' => $acct->currency, 'type' => $type, 'date' => $date->toDateString(), 'notes' => $notes,
            ]);
        };
        $rand = fn (int $low, int $high) => $low + (mt_rand() % ($high - $low + 1));
        $round50 = fn (int $rand) => intdiv($rand, 5_000) * 5_000;

        // Trade customers who pay by EFT when they order. The customers the business invoices on credit (Gardens Pantry, Woodstock Deli, ...)
        // are created by the Sales demo and never appear here, so no name is a customer twice.
        $customers = ['Salt River Deli', 'Bo-Kaap Bakehouse', 'Observatory Co-working', 'Southern Suburbs Hotel'];
        $beans = ['Ethiopia Guji', 'Kenya AA', 'Colombia Huila', 'Brazil Cerrado', 'Rwanda Nyamasheke'];

        for ($m = 0; $m <= 12; $m++) {
            $first = $start->copy()->addMonths($m);
            $day = fn (int $d) => $first->copy()->addDays($d - 1);

            // Money in
            foreach ([4, 11, 18, 24] as $i => $d) {
                $tx($cheque, 'income', 'Wholesale: '.$customers[($m + $i) % count($customers)], $round50($rand(1_800_000, 4_600_000)), $day($d + ($m % 3)), $c['wholesale']);
            }
            foreach ([5, 12, 19, 26] as $d) {
                $tx($cheque, 'income', 'Roastery counter takings', $round50($rand(780_000, 1_420_000)), $day($d), $c['counter']);
            }
            foreach ([8, 22] as $d) {
                $tx($cheque, 'income', 'Online shop payout', $round50($rand(620_000, 1_540_000)), $day($d), $c['online']);
            }

            // Money out
            $tx($cheque, 'expense', 'Rent: Woodstock roastery unit', 1_850_000, $day(1), $c['rent']);
            $tx($cheque, 'expense', 'Salaries', 4_680_000, $day(25), $c['salaries']);
            $tx($cheque, 'expense', 'Accounting and point-of-sale subscription', 115_000, $day(3), $c['software']);
            $tx($cheque, 'expense', 'Business insurance premium', 238_000, $day(5), $c['insurance']);
            $tx($cheque, 'expense', 'Monthly bank fees', 48_500, $day(28), $c['bank']);
            $tx($cheque, 'expense', 'City of Cape Town: electricity and water', $round50($rand(310_000, 455_000)), $day(14), $c['utilities']);
            foreach ([6, 20] as $i => $d) {
                $tx($cheque, 'expense', 'Green coffee: '.$beans[($m * 2 + $i) % count($beans)], $round50($rand(2_150_000, 3_150_000)), $day($d), $c['coffee']);
            }
            $tx($cheque, 'expense', 'Bags, boxes and labels', $round50($rand(350_000, 620_000)), $day(16), $c['packaging']);
            foreach ([9, 17, 27] as $d) {
                $tx($card, 'expense', 'Fuel for deliveries', $round50($rand(90_000, 145_000)), $day($d), $c['fuel']);
            }
            $tx($card, 'expense', 'Social media advertising', $round50($rand(120_000, 450_000)), $day(13), $c['marketing']);
            $tx($petty, 'expense', 'Cleaning and sundries', $round50($rand(25_000, 60_000)), $day(21), $c['utilities']);

            if ($m % 2 === 1) {
                $tx($cheque, 'expense', 'VAT payment to SARS', $round50($rand(2_400_000, 3_800_000)), $day(25), $c['vat']);
            }
            if ($m === 4) {
                $tx($cheque, 'expense', 'Second-hand drum roaster service and spare parts', 3_850_000, $day(10), $c['equipment']);
            }

            // Moving money between own accounts: never income, never spending.
            if ($day(26)->lte($today)) {
                $transfers->create($user, $cheque, $reserve, 1_000_000, null, $day(26), 'Monthly transfer to savings reserve', null);
            }
            if ($m % 3 === 2 && $day(15)->lte($today)) {
                $transfers->create($user, $cheque, $usd, 1_850_000, 100_000, $day(15), 'Top up supplier USD account', 'Rate agreed by the bank on the day');
                $tx($usd, 'expense', 'Green coffee supplier invoice (USD)', 84_000, $day(18), $c['coffee']);
            }
            if ($m % 2 === 0 && $day(1)->lte($today)) {
                // Pay the credit card off from the cheque account.
                $transfers->create($user, $cheque, $card, 520_000, null, $day(2), 'Pay business card', null);
            }
        }

        // This month: a few entries still waiting for a category, which is what the state line points at.
        $thisMonth = $today->copy()->startOfMonth();
        $tx($cheque, 'expense', 'Courier: wholesale parcel', 38_500, $thisMonth->copy()->addDays(2));
        $tx($card, 'expense', 'Stationery and receipt rolls', 21_900, $thisMonth->copy()->addDays(3));
        $tx($cheque, 'income', 'EFT received, reference unclear', 640_000, $thisMonth->copy()->addDays(1));

        // Budgets for the current month. Marketing is deliberately over its limit.
        foreach ([['coffee', 5_500_000], ['packaging', 700_000], ['fuel', 400_000], ['utilities', 550_000], ['marketing', 300_000]] as [$key, $cents]) {
            Budget::create($owner + ['category_id' => $c[$key]->id, 'amount_cents' => $cents, 'currency' => 'ZAR', 'month' => $today->month, 'year' => $today->year]);
        }
        $tx($card, 'expense', 'Print advertising: market stall banner', 420_000, $thisMonth->copy()->addDays(1), $c['marketing']);

        // Repeating items: dates are set relative to today so the command centre shows what is coming and one that is late.
        $rule = fn (string $desc, string $type, int $cents, Account $acct, ?Category $category, string $cadence, Carbon $next) => RecurringRule::create($owner + [
            'account_id' => $acct->id, 'category_id' => $category?->id, 'description' => $desc, 'amount_cents' => $cents, 'currency' => 'ZAR', 'type' => $type,
            'recurrence_type' => $cadence, 'recurrence_interval' => 1, 'start_date' => $start->toDateString(), 'next_occurrence_date' => $next->toDateString(),
        ]);
        $rule('Rent: Woodstock roastery unit', 'expense', 1_850_000, $cheque, $c['rent'], 'monthly', $today->copy()->addDays(5));
        $rule('Accounting and point-of-sale subscription', 'expense', 115_000, $cheque, $c['software'], 'monthly', $today->copy()->addDays(8));
        $rule('Business insurance premium', 'expense', 238_000, $cheque, $c['insurance'], 'monthly', $today->copy()->addDays(11));
        $rule('Salaries', 'expense', 4_680_000, $cheque, $c['salaries'], 'monthly', $today->copy()->addDays(13));
        $rule('Standing wholesale order: Salt River Deli', 'income', 1_450_000, $cheque, $c['wholesale'], 'weekly', $today->copy()->addDays(2));
        $rule('Courier account debit order', 'expense', 62_000, $cheque, $c['fuel'], 'weekly', $today->copy()->subDays(3));
    }

    /**
     * R-44 intelligence on the demo company: two things the detectors should notice (an entry made twice, an unusual
     * round amount), then the real scan, three real questions put to the real assistant (one of them a draft, left
     * pending) and the real notification rules. Nothing is typed in by hand: every row is made by the services.
     */
    private function intelligence(LedgerContext $context, User $user): void
    {
        LedgerContext::run($context, function () use ($context, $user): void {
            $cheque = Account::query()->where('name', 'Business Cheque')->firstOrFail();
            $fuel = Category::query()->where('name', 'Fuel and deliveries')->firstOrFail();
            $day = now()->startOfDay();

            foreach ([2, 1] as $back) {
                Transaction::create($context->ownerAttributes() + [
                    'account_id' => $cheque->id, 'category_id' => $fuel->id, 'description' => 'Courier: wholesale parcel to Stellenbosch', 'amount_cents' => 86_500,
                    'currency' => 'ZAR', 'type' => 'expense', 'date' => $day->copy()->subDays($back)->toDateString(),
                ]);
            }
            Transaction::create($context->ownerAttributes() + [
                'account_id' => $cheque->id, 'category_id' => $fuel->id, 'description' => 'Delivery van repairs, cash', 'amount_cents' => 1_000_000,
                'currency' => 'ZAR', 'type' => 'expense', 'date' => $day->copy()->subDays(3)->toDateString(),
            ]);

            app(InsightEngine::class)->scan($context);

            $assistant = app(FinanceAssistant::class);
            foreach (['How much did I spend this month?', 'Which expenses increased the most?', 'Add an expense of R350 for printer paper'] as $question) {
                $assistant->ask($user, $context, $question);
            }

            app(NotificationRules::class)->run($context);
        });
    }

    private function personal(User $user): void
    {
        $owner = ['user_id' => $user->id, 'team_id' => null];
        $start = now()->subMonths(2)->startOfMonth();

        Account::create($owner + ['name' => 'Everyday Account', 'type' => 'checking', 'currency' => 'ZAR', 'institution' => 'Capitec', 'opening_balance_cents' => 1_240_000, 'opening_balance_date' => $start->toDateString()]);
        Account::create($owner + ['name' => 'Tax-Free Savings', 'type' => 'savings', 'currency' => 'ZAR', 'institution' => 'Capitec', 'opening_balance_cents' => 3_500_000, 'opening_balance_date' => $start->toDateString()]);
    }
}
