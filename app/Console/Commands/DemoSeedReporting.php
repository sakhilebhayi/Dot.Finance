<?php

namespace App\Console\Commands;

use App\Contracts\Allocatable;
use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Category;
use App\Models\Planning\Dimension;
use App\Models\Planning\Scenario;
use App\Models\Shared\Party;
use App\Models\Team;
use App\Models\User;
use App\Services\Accounting\GlBackfill;
use App\Services\Accounting\GlSync;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Planning\Assumptions;
use App\Services\Planning\PlanBudgets;
use App\Services\Reporting\ReportRunner;
use App\Services\Shared\PaymentAllocator;
use App\Services\Shared\PaymentRecorder;
use App\Support\DemoRates;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * LOCAL DEMO DATA ONLY. Adds to the fictional demo company (finance:demo-seed) what the reports and planning pages need to
 * look like a working business: the double-entry books switched on, credit customers with invoices some of which are paid
 * late and some of which are open, suppliers with bills, payments and their allocations (recorded through the real payment
 * services), named departments and projects carried on the journal lines, an approved plan for the current year and two
 * saved scenarios. Everything is deterministic (fixed amounts, dates counted back from today) and fictional.
 *
 * Invoices and bills are posted straight through the journal poster because the Sales and Purchases modules that will issue
 * them are built in parallel; receipts and payments go to the "Bank: main account" GL account, not to a cash-ledger account.
 * That is a demo shortcut, stated here, and it does not affect the cash tie-out (which compares only cash-ledger accounts).
 */
#[Signature('finance:demo-seed-reporting {--no-documents : Leave out the stand-in customers, suppliers, invoices, bills and payments (use when the Sales and Purchases demos already hold real documents)}')]
#[Description('Local only: add invoices, bills, payments, departments, a plan and scenarios to the demo company so reports and planning have something to show')]
class DemoSeedReporting extends Command
{
    public function handle(PaymentRecorder $payments, PaymentAllocator $allocator, PlanBudgets $plans, GlBackfill $backfill, JournalPoster $poster): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('finance:demo-seed-reporting only runs in a local or testing environment.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', DemoSeed::EMAIL)->first();
        $team = Team::query()->where('name', DemoSeed::COMPANY)->first();
        if ($user === null || $team === null) {
            $this->error('Run php artisan finance:demo-seed first.');

            return self::FAILURE;
        }

        $ctx = LedgerContext::company($team);
        $gl = app(GlSync::class);

        if (! $gl->isEnabled($ctx)) {
            // The demo company holds a small USD account. The books value it at the ledger's USD to ZAR rates, so those go in first.
            DemoRates::ensure($ctx);

            LedgerContext::run($ctx, function () use ($backfill, $ctx) {
                $backfill->prepare($ctx, 'ZAR', 1, 'za_company');
                $run = $backfill->apply($ctx);
                if ($run->status !== 'verified') {
                    throw new \RuntimeException('The books did not verify, so accounting was not switched on: '.json_encode($run->report['verify']['checks'] ?? []));
                }
                $backfill->enable($ctx);
            });
            $gl->flush();
            $this->info('Accounting switched on for the demo company.');
        }

        $lean = (bool) $this->option('no-documents');
        $already = LedgerContext::run($ctx, fn () => $lean ? Dimension::query()->exists() : Party::query()->where('name', 'Salt River Deli')->exists());
        if ($already) {
            $this->error('The reporting demo data already exists for this company.');

            return self::FAILURE;
        }

        LedgerContext::run($ctx, function () use ($ctx, $user, $payments, $allocator, $plans, $poster, $lean) {
            DB::transaction(fn () => $this->build($ctx, $user, $payments, $allocator, $plans, $poster, $lean));
        });

        $this->info($lean ? 'Added departments, a project, an approved plan and two scenarios.' : 'Added customers, suppliers, invoices, bills, payments, departments, a plan and two scenarios.');

        return self::SUCCESS;
    }

    private function build(LedgerContext $ctx, User $user, PaymentRecorder $payments, PaymentAllocator $allocator, PlanBudgets $plans, JournalPoster $poster, bool $lean = false): void
    {
        $today = CarbonImmutable::today();
        $cheque = Account::query()->where('name', 'Business Cheque')->firstOrFail();
        $owner = $ctx->ownerAttributes();

        foreach ([['department:wholesale', 'department', 'Wholesale'], ['department:counter', 'department', 'Roastery counter'], ['project:fit-out', 'project', 'Cafe fit-out']] as [$ref, $kind, $name]) {
            Dimension::create($owner + ['ref' => $ref, 'kind' => $kind, 'name' => $name]);
        }

        $number = 1000;
        $post = function (CarbonImmutable $date, string $type, string|int $id, string $memo, array $lines) use ($poster) {
            return $poster->post(new PostingRequest($date, $type, $id, 1, $memo, $lines));
        };

        // The stand-in documents (journals posted straight to the books with no invoice or bill behind them) are only made when the
        // real Sales and Purchases demos have not run: next to real documents they duplicated customers and suppliers and left the
        // receivables and payables ledgers disagreeing with the books.
        if (! $lean) {
            $customers = [];
            foreach ([['Salt River Deli', 30], ['Bo-Kaap Bakehouse', 30], ['Observatory Co-working', 14], ['Gardens Pantry', 30], ['Southern Suburbs Hotel', 45]] as [$name, $terms]) {
                $customers[$name] = Party::create($owner + ['type' => 'customer', 'name' => $name, 'payment_terms_days' => $terms, 'status' => 'active', 'currency' => 'ZAR']);
            }
            $suppliers = [];
            foreach ([['Cape Green Coffee Importers', 30], ['Woodstock Packaging', 30], ['Table Bay Properties', 7]] as [$name, $terms]) {
                $suppliers[$name] = Party::create($owner + ['type' => 'supplier', 'name' => $name, 'payment_terms_days' => $terms, 'status' => 'active', 'currency' => 'ZAR']);
            }

            // Invoices: one a month per customer for nine months; the first six months of the first three customers are paid
            // 8 to 14 days after the due date (so a lateness history exists); the newest two months are open; one is long overdue.
            $nine = fn (int $back) => $today->startOfMonth()->subMonths($back)->addDays(9);
            $settled = [];
            $openInvoices = 0;
            foreach (array_keys($customers) as $ci => $name) {
                $c = $customers[$name];
                for ($back = 9; $back >= 1; $back--) {
                    $date = $nine($back)->addDays($ci);
                    $net = 1_200_000 + 150_000 * $ci + 40_000 * (9 - $back);
                    $vat = intdiv($net * 15, 100);
                    $id = ++$number;
                    $dimension = $ci % 2 === 0 ? 'department:wholesale' : 'department:counter';
                    $post($date, 'invoice', $id, "Invoice INV-{$id} {$name}", [
                        new PostingLine('ar', debitCents: $net + $vat, partyId: $c->id),
                        new PostingLine('sales', creditCents: $net, partyId: $c->id, dimension: $dimension),
                        new PostingLine('vat_output', creditCents: $vat, partyId: $c->id),
                    ]);

                    $isOverdue = $ci === 4 && $back === 5;   // never paid
                    $isOpen = $back <= 2;                       // too recent to be paid
                    if ($isOverdue || $isOpen || ($ci >= 3 && $back <= 4)) {
                        $openInvoices++;

                        continue;
                    }

                    $due = $date->addDays($c->payment_terms_days);
                    $paidOn = $due->addDays(8 + (($ci * 3 + $back) % 7));
                    if ($paidOn->gt($today)) {
                        $openInvoices++;

                        continue;
                    }
                    $settled[] = [$id, $c, $net + $vat, $paidOn];
                }
            }

            foreach ($settled as $n => [$id, $c, $gross, $paidOn]) {
                $post($paidOn, 'payment', 'rcpt-'.$id, "Receipt for INV-{$id}", [
                    new PostingLine('bank', debitCents: $gross, partyId: $c->id),
                    new PostingLine('ar', creditCents: $gross, partyId: $c->id),
                ]);
                $payment = $payments->record([
                    'direction' => 'in', 'party_id' => $c->id, 'cash_account_id' => $cheque->id, 'amount_cents' => $gross,
                    'paid_on' => $paidOn->toDateString(), 'method' => 'eft', 'idempotency_key' => 'demo-rcpt-'.$id,
                ], $user, $ctx);
                $allocator->allocate($payment, $this->document('invoice', $id, $gross, 'in', $c->id, $ctx), $gross, by: $user);
            }

            // Bills: green coffee (cost of sales) and packaging each month, rent on the 1st; the last month's are open.
            $bills = [['Cape Green Coffee Importers', 'cost_of_sales', 2_400_000, 'department:wholesale'], ['Woodstock Packaging', 'cost_of_sales', 380_000, 'department:counter'], ['Table Bay Properties', 'Rent', 1_850_000, null]];
            foreach ($bills as $bi => [$supplier, $account, $net, $dimension]) {
                $s = $suppliers[$supplier];
                for ($back = 6; $back >= 0; $back--) {
                    $date = $today->startOfMonth()->subMonths($back)->addDays(2 + $bi);
                    if ($date->gt($today)) {
                        continue;
                    }
                    $vat = intdiv($net * 15, 100);
                    $id = ++$number;
                    $expense = $account === 'cost_of_sales' ? 'cost_of_sales' : (string) Category::query()->where('name', $account)->value('gl_account_id');
                    $post($date, 'bill', $id, "Bill BILL-{$id} {$supplier}", [
                        new PostingLine($expense, debitCents: $net, partyId: $s->id, dimension: $dimension),
                        new PostingLine('vat_input', debitCents: $vat, partyId: $s->id),
                        new PostingLine('ap', creditCents: $net + $vat, partyId: $s->id),
                    ]);

                    if ($back >= 1) {
                        $paidOn = $date->addDays($s->payment_terms_days);
                        if ($paidOn->gt($today)) {
                            continue;
                        }
                        $gross = $net + $vat;
                        $post($paidOn, 'payment', 'pay-'.$id, "Payment of BILL-{$id}", [
                            new PostingLine('ap', debitCents: $gross, partyId: $s->id),
                            new PostingLine('bank', creditCents: $gross, partyId: $s->id),
                        ]);
                        $payment = $payments->record([
                            'direction' => 'out', 'party_id' => $s->id, 'cash_account_id' => $cheque->id, 'amount_cents' => $gross,
                            'paid_on' => $paidOn->toDateString(), 'method' => 'eft', 'idempotency_key' => 'demo-pay-'.$id,
                        ], $user, $ctx);
                        $allocator->allocate($payment, $this->document('bill', $id, $gross, 'out', $s->id, $ctx), $gross, by: $user);
                    }
                }
            }
        }

        // A project cost tagged to the fit-out, to give the project view something to show.
        $post($today->subDays(40), 'manual', 'demo-fitout', 'Espresso machine and counter (cafe fit-out)'.($lean ? ', financed by a director loan' : ''), [
            new PostingLine(GlAccount::query()->where('code', '6160')->value('id'), debitCents: 3_200_000, dimension: 'project:fit-out'),
            new PostingLine($lean ? 'loans_payable' : 'bank', creditCents: 3_200_000),
        ]);

        // An approved plan for the current calendar year and two saved scenarios.
        $plan = $plans->create($ctx, $user, ['name' => 'Budget '.$today->year, 'starts_on' => $today->startOfYear()->toDateString(), 'ends_on' => $today->endOfYear()->toDateString(), 'currency' => 'ZAR']);
        $category = fn (string $name) => (int) Category::query()->where('name', $name)->value('gl_account_id');
        $system = fn (string $key) => (int) GlAccount::query()->where('system_key', $key)->value('id');
        $rows = array_values(array_filter([
            ['gl_account_id' => $category('Wholesale'), 'annual' => 150_000_000],
            ['gl_account_id' => $category('Roastery counter'), 'annual' => 55_000_000],
            ['gl_account_id' => $category('Online shop'), 'annual' => 28_000_000],
            ['gl_account_id' => $system('sales'), 'dimension' => 'department:wholesale', 'annual' => 24_000_000],
            ['gl_account_id' => $system('sales'), 'dimension' => 'department:counter', 'annual' => 12_000_000],
            ['gl_account_id' => $system('cost_of_sales'), 'annual' => 36_000_000],
            ['gl_account_id' => $category('Rent'), 'annual' => 22_200_000],
            ['gl_account_id' => $category('Salaries and wages'), 'annual' => 56_160_000],
            ['gl_account_id' => $category('Green coffee'), 'annual' => 30_000_000],
        ], fn ($r) => $r['gl_account_id'] > 0));
        $plans->setLines($plan, $rows);
        $plans->submit($plan, $user);

        foreach ([
            ['Customers pay 7 days sooner', 'weeks_13', [['type' => 'collect_faster', 'days' => '7']]],
            ['Hire a second roaster', 'months_12', [['type' => 'new_cost', 'name' => 'Second roaster', 'amount' => '28000.00', 'start_on' => $today->addMonth()->startOfMonth()->toDateString(), 'months' => ''], ['type' => 'change_recurring', 'direction' => 'income', 'percent' => '4']]],
        ] as [$name, $horizon, $assumptions]) {
            Scenario::create($owner + ['name' => $name, 'horizon' => $horizon, 'assumptions' => Assumptions::normalise($assumptions), 'created_by' => $user->id]);
        }

        app(ReportRunner::class)->run($ctx, 'profit-loss', []);
    }

    /** What PaymentAllocator asks of a document, for a demo invoice or bill that has no model of its own yet. */
    private function document(string $kind, int $id, int $total, string $direction, int $partyId, LedgerContext $ctx): Allocatable
    {
        return new class($kind, $id, $total, $direction, $partyId, $ctx->ownerAttributes()) implements Allocatable
        {
            public function __construct(private string $kind, private int $id, private int $total, private string $direction, private int $partyId, private array $owner) {}

            public function getMorphClass()
            {
                return $this->kind;
            }

            public function getKey()
            {
                return $this->id;
            }

            public function allocatableTotalCents(): int
            {
                return $this->total;
            }

            public function allocatableCurrency(): string
            {
                return 'ZAR';
            }

            public function allocatablePartyId(): ?int
            {
                return $this->partyId;
            }

            public function allocatableDirection(): string
            {
                return $this->direction;
            }

            public function ledgerOwner(): array
            {
                return $this->owner;
            }
        };
    }
}
