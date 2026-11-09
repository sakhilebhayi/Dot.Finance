<?php

namespace App\Console\Commands\Purchases;

use App\Console\Commands\DemoSeed;
use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Purchases\Bill;
use App\Models\Purchases\PurchaseSetting;
use App\Models\Purchases\RecurringBill;
use App\Models\Shared\Party;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\ChartTemplates;
use App\Services\Accounting\GlBackfill;
use App\Services\Accounting\GlSync;
use App\Services\Purchases\BillWorkflow;
use App\Services\Purchases\ClaimWorkflow;
use App\Services\Purchases\PaymentRunService;
use App\Services\Purchases\ReceiptCapture;
use App\Services\Purchases\SupplierBankDetails;
use App\Services\Purchases\SupplierPayments;
use App\Services\Shared\FxService;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * LOCAL DEMO DATA ONLY. Adds fictional suppliers, bills, payments, a credit note, an expense claim, a recurring bill, a receipt
 * waiting in the inbox and a draft payment run to the "Karoo Roasters" demo company made by finance:demo-seed. Everything goes
 * through the real services (so every entry balances and the payables ledger ties out); nothing here is a real business.
 *
 * It turns accounting on for the demo company, which needs one change to the demo data: the foreign-currency account is emptied (opening
 * balance zero, its demo transactions removed), because nothing in a foreign currency can be journalled until an exchange-rate provider is bound.
 * Refuses outside local/testing. Run it once after finance:demo-seed (add --fresh to that command first to start again).
 */
class DemoSeedPurchases extends Command
{
    protected $signature = 'finance:demo-seed-purchases';

    protected $description = 'Local only: add suppliers, bills, payments, a claim and a payment run to the demo company';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('finance:demo-seed-purchases only runs in a local or testing environment.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', DemoSeed::EMAIL)->first();
        $company = $user ? Team::query()->where('user_id', $user->id)->where('name', DemoSeed::COMPANY)->first() : null;
        if (! $user || ! $company) {
            $this->error('Run finance:demo-seed first.');

            return self::FAILURE;
        }

        $ctx = LedgerContext::company($company);

        if (LedgerContext::run($ctx, fn () => Party::query()->where('name', 'Cape Green Importers')->exists())) {
            $this->error('The purchases demo data is already there.');

            return self::FAILURE;
        }

        // the South African tax rules are data; the seeder is idempotent (production runs it as an owner step)
        $this->callSilently('db:seed', ['--class' => \Database\Seeders\TaxRulesSeeder::class, '--force' => true]);

        mt_srand(4402);
        DB::transaction(fn () => LedgerContext::run($ctx, fn () => $this->build($ctx, $user)));
        $this->info('Purchases demo data created for '.DemoSeed::COMPANY.'. Open Purchases in the sidebar.');

        return self::SUCCESS;
    }

    private function build(LedgerContext $ctx, User $user): void
    {
        $today = CarbonImmutable::today();

        // accounting on (nothing in a foreign currency can be journalled until an exchange-rate provider is bound to the posting
        // layer, so the demo's dollar account is emptied: it opens at zero and its few demo transactions are removed)
        $dollarAccounts = Account::query()->where('currency', 'USD')->pluck('id');
        Transaction::query()->whereIn('account_id', $dollarAccounts)->forceDelete();
        Account::query()->whereIn('id', $dollarAccounts)->update(['opening_balance_cents' => 0]);
        $backfill = app(GlBackfill::class);
        $backfill->prepare($ctx, 'ZAR', 1, ChartTemplates::COMPANY);
        $backfill->apply($ctx);
        $backfill->enable($ctx);
        app(GlSync::class)->flush();

        PurchaseSetting::query()->create($ctx->ownerAttributes() + ['vat_registered' => true, 'vat_registered_set_by' => $user->id, 'vat_registered_set_at' => now()]);
        app(FxService::class)->record('USD', 'ZAR', 18_4500_0000, $today->subDays(40)->toDateString(), 'manual', $user, 'demo rate', false, $ctx);
        app(FxService::class)->record('USD', 'ZAR', 18_7200_0000, $today->subDays(5)->toDateString(), 'manual', $user, 'demo rate', false, $ctx);

        $gl = fn (string $code) => GlAccount::query()->where('code', $code)->firstOrFail()->id;
        $party = fn (string $name, string $vat, int $terms, array $extra = []) => Party::query()->create($ctx->ownerAttributes() + $extra + ['type' => 'supplier', 'name' => $name, 'tax_number' => $vat ?: null, 'payment_terms_days' => $terms, 'status' => 'active']);

        $capeGreen = $party('Cape Green Importers', '4100000011', 30, ['currency' => 'USD']);
        $kasi = $party('Kasi Packaging Supplies', '4100000022', 30);
        $mountain = $party('Mountain Property Management', '4100000033', 14);
        $volt = $party('Voltage Utilities', '', 30);

        $bills = app(BillWorkflow::class);
        $bank = app(SupplierBankDetails::class);
        $bank->request($ctx, $user, $capeGreen, ['bank_name' => 'Wise', 'account_holder' => 'Cape Green Importers', 'account_number' => '70010020030', 'account_type' => 'Business'], 'Details taken from the signed supplier agreement and confirmed by call-back.', true);
        $bank->request($ctx, $user, $kasi, ['bank_name' => 'FNB', 'account_holder' => 'Kasi Packaging Supplies (Pty) Ltd', 'account_number' => '62001122334', 'branch_code' => '250655', 'account_type' => 'Cheque'], 'Entered from their invoice; call-back done.', true);
        $bank->request($ctx, $user, $mountain, ['bank_name' => 'Nedbank', 'account_holder' => 'Mountain Property Management', 'account_number' => '1007788990', 'branch_code' => '198765'], 'From the lease; confirmed with the agent.', true);

        $line = fn (string $desc, string $code, int $cents, string $tax = 'ZA_VAT_STD', string $qty = '1') => ['description' => $desc, 'gl_account_id' => $gl($code), 'quantity' => $qty, 'unit_price_cents' => $cents, 'tax_code' => $tax];
        $bill = fn (Party $p, string $ref, int $daysAgo, array $lines, array $extra = []) => $bills->saveDraft($ctx, $user, $extra + [
            'party_id' => $p->id, 'bill_date' => $today->subDays($daysAgo)->toDateString(), 'supplier_reference' => $ref, 'document_type' => 'tax_invoice', 'lines' => $lines,
        ]);
        $post = fn (Bill $b) => $bills->submit($user, $b);

        // rent, paid
        $paidRent = $post($bill($mountain, 'MPM-2310', 55, [$line('Roastery unit rent', '6100', 1_850_000)]));
        // packaging: one overdue, one current, a credit note on the overdue one
        $overdue = $post($bill($kasi, 'KP-88213', 62, [$line('Kraft bags 250g, carton of 500', '6190', 410_000, qty: '4')]));
        $post($bill($kasi, 'KP-88540', 9, [$line('Kraft bags 1kg, carton of 200', '6190', 320_000, qty: '3')]));
        $post($bills->saveDraft($ctx, $user, [
            'kind' => 'credit_note', 'party_id' => $kasi->id, 'credit_for_bill_id' => $overdue->id, 'bill_date' => $today->subDays(20)->toDateString(), 'supplier_reference' => 'KP-CN-114',
            'credit_reason' => 'One carton arrived damaged', 'document_type' => 'credit_note', 'lines' => [$line('Damaged carton credited', '6190', 410_000)],
        ]));
        // a dollar bill, part paid from the dollar account at a different rate
        $usd = $post($bill($capeGreen, 'CGI-5521', 38, [$line('Green coffee, Ethiopia Guji, 5 x 60kg', '5000', 640_000, 'ZA_VAT_ZERO')], ['currency' => 'USD']));
        // an expense with no valid tax invoice (VAT not claimed) and one entertainment bill (VAT denied)
        $post($bill($volt, 'VU-77001', 25, [$line('Electricity, roastery', '6110', 612_000)], ['document_type' => 'unknown']));
        $post($bill($kasi, 'KP-88601', 4, [$line('Staff end-of-year lunch', '6190', 285_000, 'ZA_VAT_DENIED_ENTERTAINMENT')]));
        // drafts waiting to be sent
        $bill($mountain, 'MPM-2311', 1, [$line('Roastery unit rent', '6100', 1_850_000)]);

        // pay the rent in full, the overdue bill in part, the dollar bill in part from the dollar account
        $pay = app(SupplierPayments::class);
        $cheque = Account::query()->where('name', 'Business Cheque')->firstOrFail();
        $dollars = Account::query()->where('currency', 'USD')->firstOrFail();
        $pay->pay($ctx, $user, ['party_id' => $mountain->id, 'cash_account_id' => $cheque->id, 'paid_on' => $today->subDays(50)->toDateString(), 'reference' => 'RENT-OCT', 'idempotency_key' => 'demo-pay-1', 'allocations' => [['type' => 'bill', 'id' => $paidRent->id]]]);
        $pay->pay($ctx, $user, ['party_id' => $kasi->id, 'cash_account_id' => $cheque->id, 'paid_on' => $today->subDays(15)->toDateString(), 'reference' => 'KP part', 'idempotency_key' => 'demo-pay-2', 'allocations' => [['type' => 'bill', 'id' => $overdue->id, 'amount_cents' => 700_000]]]);
        $pay->pay($ctx, $user, ['party_id' => $capeGreen->id, 'cash_account_id' => $dollars->id, 'paid_on' => $today->subDays(5)->toDateString(), 'reference' => 'CGI deposit', 'idempotency_key' => 'demo-pay-3', 'allocations' => [['type' => 'bill', 'id' => $usd->id, 'amount_cents' => 300_000]]]);

        // an expense claim, posted
        $claims = app(ClaimWorkflow::class);
        $claim = $claims->saveDraft($ctx, $user, [
            'title' => 'Wholesale deliveries, Garden Route', 'claim_date' => $today->subDays(12)->toDateString(), 'claimant_user_id' => $user->id,
            'lines' => [
                ['spent_on' => $today->subDays(13)->toDateString(), 'merchant' => 'Engen Plettenberg Bay', 'description' => 'Fuel for delivery run', 'receipt_type' => 'tax_invoice', 'merchant_vat_number' => '4880000012', 'gl_account_id' => $gl('6170'), 'tax_code' => 'ZA_VAT_STD', 'gross_cents' => 184_000],
                ['spent_on' => $today->subDays(13)->toDateString(), 'merchant' => 'Roadside fruit stall', 'description' => 'Snacks for the drivers', 'receipt_type' => 'none', 'gl_account_id' => $gl('6190'), 'tax_code' => 'ZA_VAT_STD', 'gross_cents' => 6_500],
            ],
        ]);
        $claims->submit($user, $claim);

        // a recurring bill: next draft is due soon
        RecurringBill::query()->create($ctx->ownerAttributes() + [
            'party_id' => $mountain->id, 'name' => 'Roastery unit rent', 'frequency' => 'monthly', 'interval_count' => 1, 'start_on' => $today->subMonths(3)->toDateString(),
            'next_run_on' => $today->addDays(4)->toDateString(), 'status' => 'active', 'days_until_due' => 14, 'currency' => 'ZAR', 'document_type' => 'tax_invoice', 'created_by' => $user->id,
            'template' => [$line('Roastery unit rent', '6100', 1_850_000)],
        ]);

        // a supplier invoice waiting in the receipts inbox (a text PDF, read by the rules reader)
        $path = tempnam(sys_get_temp_dir(), 'fxdemo');
        file_put_contents($path, $this->invoicePdf($today->subDays(2)));
        app(ReceiptCapture::class)->capture($ctx, $user, new UploadedFile($path, 'voltage-utilities-water.pdf', null, null, true));

        // a payment run for what falls due next week, not yet paid
        app(PaymentRunService::class)->create($ctx, $user, [
            'name' => 'Friday run', 'due_by' => $today->addDays(7)->toDateString(), 'paid_on' => $today->addDays(3)->toDateString(), 'cash_account_id' => $cheque->id, 'idempotency_key' => 'demo-run-1',
            'selection' => $this->dueItems($ctx, $today->addDays(7)),
        ]);
    }

    /** @return list<array{type: string, id: int}> */
    private function dueItems(LedgerContext $ctx, CarbonImmutable $dueBy): array
    {
        $items = [];
        foreach (app(PaymentRunService::class)->candidates($ctx, $dueBy, 'ZAR') as $group) {
            if ($group['held'] === null) {
                foreach ($group['items'] as $item) {
                    $items[] = ['type' => $item['type'], 'id' => $item['id']];
                }
            }
        }

        return $items;
    }

    private function invoicePdf(CarbonImmutable $on): string
    {
        $lines = ['Voltage Utilities (Pty) Ltd', 'TAX INVOICE', 'VAT Reg No: 4100000044', 'Invoice No: WA-20931', 'Date: '.$on->format('d/m/Y'), 'Due date: '.$on->addDays(30)->format('d/m/Y'),
            'Water and sanitation, roastery unit', 'Subtotal R 1 480.00', 'VAT 15% R 222.00', 'Total (incl. VAT) R 1 702.00'];
        $content = "BT\n/F1 12 Tf\n72 780 Td\n14 TL\n";
        foreach ($lines as $i => $l) {
            $content .= ($i === 0 ? '' : "T*\n").'('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $l).") Tj\n";
        }
        $content .= "ET\n";

        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
            ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n"
            .'4 0 obj\n<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream\nendobj\n5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\ntrailer\n<< /Root 1 0 R /Size 6 >>\n%%EOF\n";
    }
}
