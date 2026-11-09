<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankRule;
use App\Models\Bank\BankStatement;
use App\Models\Category;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Bank\MatchingService;
use App\Services\Bank\ReconciliationBalances;
use App\Services\Bank\ReconciliationService;
use App\Services\Bank\StatementImporter;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LOCAL DEMO DATA ONLY. Adds bank statements and reconciliations to the fictional "Karoo Roasters" demo ledger that
 * finance:demo-seed builds, so the bank screens can be looked at in a realistic state. Everything goes through the real
 * services: the statements are CSV files imported by StatementImporter, the matches are made by MatchingService, and the
 * reconciliation is completed by ReconciliationService, so every rule (fingerprints, locks, history, audit) applies.
 *
 *   last month   a statement for the whole month, reconciled to the cent and completed (the report and the history exist)
 *   this month   a statement so far, left open: lines that match for you, lines that need a look, and the exceptions the inbox
 *                groups (a bank fee, interest, a deposit and a payment nobody recorded, a close amount, a timing difference,
 *                a line a bank rule recognises) plus two entries not on the statement yet
 *
 * Nothing here is a real bank, person or company. The bank's narrative text is invented from the entries' own descriptions.
 * It refuses to run outside local/testing, and stops when bank data already exists: bank history is append-only (that is the
 * point of it), so it cannot be rebuilt in place. Start from `php artisan migrate:fresh` and `finance:demo-seed`.
 */
#[Signature('finance:demo-seed-bank')]
#[Description('Local only: add bank statements and reconciliations to the demo ledger made by finance:demo-seed')]
class BankDemoSeed extends Command
{
    public function handle(StatementImporter $importer, MatchingService $matching, ReconciliationService $reconciliations, ReconciliationBalances $balances): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('finance:demo-seed-bank only runs in a local or testing environment, never in production or staging.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', DemoSeed::EMAIL)->first();
        $company = $user ? Team::query()->where('user_id', $user->id)->where('name', DemoSeed::COMPANY)->first() : null;

        if (! $user || ! $company) {
            $this->error('Run finance:demo-seed first: there is no demo ledger to add bank data to.');

            return self::FAILURE;
        }

        $context = LedgerContext::company($company);

        $exists = LedgerContext::run($context, fn () => BankStatement::query()->exists());
        if ($exists) {
            $this->error('The demo ledger already has bank statements. Bank history is append-only, so it cannot be rebuilt here: run `php artisan migrate:fresh` and `finance:demo-seed` first.');

            return self::FAILURE;
        }

        LedgerContext::run($context, function () use ($user, $context, $importer, $matching, $reconciliations, $balances) {
            $cheque = Account::query()->where('name', 'Business Cheque')->firstOrFail();
            $today = now()->startOfDay();
            $lastMonth = $today->copy()->subMonthNoOverflow()->startOfMonth();

            DB::transaction(function () use ($user, $context, $importer, $matching, $reconciliations, $balances, $cheque, $today, $lastMonth) {
                $this->closedMonth($user, $context, $importer, $matching, $reconciliations, $balances, $cheque, $lastMonth);
                $this->openMonth($user, $context, $importer, $matching, $reconciliations, $cheque, $today);
            });
        });

        $this->info('Bank demo data added to '.DemoSeed::COMPANY.': last month reconciled, this month open with exceptions to work through.');

        return self::SUCCESS;
    }

    private function closedMonth(User $user, LedgerContext $context, StatementImporter $importer, MatchingService $matching, ReconciliationService $reconciliations, ReconciliationBalances $balances, Account $cheque, Carbon $first): void
    {
        $end = $first->copy()->endOfMonth()->startOfDay();
        $rows = $this->rows($cheque, $first, $end);

        $lines = [];
        foreach ($rows as $row) {
            $date = Carbon::parse($row->date);
            $bankDate = $row->id % 4 === 0 ? $date->copy()->addDay() : $date->copy();
            $lines[] = [min($bankDate->toDateString(), $end->toDateString()), $this->narrative($row), $row->signed, $row->id];
        }
        usort($lines, fn ($a, $b) => [$a[0], $a[3]] <=> [$b[0], $b[3]]);

        $opening = $balances->ledgerBalanceAt($cheque, $first->copy()->subDay()->toDateString());
        $statement = $this->import($importer, $user, $context, $cheque, 'karoo-cheque-'.$first->format('Y-m').'.csv', $lines, $opening);

        $session = $reconciliations->start($user, $cheque, $end->toDateString(), $balances->ledgerBalanceAt($cheque, $end->toDateString()), $opening, $statement);
        $matching->accept($session, $matching->suggest($session)['proposals'], $user, 'auto');
        $reconciliations->complete($session, $user);
    }

    private function openMonth(User $user, LedgerContext $context, StatementImporter $importer, MatchingService $matching, ReconciliationService $reconciliations, Account $cheque, Carbon $today): void
    {
        $first = $today->copy()->startOfMonth();
        $through = $today->copy()->subDay();

        if ($through->lt($first)) {
            return;
        }

        $rows = $this->rows($cheque, $first, $through)->values();
        $lines = [];

        foreach ($rows as $i => $row) {
            // two entries the bank does not show yet; one shown at a slightly different amount; one shown ten days late; the rest as they are
            if ($i === 1 || $i === 4) {
                continue;
            }
            $cents = $row->signed;
            $date = Carbon::parse($row->date);
            if ($i === 2 && $cents > 0) {
                $cents -= 1_500;
            }
            if ($i === 3 && $date->copy()->addDays(9)->lte($through)) {
                $date = $date->copy()->addDays(9);   // the bank shows it well after the date in the books
            }
            $lines[] = [$date->toDateString(), $this->narrative($row), $cents, $row->id];
        }

        $at = fn (int $d) => min($first->copy()->addDays($d)->toDateString(), $through->toDateString());
        $lines[] = [$at(1), 'SERVICE FEE SMS NOTIFICATIONS', -1_250, 9001];
        $lines[] = [$at(2), 'CREDIT INTEREST', 1_840, 9002];
        $lines[] = [$at(3), 'EFT DEPOSIT J NKOSI', 215_000, 9003];
        $lines[] = [$at(4), 'POS PURCHASE ENGEN WOODSTOCK FUEL', -64_000, 9004];
        $lines[] = [$at(5), 'DEBIT ORDER GARDEN CITY PRINT', -41_200, 9005];
        usort($lines, fn ($a, $b) => [$a[0], $a[3]] <=> [$b[0], $b[3]]);

        $previous = BankReconciliation::query()->where('account_id', $cheque->id)->where('status', 'completed')->orderByDesc('statement_date')->first();
        $opening = $previous?->statement_closing_cents ?? 0;
        $statement = $this->import($importer, $user, $context, $cheque, 'karoo-cheque-'.$first->format('Y-m').'.csv', $lines, $opening);

        $fuel = Category::query()->where('name', 'Fuel and deliveries')->first();
        BankRule::create($context->ownerAttributes() + ['name' => 'Engen fuel', 'pattern' => 'engen', 'direction' => 'out', 'category_id' => $fuel?->id, 'priority' => 10, 'account_id' => $cheque->id]);

        $reconciliations->start($user, $cheque, $through->toDateString(), $statement->closing_balance_cents, $opening, $statement);
    }

    /** @return Collection<int, object{id: int, date: string, description: string, type: string, signed: int}> */
    private function rows(Account $cheque, Carbon $from, Carbon $to)
    {
        $signed = Transaction::signedCentsSql('transactions');

        return Transaction::query()->counted()->where('account_id', $cheque->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereRaw("({$signed}) <> 0")->orderBy('date')->orderBy('id')
            ->toBase()->selectRaw("transactions.id, transactions.date, transactions.description, transactions.type, ({$signed}) AS signed")->get()
            ->map(fn ($r) => (object) ['id' => (int) $r->id, 'date' => substr((string) $r->date, 0, 10), 'description' => (string) $r->description, 'type' => (string) $r->type, 'signed' => (int) $r->signed]);
    }

    private function narrative(object $row): string
    {
        $text = mb_strtoupper(preg_replace('/[:\']/u', '', $row->description));

        return match (true) {
            $row->type === 'transfer' => 'IB TRANSFER '.($row->signed < 0 ? 'TO ' : 'FROM ').$text,
            $row->signed > 0 => 'EFT CREDIT '.$text,
            default => 'DEBIT '.$text,
        };
    }

    /** @param  list<array{0: string, 1: string, 2: int, 3: int}>  $lines */
    private function import(StatementImporter $importer, User $user, LedgerContext $context, Account $cheque, string $name, array $lines, int $opening): BankStatement
    {
        $balance = $opening;
        $csv = "Date,Description,Amount,Balance\n";
        foreach ($lines as [$date, $text, $cents, $id]) {
            $balance += $cents;
            $csv .= sprintf("%s,\"%s\",%s,%s\n", $date, str_replace('"', '""', $text), Money::toDecimalString($cents), Money::toDecimalString($balance));
        }

        $file = UploadedFile::fake()->createWithContent($name, $csv);
        $token = $importer->stage($user, $context, $cheque, $file);

        return $importer->commit($user, $context, $token, ['date_format' => 'ymd', 'amount_locale' => 'en'])['statement'];
    }
}
