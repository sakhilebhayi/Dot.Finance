<?php

namespace Tests\Support\Perf;

use App\Models\Accounting\LedgerSetting;
use App\Services\Accounting\Periods;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * LOCAL PERFORMANCE FIXTURE, never run against production. Grows the fictional demo company (finance:demo-seed --sales) into a
 * busy ledger so pages, reports, jobs, backup and restore can be measured at scale: cash-ledger rows with their journals, credit
 * customers and suppliers, invoices and bills with payments and allocations (each payment's bank leg written as the real
 * services write it), a year of VAT-tagged lines, and bank statements.
 *
 * Rows are written straight into the tables in bulk (explicit ids, multi-row inserts) because posting 300,000 entries through the
 * services would take hours. Every shape copies what the services write (journal lines, hash chain, settlement rows), and the
 * result is judged by the real verifiers: finance:gl:verify, finance:check-balances and sales:check-receivables must pass.
 *
 * Deterministic: the same seed and sizes give the same rows. Sizes come from the environment (PERF_TX, PERF_INV, PERF_BILL,
 * PERF_PARTIES); the defaults are the full-size run (about 250,000 entries and 550,000 journal lines).
 */
final class PerfSeeder
{
    private const START = '2025-11-02';

    private const END = '2026-11-08';

    private int $teamId;

    private int $userId;

    private string $ctxKey;

    /** @var array<int, int> cash account id => gl account id */
    private array $cashGl = [];

    /** @var array<int, int> category id => gl account id */
    private array $catGl = [];

    /** @var array<string, int> */
    private array $sys = [];

    /** @var array<string, int> month 'Y-m' => period id */
    private array $periods = [];

    private int $entryId;

    private int $entryNo;

    private ?string $prevHash;

    private int $txId;

    private int $lineId;

    private int $docId;

    private int $docLineId;

    private int $billId;

    private int $billLineId;

    private int $payId;

    private int $allocId;

    private int $invNo;

    private int $billNo;

    private int $appId;

    private int $receipts;

    private int $payouts;

    /** @var array<string, list<array<string, mixed>>> */
    private array $buf = [];

    private int $bufEntries = 0;

    /** @var list<int> */
    private array $customers = [];

    /** @var list<int> */
    private array $suppliers = [];

    /** @var array<int, string> */
    private array $partyName = [];

    /** @var array<string, list<array<string, mixed>>> payments scheduled for a day */
    private array $scheduled = [];

    /** @var array<string, int> */
    public array $counts = [];

    public function __construct(private readonly string $company = 'Karoo Roasters (Pty) Ltd') {}

    public function run(array $sizes = []): array
    {
        $sizes += [
            'tx' => (int) (getenv('PERF_TX') ?: 165000),
            'inv' => (int) (getenv('PERF_INV') ?: 25000),
            'bill' => (int) (getenv('PERF_BILL') ?: 25000),
            'parties' => (int) (getenv('PERF_PARTIES') ?: 5000),
            'seed' => (int) (getenv('PERF_SEED') ?: 44),
        ];
        mt_srand($sizes['seed']);
        DB::disableQueryLog();

        $team = DB::table('teams')->where('name', $this->company)->first() ?? throw new \RuntimeException('Run finance:demo-seed --sales first.');
        $this->teamId = (int) $team->id;
        $this->userId = (int) $team->user_id;
        $ctx = LedgerContext::company($this->teamId);
        $this->ctxKey = $ctx->key();

        $this->load($ctx);
        $this->makeParties($sizes['parties']);
        $this->loop($sizes);
        $this->flush();
        $this->finish();

        return $this->counts;
    }

    private function load(LedgerContext $ctx): void
    {
        foreach (DB::table('accounts')->where('team_id', $this->teamId)->whereNull('deleted_at')->where('currency', 'ZAR')->get() as $a) {
            $this->cashGl[(int) $a->id] = (int) $a->gl_account_id;
        }
        $names = DB::table('categories')->where('team_id', $this->teamId)->pluck('id', 'name');
        $this->receipts = (int) ($names['Customer receipts'] ?? DB::table('categories')->insertGetId(['team_id' => $this->teamId, 'name' => 'Customer receipts', 'type' => 'income', 'created_at' => now(), 'updated_at' => now()]));
        $this->payouts = (int) ($names['Supplier payments'] ?? DB::table('categories')->insertGetId(['team_id' => $this->teamId, 'name' => 'Supplier payments', 'type' => 'expense', 'created_at' => now(), 'updated_at' => now()]));
        foreach (DB::table('categories')->where('team_id', $this->teamId)->get() as $c) {
            if ($c->gl_account_id !== null) {
                $this->catGl[(int) $c->id] = (int) $c->gl_account_id;
            }
        }
        foreach (DB::table('gl_accounts')->where('team_id', $this->teamId)->whereNotNull('system_key')->get() as $g) {
            $this->sys[(string) $g->system_key] = (int) $g->id;
        }

        $settings = LedgerSetting::query()->forContext($ctx)->first();
        $periods = app(Periods::class);
        LedgerContext::run($ctx, function () use ($ctx, $settings, $periods) {
            for ($d = CarbonImmutable::parse(self::START)->startOfMonth(); $d->lte(CarbonImmutable::parse(self::END)); $d = $d->addMonth()) {
                $this->periods[$d->format('Y-m')] = (int) $periods->forDate($ctx, $d, $settings)->id;
            }
        });

        $last = DB::table('journal_entries')->where('team_id', $this->teamId)->orderByDesc('entry_number')->first(['entry_number', 'entry_hash']);
        $this->entryNo = $last ? (int) $last->entry_number + 1 : 1;
        $this->prevHash = $last?->entry_hash;
        $this->entryId = (int) DB::table('journal_entries')->max('id') + 1;
        $this->lineId = (int) DB::table('journal_lines')->max('id') + 1;
        $this->txId = (int) DB::table('transactions')->max('id') + 1;
        $this->docId = (int) DB::table('sales_documents')->max('id') + 1;
        $this->docLineId = (int) DB::table('sales_document_lines')->max('id') + 1;
        $this->billId = (int) DB::table('bills')->max('id') + 1;
        $this->billLineId = (int) DB::table('bill_lines')->max('id') + 1;
        $this->payId = (int) DB::table('payments')->max('id') + 1;
        $this->allocId = (int) DB::table('payment_allocations')->max('id') + 1;
        $this->invNo = (int) DB::table('document_sequences')->where('team_id', $this->teamId)->where('doc_type', 'invoice')->value('next_number') ?: 100;
        $this->billNo = 1;
        $this->appId = (int) DB::table('sales_applications')->max('id') + 1;
    }

    private function makeParties(int $count): void
    {
        $first = ['Karoo', 'Fynbos', 'Table', 'Harbour', 'Protea', 'Baobab', 'Lion', 'Springbok', 'Cape', 'Jozi', 'Durban', 'Ubuntu', 'Savanna', 'Marula', 'Kloof', 'Sunbird', 'Rooibos', 'Highveld', 'Lowveld', 'Drakensberg'];
        $second = ['Trading', 'Foods', 'Logistics', 'Packaging', 'Hospitality', 'Catering', 'Supplies', 'Holdings', 'Hardware', 'Print', 'Cafe', 'Wholesale', 'Distributors', 'Farms', 'Roasters', 'Bakery'];
        $base = (int) DB::table('parties')->max('id');
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $id = $base + $i;
            $customer = $i % 2 === 1;
            $name = $first[$i % 20].' '.$second[intdiv($i, 20) % 16].' '.str_pad((string) $i, 5, '0', STR_PAD_LEFT);
            $rows[] = [
                'id' => $id, 'user_id' => null, 'team_id' => $this->teamId, 'type' => $customer ? 'customer' : 'supplier', 'name' => $name,
                'legal_name' => $name.' (Pty) Ltd', 'email' => 'acc'.$i.'@perf.example', 'currency' => 'ZAR', 'payment_terms_days' => 30,
                'credit_limit_cents' => $customer ? 5_000_000 : null, 'status' => 'active', 'created_at' => '2025-11-01 08:00:00', 'updated_at' => '2025-11-01 08:00:00',
            ];
            $customer ? $this->customers[] = $id : $this->suppliers[] = $id;
            $this->partyName[$id] = $name;
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('parties')->insert($chunk);
        }
        DB::statement("SELECT setval(pg_get_serial_sequence('parties','id'), (SELECT MAX(id) FROM parties))");
        $this->counts['parties'] = $count;
    }

    private function loop(array $sizes): void
    {
        $start = CarbonImmutable::parse(self::START);
        $days = $start->diffInDays(CarbonImmutable::parse(self::END)) + 1;
        $txPer = $sizes['tx'] / $days;
        $invPer = $sizes['inv'] / $days;
        $billPer = $sizes['bill'] / $days;
        $carry = ['tx' => 0.0, 'inv' => 0.0, 'bill' => 0.0];

        for ($i = 0; $i < $days; $i++) {
            $day = $start->addDays($i);
            $date = $day->toDateString();
            $weight = $day->isWeekend() ? 0.35 : 1.15; // weekdays are busier; the mean stays near 1
            foreach (['tx' => $txPer, 'inv' => $invPer, 'bill' => $billPer] as $k => $per) {
                $carry[$k] += $per * $weight;
            }
            $nTx = (int) $carry['tx'];
            $nInv = (int) $carry['inv'];
            $nBill = (int) $carry['bill'];
            $carry['tx'] -= $nTx;
            $carry['inv'] -= $nInv;
            $carry['bill'] -= $nBill;

            foreach ($this->scheduled[$date] ?? [] as $pay) {
                $this->payment($pay, $date);
            }
            unset($this->scheduled[$date]);
            for ($n = 0; $n < $nInv; $n++) {
                $this->invoice($date);
            }
            for ($n = 0; $n < $nBill; $n++) {
                $this->bill($date);
            }
            for ($n = 0; $n < $nTx; $n++) {
                $this->cashTx($date);
            }
            if ($this->bufEntries >= 600) {
                $this->flush();
            }
        }
    }

    // ---- events -----------------------------------------------------------------------------------------------

    private function cashTx(string $date): void
    {
        static $names = ['Checkers', 'Engen fuel', 'Takealot', 'Woolworths', 'Telkom', 'City of Cape Town', 'Vodacom', 'Pick n Pay', 'Builders Warehouse', 'Uber', 'Yoco payout', 'Snapscan settlement', 'Zoom', 'Google Workspace', 'Office Choice', 'Makro', 'FNB fee', 'Courier Guy', 'Dis-Chem', 'Shell'];
        $roll = mt_rand(1, 100);
        $account = $roll <= 50 ? 1 : ($roll <= 65 ? 2 : ($roll <= 75 ? 4 : 3));
        $income = $account !== 3 && mt_rand(1, 100) <= 30;
        $category = $income ? mt_rand(1, 3) : [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 15][mt_rand(0, 10)];
        $cents = (int) round(exp(mt_rand(700, 1250) / 100));
        $desc = $names[mt_rand(0, 19)].' ref '.mt_rand(100000, 999999);
        $id = $this->txId++;
        $this->buf['transactions'][] = [
            'id' => $id, 'account_id' => $account, 'category_id' => $category, 'user_id' => null, 'team_id' => $this->teamId, 'description' => $desc,
            'amount' => number_format($cents / 100, 2, '.', ''), 'amount_cents' => $cents, 'currency' => 'ZAR', 'type' => $income ? 'income' : 'expense',
            'date' => $date, 'version' => 1, 'recurrence_interval' => 1, 'created_at' => $date.' 09:00:00', 'updated_at' => $date.' 09:00:00',
        ];
        $cashLine = [$this->cashGl[$account], $income ? $cents : 0, $income ? 0 : $cents, $account, null, null, null, $desc];
        $catLine = [$this->catGl[$category], $income ? 0 : $cents, $income ? $cents : 0, null, $category, null, null, $desc];
        $this->entry($date, 'transaction', (string) $id, ($income ? 'Income' : 'Expense').': '.$desc, $cents, $income ? [$cashLine, $catLine] : [$catLine, $cashLine]);
    }

    private function invoice(string $date): void
    {
        $party = $this->customers[mt_rand(0, count($this->customers) - 1)];
        $net = mt_rand(5_000, 600_000) * mt_rand(1, 8);
        $tax = intdiv($net * 15 + 50, 100);
        $total = $net + $tax;
        $dueDate = CarbonImmutable::parse($date)->addDays(30)->toDateString();
        $id = $this->docId++;
        $number = 'INV-'.str_pad((string) $this->invNo++, 5, '0', STR_PAD_LEFT);

        // the outcome is decided now: paid in one or two payments, part paid, or open
        $roll = mt_rand(1, 100);
        $lag = mt_rand(5, 70);
        $payDate = CarbonImmutable::parse($date)->addDays($lag)->toDateString();
        $payments = [];
        if ($payDate <= self::END) {
            if ($roll <= 65) {
                $payments = [[$payDate, $total]];
            } elseif ($roll <= 80) {
                $first = intdiv($total * mt_rand(30, 70), 100);
                $second = CarbonImmutable::parse($payDate)->addDays(mt_rand(3, 20))->toDateString();
                $payments = $second <= self::END ? [[$payDate, $first], [$second, $total - $first]] : [[$payDate, $first]];
            } elseif ($roll <= 90) {
                $payments = [[$payDate, intdiv($total * mt_rand(20, 80), 100)]];
            }
        }
        $paid = array_sum(array_column($payments, 1));
        $status = $paid === 0 ? 'issued' : ($paid >= $total ? 'paid' : 'partially_paid');

        $entry = $this->entry($date, 'invoice', (string) $id, 'Tax invoice '.$number, $total, [
            [$this->sys['ar'], $total, 0, null, null, $party, null, 'Tax invoice '.$number],
            [$this->sys['sales'], 0, $net, null, null, null, 1, 'Sales: Tax invoice '.$number],
            [$this->sys['vat_output'], 0, $tax, null, null, null, 1, 'Output VAT: Tax invoice '.$number],
        ]);

        $now = $date.' 10:00:00';
        $this->buf['sales_documents'][] = [
            'id' => $id, 'user_id' => null, 'team_id' => $this->teamId, 'kind' => 'tax_invoice', 'status' => 'draft', 'internal_note' => $status, 'number_series' => 'invoice', 'reference' => $number,
            'party_id' => $party, 'issue_date' => $date, 'due_date' => $dueDate, 'currency' => 'ZAR', 'base_currency' => 'ZAR', 'fx_rate_locked' => 'f',
            'prices_include_tax' => 'f', 'tax_jurisdiction' => 'ZA', 'tax_rule_versions' => '["tax_rules#25","tax_rates#1","tax_codes#1"]', 'tax_confidence' => 'high',
            'vat_registered' => 't', 'net_cents' => $net, 'discount_cents' => 0, 'tax_cents' => $tax, 'total_cents' => $total, 'base_net_cents' => $net, 'base_tax_cents' => $tax,
            'base_total_cents' => $total, 'customer_snapshot' => json_encode(['name' => $this->partyName[$party]]), 'supplier_snapshot' => '{"name":"Karoo Roasters (Pty) Ltd"}',
            'gl_entry_id' => $entry, 'issued_at' => $now, 'issued_by' => $this->userId, 'created_by' => $this->userId,
            'written_off_cents' => 0, 'written_off_base_cents' => 0, 'version' => 2, 'created_at' => $now, 'updated_at' => $now,
        ];
        $lines = mt_rand(1, 3);
        $left = $net;
        for ($p = 1; $p <= $lines; $p++) {
            $n = $p === $lines ? $left : intdiv($net, $lines);
            $left -= $n;
            $t = intdiv($n * 15 + 50, 100);
            $this->buf['sales_document_lines'][] = [
                'id' => $this->docLineId++, 'user_id' => null, 'team_id' => $this->teamId, 'document_id' => $id, 'position' => $p, 'description' => 'Karoo Blend, 1 kg bags',
                'unit' => 'kg', 'quantity_e4' => 10000, 'unit_cents' => $n, 'discount_bp' => 0, 'discount_cents' => 0, 'tax_code' => 'ZA_VAT_STD', 'tax_code_id' => 1, 'rate_bp' => 1500,
                'before_discount_cents' => $n, 'line_discount_cents' => 0, 'net_cents' => $n, 'tax_cents' => $t, 'gross_cents' => $n + $t, 'income_account_id' => $this->sys['sales'],
                'box_output' => 1, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        $this->audit($date, 'issued', 'sales_document', $id, $number);
        $this->counts['invoices'] = ($this->counts['invoices'] ?? 0) + 1;

        foreach ($payments as [$when, $amount]) {
            $this->scheduled[$when][] = ['dir' => 'in', 'party' => $party, 'doc' => $id, 'number' => $number, 'amount' => $amount];
        }
    }

    private function bill(string $date): void
    {
        static $expense = [31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 57, 58, 59, 61, 62];
        $party = $this->suppliers[mt_rand(0, count($this->suppliers) - 1)];
        $net = mt_rand(3_000, 500_000) * mt_rand(1, 6);
        $tax = intdiv($net * 15 + 50, 100);
        $total = $net + $tax;
        $id = $this->billId++;
        $number = 'BILL-S-'.str_pad((string) $this->billNo++, 6, '0', STR_PAD_LEFT);
        $dueDate = CarbonImmutable::parse($date)->addDays(30)->toDateString();
        $gl = $expense[mt_rand(0, count($expense) - 1)];

        $roll = mt_rand(1, 100);
        $payDate = CarbonImmutable::parse($date)->addDays(mt_rand(8, 60))->toDateString();
        $paid = 0;
        if ($payDate <= self::END) {
            $paid = $roll <= 70 ? $total : ($roll <= 82 ? intdiv($total * mt_rand(30, 70), 100) : 0);
        }
        $status = $paid === 0 ? 'open' : ($paid >= $total ? 'paid' : 'partially_paid');
        $ref = 'SUP-'.mt_rand(100000, 999999);

        $entry = $this->entry($date, 'bill', (string) $id, 'Supplier bill '.$number.' from '.$this->partyName[$party], $total, [
            [$gl, $net, 0, null, null, $party, null, 'Perf supplies'],
            [$this->sys['vat_input'], $tax, 0, null, null, $party, 1, 'Input VAT'],
            [$this->sys['ap'], 0, $total, null, null, $party, null, 'Supplier invoice '.$ref],
        ]);
        $now = $date.' 11:00:00';
        $this->buf['bills'][] = [
            'id' => $id, 'user_id' => null, 'team_id' => $this->teamId, 'kind' => 'bill', 'party_id' => $party, 'number' => $number, 'supplier_reference' => $ref,
            'supplier_reference_norm' => str_replace('-', '', $ref), 'status' => $status, 'bill_date' => $date, 'due_date' => $dueDate, 'currency' => 'ZAR', 'tax_inclusive' => 'f',
            'subtotal_cents' => $net, 'tax_cents' => $tax, 'total_cents' => $total, 'claimable_tax_cents' => $tax, 'base_total_cents' => $total, 'document_type' => 'tax_invoice',
            'vat_review' => '[]', 'rule_versions' => '["tax_rules#25","tax_rates#1","tax_codes#1"]', 'rounding_level' => 'line', 'tax_confidence' => 'high',
            'posted_entry_id' => $entry, 'posted_at' => $now, 'posted_by' => $this->userId, 'source' => 'manual', 'created_by' => $this->userId, 'submitted_by' => $this->userId,
            'submitted_at' => $now, 'version' => 2, 'created_at' => $now, 'updated_at' => $now,
        ];
        $this->buf['bill_lines'][] = [
            'id' => $this->billLineId++, 'user_id' => null, 'team_id' => $this->teamId, 'bill_id' => $id, 'line_no' => 1, 'description' => 'Perf supplies', 'gl_account_id' => $gl,
            'quantity_e4' => 10000, 'unit_price_cents' => $net, 'discount_bp' => 0, 'tax_code' => 'ZA_VAT_STD', 'tax_code_id' => 1, 'tax_rate_bp' => 1500, 'net_cents' => $net,
            'tax_cents' => $tax, 'gross_cents' => $total, 'claimable_tax_cents' => $tax, 'created_at' => $now, 'updated_at' => $now,
        ];
        $this->audit($date, 'posted', 'bill', $id, $number);
        $this->counts['bills'] = ($this->counts['bills'] ?? 0) + 1;

        if ($paid > 0) {
            $this->scheduled[$payDate][] = ['dir' => 'out', 'party' => $party, 'doc' => $id, 'number' => $number, 'amount' => $paid];
        }
    }

    /** @param array{dir: string, party: int, doc: int, number: string, amount: int} $pay */
    private function payment(array $pay, string $date): void
    {
        $in = $pay['dir'] === 'in';
        $account = $in ? (mt_rand(1, 100) <= 85 ? 1 : 2) : (mt_rand(1, 100) <= 85 ? 1 : 2);
        $amount = $pay['amount'];
        $payId = $this->payId++;
        $txId = $this->txId++;
        $ref = ($in ? 'RCPT-' : 'PAY-').$payId;
        $name = $this->partyName[$pay['party']];
        $now = $date.' 14:00:00';

        $this->buf['transactions'][] = [
            'id' => $txId, 'account_id' => $account, 'category_id' => $in ? $this->receipts : $this->payouts, 'user_id' => null, 'team_id' => $this->teamId,
            'description' => ($in ? 'Payment received from ' : 'Payment to ').$name.' ('.$ref.')', 'amount' => number_format($amount / 100, 2, '.', ''), 'amount_cents' => $amount,
            'currency' => 'ZAR', 'type' => $in ? 'income' : 'expense', 'date' => $date, 'notes' => $ref, 'version' => 1, 'recurrence_interval' => 1, 'settlement_ref' => 'payment:'.$payId,
            'created_at' => $now, 'updated_at' => $now,
        ];
        $bank = [$this->cashGl[$account], $in ? $amount : 0, $in ? 0 : $amount, $account, null, null, null, $in ? 'Payment received' : 'Payment to '.$name];
        $control = [$in ? $this->sys['ar'] : $this->sys['ap'], $in ? 0 : $amount, $in ? $amount : 0, null, null, $pay['party'], null, $in ? 'Payment received' : 'Payment '.$ref];
        $entry = $this->entry($date, 'payment', (string) $payId, $in ? 'Payment received from '.$name : 'Payment to '.$name.' ('.$ref.')', $amount, $in ? [$bank, $control] : [$control, $bank]);

        $this->buf['payments'][] = [
            'id' => $payId, 'user_id' => null, 'team_id' => $this->teamId, 'direction' => $pay['dir'], 'party_id' => $pay['party'], 'cash_account_id' => $account,
            'amount_cents' => $amount, 'currency' => 'ZAR', 'base_amount_cents' => $amount, 'paid_on' => $date, 'method' => 'eft', 'reference' => $ref,
            'idempotency_key' => 'perf-'.$payId, 'status' => 'recorded', 'gl_entry_id' => $entry, 'transaction_id' => $txId, 'recorded_by' => $this->userId, 'created_at' => $now, 'updated_at' => $now,
        ];
        $this->buf['payment_allocations'][] = [
            'id' => $this->allocId++, 'user_id' => null, 'team_id' => $this->teamId, 'payment_id' => $payId, 'allocatable_type' => $in ? 'App\\Models\\Sales\\SalesDocument' : 'bill',
            'allocatable_id' => $pay['doc'], 'amount_cents' => $amount, 'tax_cents' => 0, 'fx_diff_cents' => 0, 'allocated_by' => $this->userId, 'created_at' => $now, 'updated_at' => $now,
        ];
        if ($in) {
            $this->buf['sales_applications'][] = [
                'id' => $this->appId++, 'user_id' => null, 'team_id' => $this->teamId, 'kind' => 'payment', 'payment_id' => $payId, 'allocation_id' => $this->allocId - 1,
                'target_document_id' => $pay['doc'], 'amount_cents' => $amount, 'target_base_cents' => $amount, 'source_base_cents' => $amount, 'fx_diff_base_cents' => 0,
                'applied_on' => $date, 'applied_by' => $this->userId, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        $this->audit($date, 'recorded', 'payment', $payId, $ref);
        $this->counts['payments'] = ($this->counts['payments'] ?? 0) + 1;
    }

    // ---- plumbing ---------------------------------------------------------------------------------------------

    /**
     * Write one entry, chained like the poster does. A line is [gl, debit, credit, cashAccountId, categoryId, partyId, taxCodeId, description].
     *
     * @param  list<array<int, mixed>>  $lines
     */
    private function entry(string $date, string $type, string $sourceId, string $memo, int $total, array $lines): int
    {
        $id = $this->entryId++;
        $no = $this->entryNo++;
        $hashLines = [];
        foreach ($lines as $l) {
            $hashLines[] = implode(':', [$l[0], $l[1], $l[2], '', '', '', $l[3] ?? '', $l[4] ?? '']);
        }
        $hash = hash('sha256', implode('|', [$this->prevHash ?? '', $this->ctxKey, $no, $date, $type, $sourceId, 1, 'ZAR', implode(',', $hashLines)]));
        $at = $date.' 12:00:00';
        $this->buf['journal_entries'][] = [
            'id' => $id, 'user_id' => null, 'team_id' => $this->teamId, 'entry_number' => $no, 'entry_date' => $date, 'period_id' => $this->periods[substr($date, 0, 7)],
            'status' => 'posted', 'source_type' => $type, 'source_id' => $sourceId, 'source_version' => 1, 'memo' => mb_substr($memo, 0, 500), 'currency' => 'ZAR',
            'total_cents' => $total, 'posted_by_user_id' => $this->userId, 'posted_at' => $at, 'posting_rule_version' => 1, 'prev_hash' => $this->prevHash, 'entry_hash' => $hash,
        ];
        $this->prevHash = $hash;
        foreach ($lines as $i => $l) {
            $this->buf['journal_lines'][] = [
                'id' => $this->lineId++, 'user_id' => null, 'team_id' => $this->teamId, 'journal_entry_id' => $id, 'line_no' => $i + 1, 'entry_date' => $date, 'gl_account_id' => $l[0],
                'debit_cents' => $l[1], 'credit_cents' => $l[2], 'party_id' => $l[5], 'cash_account_id' => $l[3], 'category_id' => $l[4], 'tax_code_id' => $l[6],
                'description' => $l[7] !== null ? mb_substr($l[7], 0, 255) : null, 'created_at' => $at,
            ];
        }
        $this->bufEntries++;
        $this->counts['entries'] = ($this->counts['entries'] ?? 0) + 1;
        $this->counts['lines'] = ($this->counts['lines'] ?? 0) + count($lines);

        return $id;
    }

    private function audit(string $date, string $action, string $subject, int $id, string $label): void
    {
        $this->buf['finance_audit_log'][] = [
            'user_id' => null, 'team_id' => $this->teamId, 'action' => $action, 'actor' => 'user', 'subject_type' => $subject, 'subject_id' => $id, 'subject_label' => $label,
            'currency' => 'ZAR', 'changes' => '{}', 'created_at' => $date.' 12:00:00', 'actor_user_id' => $this->userId,
        ];
    }

    private function flush(): void
    {
        if ($this->buf === []) {
            return;
        }
        DB::transaction(function () {
            // parents before children (foreign keys); each table's rows share one shape, so a multi-row insert is valid
            foreach (['transactions', 'journal_entries', 'journal_lines', 'sales_documents', 'sales_document_lines', 'bills', 'bill_lines', 'payments', 'payment_allocations', 'sales_applications', 'finance_audit_log'] as $table) {
                $rows = $this->buf[$table] ?? [];
                if ($rows === []) {
                    continue;
                }
                $size = max(50, intdiv(30000, count($rows[0])));
                foreach (array_chunk($rows, $size) as $chunk) {
                    DB::table($table)->insert($this->normalise($chunk));
                }
                if ($table === 'sales_document_lines') {
                    // an issued document is immutable, lines included: seal after the lines are in
                    $ids = array_column($this->buf['sales_documents'], 'id');
                    DB::table('sales_documents')->whereBetween('id', [min($ids), max($ids)])->whereNull('sealed_at')->update(['sealed_at' => DB::raw('issued_at'), 'status' => DB::raw('internal_note'), 'internal_note' => null, 'number' => DB::raw('reference'), 'reference' => null]);
                }
            }
        });
        $this->buf = [];
        $this->bufEntries = 0;
    }

    /** Multi-row inserts need every row to name the same columns: fill the gaps with null. */
    private function normalise(array $rows): array
    {
        $columns = [];
        foreach ($rows as $row) {
            $columns += array_fill_keys(array_keys($row), null);
        }

        return array_map(fn ($row) => array_replace($columns, $row), $rows);
    }

    /** Four 5,000-line bank statements: most lines mirror a cash-ledger row (so they can be matched), 10 per cent have no entry. */
    public function bankStatements(): array
    {
        $out = [];
        if (! isset($this->teamId)) {
            $team = DB::table('teams')->where('name', $this->company)->first();
            $this->teamId = (int) $team->id;
            $this->userId = (int) $team->user_id;
        }
        $windows = [[1, '2026-08-01'], [1, '2026-06-01'], [2, '2026-03-01'], [4, '2026-01-01']];
        foreach ($windows as $i => [$account, $from]) {
            $rows = DB::table('transactions')->where('team_id', $this->teamId)->where('account_id', $account)->whereNull('settlement_ref')->where('date', '>=', $from)
                ->orderBy('date')->orderBy('id')->limit(4500)->get(['id', 'description', 'amount_cents', 'type', 'date']);
            $statementId = (int) DB::table('bank_statements')->max('id') + 1;
            $lines = [];
            $n = 0;
            $in = 0;
            $out_ = 0;
            $lastDate = $from;
            foreach ($rows as $r) {
                $n++;
                $cents = $r->type === 'income' ? (int) $r->amount_cents : -(int) $r->amount_cents;
                $cents > 0 ? $in += $cents : $out_ += -$cents;
                $lastDate = (string) $r->date;
                $lines[] = $this->bankLine($statementId, $account, $n, $lastDate, $cents, strtoupper((string) $r->description), $i);
                if ($n % 9 === 0) { // an unexplained line after every ninth: 500 in all
                    $n++;
                    $x = -mt_rand(1500, 90000);
                    $out_ += -$x;
                    $lines[] = $this->bankLine($statementId, $account, $n, $lastDate, $x, 'UNEXPLAINED DEBIT ORDER '.mt_rand(10000, 99999), $i);
                }
            }
            DB::table('bank_statements')->insert([
                'id' => $statementId, 'user_id' => null, 'team_id' => $this->teamId, 'account_id' => $account, 'source' => 'csv', 'original_name' => "perf-{$i}.csv",
                'file_sha256' => hash('sha256', "perf-statement-{$i}"), 'currency' => 'ZAR', 'date_from' => $from, 'date_to' => $lastDate, 'balances_foot' => 't', 'line_count' => count($lines),
                'duplicate_count' => 0, 'in_cents' => $in, 'out_cents' => $out_, 'imported_by' => $this->userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (array_chunk($lines, 1500) as $chunk) {
                DB::table('bank_statement_lines')->insert($chunk);
            }
            $out[] = ['statement' => $statementId, 'account' => $account, 'lines' => count($lines), 'from' => $from, 'to' => $lastDate];
        }
        DB::statement("SELECT setval(pg_get_serial_sequence('bank_statements','id'), (SELECT MAX(id) FROM bank_statements))");
        DB::statement("SELECT setval(pg_get_serial_sequence('bank_statement_lines','id'), (SELECT MAX(id) FROM bank_statement_lines))");

        return $out;
    }

    /** A VAT profile for the busy company, twelve payroll months captured through the real service (eleven posted), and the tax calendar. */
    public function tax(): array
    {
        $team = \App\Models\Team::query()->where('name', $this->company)->firstOrFail();
        $user = \App\Models\User::query()->findOrFail($team->user_id);
        $ctx = LedgerContext::company($team);
        $today = CarbonImmutable::parse(self::END);

        return LedgerContext::run($ctx, function () use ($ctx, $user, $today) {
            $profile = app(\App\Services\Tax\TaxProfiles::class)->save($ctx, [
                'jurisdiction' => 'ZA', 'legal_entity' => 'company', 'tax_regime' => 'standard', 'vat_registered' => true, 'vat_number' => '4410298763',
                'vat_category' => 'B', 'vat_basis' => 'invoice', 'vat_registered_on' => '2025-11-01', 'vat_filing_channel' => 'efiling',
                'income_tax_ref' => '9000000001', 'paye_ref' => '7000000001', 'sdl_ref' => 'L7000000001', 'uif_ref' => 'U7000000001',
                'financial_year_end_month' => 2, 'provisional_taxpayer' => true, 'accounting_basis' => 'accrual', 'employs_staff' => true,
            ], $user);
            $runs = app(\App\Services\Tax\PayrollRuns::class);
            $journal = app(\App\Services\Tax\PayrollJournal::class);
            $posted = 0;
            for ($m = 11; $m >= 0; $m--) {
                $month = $today->startOfMonth()->subMonths($m);
                $gross = 450_000_00 + $m * 3_000_00;
                $uif = 25 * 17_712;
                $run = $runs->capture($ctx, $month, [
                    'gross_pay_cents' => $gross, 'paye_cents' => intdiv($gross * 21, 100), 'uif_employee_cents' => $uif, 'uif_employer_cents' => $uif,
                    'sdl_cents' => intdiv($gross, 100), 'eti_claimed_cents' => 0, 'employee_count' => 25, 'notes' => 'Perf fixture.',
                ], $user);
                if ($m >= 1) {
                    $journal->post($ctx, \App\Models\Tax\TaxPayrollRun::query()->forContext($ctx)->find($run->id), $user);
                    $posted++;
                }
            }
            $r = app(\App\Services\Tax\DeadlineGenerator::class)->generate($ctx, $profile, $today);

            return ['payroll_runs' => 12, 'posted' => $posted, 'deadlines' => $r['created']];
        });
    }

    private function bankLine(int $statementId, int $account, int $n, string $date, int $cents, string $desc, int $salt): array
    {
        return [
            'user_id' => null, 'team_id' => $this->teamId, 'bank_statement_id' => $statementId, 'account_id' => $account, 'line_no' => $n, 'posted_on' => $date, 'amount_cents' => $cents,
            'currency' => 'ZAR', 'description' => mb_substr($desc, 0, 250), 'fingerprint' => hash('sha256', "perf|{$salt}|{$n}|{$date}|{$cents}"), 'created_at' => now(),
        ];
    }

    private function finish(): void
    {
        foreach (['transactions', 'journal_entries', 'journal_lines', 'sales_documents', 'sales_document_lines', 'bills', 'bill_lines', 'payments', 'payment_allocations', 'sales_applications', 'finance_audit_log'] as $table) {
            DB::statement("SELECT setval(pg_get_serial_sequence('{$table}','id'), (SELECT COALESCE(MAX(id), 1) FROM {$table}))");
        }
        DB::table('document_sequences')->where('team_id', $this->teamId)->where('doc_type', 'invoice')->update(['next_number' => $this->invNo]);
        DB::statement('ANALYZE');
    }
}
