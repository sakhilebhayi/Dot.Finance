<?php

namespace App\Console\Commands\Sales;

use App\Console\Commands\DemoSeed;
use App\Models\Account;
use App\Models\Accounting\LedgerSetting;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesRecurringTemplate;
use App\Models\Shared\Party;
use App\Models\Team;
use App\Models\User;
use App\Services\Accounting\ChartTemplates;
use App\Services\Accounting\GlBackfill;
use App\Services\Sales\DocumentDrafts;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\DocumentLifecycle;
use App\Services\Sales\ReceiptService;
use App\Services\Sales\SalesProfiles;
use App\Services\Sales\WriteOffs;
use App\Services\Shared\FxMath;
use App\Services\Shared\FxService;
use App\Support\DemoRates;
use App\Support\Ledger\LedgerContext;
use Database\Seeders\TaxRulesSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Local only. Adds a believable sales history to the demo company made by finance:demo-seed: customers, a business
 * profile, invoices issued and paid through the real services (so every posting, number and tie-out is the real thing),
 * an overdue invoice, a part payment, an unapplied payment, a credit note, a write-off, a foreign-currency invoice settled at
 * a different rate, a quote, a pro forma, a draft and a repeating template. Nothing is a real business or person.
 *
 * Deterministic: the same dates relative to today, the same amounts, every run. Refuses outside local and testing.
 */
#[Signature('finance:demo-seed-sales')]
#[Description('Local only: add a sales history (customers, invoices, payments, a credit note ...) to the demo company')]
class DemoSeedSales extends Command
{
    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('finance:demo-seed-sales only runs in a local or testing environment.');

            return self::FAILURE;
        }

        $company = Team::query()->where('name', DemoSeed::COMPANY)->first();
        $user = User::query()->where('email', DemoSeed::EMAIL)->first();
        if ($company === null || $user === null) {
            $this->error('Run finance:demo-seed first: this adds to the demo company it makes.');

            return self::FAILURE;
        }

        $ctx = LedgerContext::company($company);
        $made = LedgerContext::run($ctx, function () use ($ctx, $user) {
            if (SalesDocument::query()->exists()) {
                return null;   // already done
            }

            (new TaxRulesSeeder)->run();
            DemoRates::ensure($ctx);
            $settings = LedgerSetting::query()->forContext($ctx)->first();
            if ($settings === null || ! $settings->isEnabled()) {
                $backfill = app(GlBackfill::class);
                $backfill->prepare($ctx, 'ZAR', 1, ChartTemplates::COMPANY);
                $backfill->apply($ctx);
                $backfill->enable($ctx);
            }

            return DB::transaction(fn () => $this->build($ctx, $user));
        });

        $this->info($made === null ? 'The demo company already has sales.' : "Sales added: {$made} documents.");

        return self::SUCCESS;
    }

    private function build(LedgerContext $ctx, User $user): int
    {
        $today = now()->startOfDay();
        $day = fn (int $ago) => $today->copy()->subDays($ago)->toDateString();

        app(SalesProfiles::class)->save($ctx, [
            'legal_name' => 'Karoo Roasters (Pty) Ltd', 'trading_name' => 'Karoo Roasters', 'vat_registered' => true, 'vat_number' => '4410298763', 'registration_number' => '2019/412233/07',
            'address' => ['line1' => '14 Albert Road', 'suburb' => 'Woodstock', 'city' => 'Cape Town', 'province' => 'Western Cape', 'postal_code' => '7925', 'country' => 'South Africa'],
            'email' => 'accounts@karooroasters.example', 'phone' => '021 555 0142', 'bank_name' => 'FNB Business', 'bank_account_holder' => 'Karoo Roasters (Pty) Ltd',
            'bank_account_number' => '62000123456', 'bank_branch_code' => '250655', 'bank_account_type' => 'Cheque', 'brand_color' => '#5b3a1e', 'default_terms_days' => 30,
            'invoice_footer' => 'Karoo Roasters (Pty) Ltd · Registration 2019/412233/07 · VAT 4410298763', 'reminders_enabled' => true, 'reminder_before_days' => 3, 'reminder_after_days' => [7, 14, 30],
        ], $user->id);

        $customer = fn (string $name, string $legal, string $vat, string $email, string $line1, string $city, int $terms, ?int $limit = null, string $currency = 'ZAR', string $country = 'South Africa') => Party::create($ctx->ownerAttributes() + [
            'type' => 'customer', 'name' => $name, 'legal_name' => $legal, 'tax_number' => $vat, 'email' => $email, 'currency' => $currency, 'payment_terms_days' => $terms, 'credit_limit_cents' => $limit,
            'billing_address' => ['line1' => $line1, 'city' => $city, 'country' => $country], 'status' => 'active',
        ]);
        $pantry = $customer('Gardens Pantry', 'Gardens Pantry (Pty) Ltd', '4220198811', 'orders@gardenspantry.example', '22 Kloof Street', 'Cape Town', 30, 5_000_000);
        $deli = $customer('Woodstock Deli', 'Woodstock Deli CC', '4330277145', 'hello@woodstockdeli.example', '3 Sutherland Road', 'Cape Town', 30, 1_500_000);
        $hotel = $customer('Harbour Hotel Group', 'Harbour Hospitality Holdings (Pty) Ltd', '4550366290', 'payables@harbourhotels.example', '1 Quay Lane', 'Cape Town', 30, 4_000_000);
        $brewer = $customer('Brewer and Co', 'Brewer and Co Ltd', 'GB 321 4455 78', 'buying@brewerandco.example', '9 Mill Yard', 'Bristol', 30, null, 'USD', 'United Kingdom');
        $cafe = $customer('Sunbird Cafe', 'Sunbird Cafe (Pty) Ltd', '4770455512', 'sunbirdcafe@example.test', '40 Main Road', 'Paarl', 14);

        $fx = app(FxService::class);
        $fx->record('USD', 'ZAR', FxMath::parseRate('18.42'), $day(40), 'manual', $user, 'Demo rate', context: $ctx);
        $fx->record('USD', 'ZAR', FxMath::parseRate('18.05'), $day(12), 'manual', $user, 'Demo rate', context: $ctx);

        $drafts = app(DocumentDrafts::class);
        $issuer = app(DocumentIssuer::class);
        $lifecycle = app(DocumentLifecycle::class);
        $receipts = app(ReceiptService::class);
        $cheque = Account::query()->forContext($ctx)->where('name', 'Business Cheque')->firstOrFail();
        $usd = Account::query()->forContext($ctx)->where('name', 'Supplier USD')->firstOrFail();

        $line = fn (string $d, string $q, int $unit, string $code = 'ZA_VAT_STD', string $unitName = 'kg') => ['description' => $d, 'quantity' => $q, 'unit_cents' => $unit, 'tax_code' => $code, 'unit' => $unitName];
        $n = 0;
        $invoice = function (Party $to, int $issueDaysAgo, array $lines, array $more = []) use (&$n, $ctx, $user, $drafts, $issuer, $day) {
            $n++;
            $draft = $drafts->create($ctx, $user, $more + ['kind' => 'tax_invoice', 'party_id' => $to->id, 'currency' => 'ZAR', 'issue_date' => $day($issueDaysAgo)], $lines);

            return $issuer->issue($draft, $user, ['acknowledge_credit_limit' => true])['document'];
        };
        $pay = function (Party $from, int $daysAgo, int $cents, array $allocations, string $reference, ?Account $into = null, ?string $rate = null) use ($ctx, $user, $receipts, $cheque, $day) {
            return $receipts->receive($ctx, $user, [
                'party_id' => $from->id, 'cash_account_id' => ($into ?? $cheque)->id, 'amount_cents' => $cents, 'paid_on' => $day($daysAgo), 'reference' => $reference,
                'idempotency_key' => 'demo-'.$reference, 'allocations' => $allocations, 'fx_rate' => $rate,
            ]);
        };

        // Gardens Pantry: a standing monthly wholesale order, three issued, two paid on time
        $p1 = $invoice($pantry, 95, [$line('Karoo Blend, 1 kg bags', '60', 21_500), $line('Ethiopia Yirgacheffe, 1 kg bags', '24', 28_900)]);
        $pay($pantry, 70, (int) $p1->total_cents, [['document_id' => $p1->id, 'amount_cents' => (int) $p1->total_cents]], 'FNB-4471');
        $p2 = $invoice($pantry, 62, [$line('Karoo Blend, 1 kg bags', '60', 21_500), $line('Ethiopia Yirgacheffe, 1 kg bags', '30', 28_900)]);
        $pay($pantry, 38, (int) $p2->total_cents, [['document_id' => $p2->id, 'amount_cents' => (int) $p2->total_cents]], 'FNB-4519');
        $p3 = $invoice($pantry, 31, [$line('Karoo Blend, 1 kg bags', '72', 21_500), $line('Delivery', '1', 45_000, 'ZA_VAT_STD', 'trip')]);

        // Woodstock Deli: a part payment, and a credit note for bags returned
        $d1 = $invoice($deli, 58, [$line('Espresso Roast, 1 kg bags', '18', 24_900), $line('Filter Roast, 250 g bags', '40', 6_800, 'ZA_VAT_STD', 'bag')]);
        $pay($deli, 33, 400_000, [['document_id' => $d1->id, 'amount_cents' => 400_000]], 'EFT deli part');
        $filter = $d1->lines()->where('description', 'like', 'Filter Roast%')->firstOrFail();
        $credit = $drafts->create($ctx, $user, ['kind' => 'credit_note', 'party_id' => $deli->id, 'parent_id' => $d1->id, 'issue_date' => $day(30), 'reason' => 'Six bags of Filter Roast returned: stale on arrival'],
            [['description' => 'Returned: Filter Roast, 250 g bags', 'quantity' => '6', 'unit_cents' => 6_800, 'unit' => 'bag', 'parent_line_id' => $filter->id]]);
        $issuer->issue($credit, $user);

        // Harbour Hotel: one long overdue, one overdue, one not yet due. Over its credit limit on the last (acknowledged).
        $h1 = $invoice($hotel, 140, [$line('House Blend, 5 kg catering bags', '20', 92_000), $line('Barista training session', '1', 350_000, 'ZA_VAT_STD', 'session')]);
        $h2 = $invoice($hotel, 74, [$line('House Blend, 5 kg catering bags', '24', 92_000)]);
        $h3 = $invoice($hotel, 9, [$line('House Blend, 5 kg catering bags', '36', 92_000)], ['due_date' => $day(-21)]);
        unset($h1, $h2, $h3);

        // Sunbird Cafe: paid more than the invoice (an unapplied credit stays on account); a write-off of a small short-payment
        $s1 = $invoice($cafe, 26, [$line('Karoo Blend, 1 kg bags', '8', 21_500)]);
        $pay($cafe, 8, 250_000, [['document_id' => $s1->id, 'amount_cents' => (int) $s1->total_cents]], 'Sunbird EFT');
        $s2 = $invoice($cafe, 50, [$line('Espresso Roast, 1 kg bags', '3', 24_900)]);
        $pay($cafe, 30, 80_000, [['document_id' => $s2->id, 'amount_cents' => 80_000]], 'Sunbird short');
        app(WriteOffs::class)->request($s2, (int) $s2->total_cents - 80_000, 'Short payment: not worth chasing', $user);

        // Brewer and Co: a USD export invoice booked at 18.42 and paid at 18.05, so a realised loss
        $b = $drafts->create($ctx, $user, ['kind' => 'tax_invoice', 'party_id' => $brewer->id, 'currency' => 'USD', 'issue_date' => $day(40), 'reference' => 'PO BR-2290'],
            [$line('Karoo Blend, 12 x 1 kg cartons (export)', '5', 21_000, 'ZA_VAT_ZERO_EXPORT', 'carton')]);
        $b = $issuer->issue($b, $user)['document'];
        $pay($brewer, 12, (int) $b->total_cents, [['document_id' => $b->id, 'amount_cents' => (int) $b->total_cents]], 'Wise-7781', $usd);

        // A quote that has been sent, a pro forma, a draft and a repeating invoice
        $quote = $drafts->create($ctx, $user, ['kind' => 'quote', 'party_id' => $hotel->id, 'currency' => 'ZAR', 'issue_date' => $day(5)], [$line('Espresso machine service plan, 12 months', '1', 1_850_000, 'ZA_VAT_STD', 'plan')]);
        $issuer->issue($quote, $user);
        $pro = $drafts->create($ctx, $user, ['kind' => 'pro_forma', 'party_id' => $deli->id, 'currency' => 'ZAR', 'issue_date' => $day(2)], [$line('Espresso Roast, 1 kg bags', '30', 24_900)]);
        $issuer->issue($pro, $user);
        $drafts->create($ctx, $user, ['kind' => 'tax_invoice', 'party_id' => $cafe->id, 'currency' => 'ZAR', 'issue_date' => $day(0)], [$line('Karoo Blend, 1 kg bags', '10', 21_500)]);

        SalesRecurringTemplate::create($ctx->ownerAttributes() + [
            'party_id' => $pantry->id, 'name' => 'Gardens Pantry monthly order', 'frequency' => 'monthly', 'every' => 1, 'start_date' => $day(31), 'next_run_date' => $today->copy()->addDays(1)->toDateString(),
            'mode' => 'draft', 'currency' => 'ZAR', 'terms_days' => 30, 'reference' => 'Standing order', 'status' => 'active', 'created_by' => $user->id,
            'lines' => [$line('Karoo Blend, 1 kg bags', '72', 21_500)],
        ]);

        return SalesDocument::query()->forContext($ctx)->count();
    }
}
