<?php

/*
 * A worker process for SalesConcurrencyTest. It boots the application against the database named in its environment
 * and does real Sales work in its own transactions, so several of these running at once really contend for the same
 * numbering sequence, journal numbering and invoice rows.
 *
 *   setup                                   builds a company ledger (accounting on, VAT-registered, one customer, one bank account),
 *                                           24 drafts and one issued invoice of 1,150.00; prints JSON
 *   issue <team> <user> <ids,...>           issues those drafts; prints "number" per success
 *   pay <team> <user> <bank> <customer> <invoice> <count> <cents> <key prefix> [shared]
 *                                           records <count> payments of <cents> allocated to the invoice; prints ok/refused per attempt.
 *                                           With "shared" every attempt uses the SAME idempotency key.
 *   verify <team>                           prints JSON: invoice numbers, entry-number check, receivables vs ledger, books verify
 */

use App\Models\Account;
use App\Models\Accounting\JournalEntry;
use App\Models\Sales\SalesDocument;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Models\Team;
use App\Models\User;
use App\Services\Accounting\ChartTemplates;
use App\Services\Accounting\GlBackfill;
use App\Services\Accounting\GlVerifier;
use App\Services\Sales\DocumentDrafts;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\ReceiptService;
use App\Services\Sales\ReceivablesLedger;
use App\Services\Sales\SalesProfiles;
use App\Services\Sales\Support\SalesViolation;
use App\Support\Ledger\LedgerContext;
use Database\Seeders\TaxRulesSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$today = now()->toDateString();

if ($mode === 'setup') {
    (new TaxRulesSeeder)->run();
    $user = User::forceCreate(['name' => 'Sales worker', 'email' => 'sales-'.uniqid().'@example.test', 'password' => 'x']);
    $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Concurrent (Pty) Ltd', 'personal_team' => false, 'infodot_team_uuid' => (string) Str::uuid()]);
    $ctx = LedgerContext::company($team);
    $bank = Account::create(['team_id' => $team->id, 'name' => 'Current account', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance_cents' => 0]);

    LedgerContext::run($ctx, function () use ($ctx, $user, $bank, $team, $today) {
        $backfill = app(GlBackfill::class);
        $backfill->prepare($ctx, 'ZAR', 1, ChartTemplates::COMPANY);
        $backfill->apply($ctx);
        $backfill->enable($ctx);

        app(SalesProfiles::class)->save($ctx, ['legal_name' => 'Concurrent (Pty) Ltd', 'vat_registered' => true, 'vat_number' => '4123456789', 'address' => ['line1' => '1 Long St', 'city' => 'Cape Town']], $user->id);
        $customer = Party::create($ctx->ownerAttributes() + ['type' => 'customer', 'name' => 'Acme', 'status' => 'active', 'billing_address' => ['line1' => '5 Short Rd', 'city' => 'Durban']]);

        $drafts = app(DocumentDrafts::class);
        $header = ['kind' => 'tax_invoice', 'party_id' => $customer->id, 'currency' => 'ZAR', 'issue_date' => $today];
        $ids = [];
        for ($i = 0; $i < 24; $i++) {
            $ids[] = $drafts->create($ctx, $user, $header, [['description' => 'Work '.$i, 'quantity' => '1', 'unit_cents' => 1_000 + $i, 'tax_code' => 'ZA_VAT_STD']])->id;
        }
        $big = app(DocumentIssuer::class)->issue($drafts->create($ctx, $user, $header, [['description' => 'Big', 'quantity' => '1', 'unit_cents' => 100_000, 'tax_code' => 'ZA_VAT_STD']]), $user)['document'];

        echo json_encode(['team' => $team->id, 'user' => $user->id, 'bank' => $bank->id, 'customer' => $customer->id, 'drafts' => $ids, 'invoice' => $big->id, 'invoice_number' => $big->number]), "\n";
    });
    exit(0);
}

$ctx = LedgerContext::company((int) ($argv[2] ?? 0));

if ($mode === 'issue') {
    $user = User::findOrFail((int) $argv[3]);
    foreach (explode(',', $argv[4]) as $id) {
        LedgerContext::run($ctx, function () use ($ctx, $user, $id) {
            $draft = SalesDocument::query()->forContext($ctx)->findOrFail((int) $id);
            echo app(DocumentIssuer::class)->issue($draft, $user)['document']->number, "\n";
        });
    }
    exit(0);
}

if ($mode === 'pay') {
    [$userId, $bank, $customer, $invoice, $count, $cents, $prefix] = [(int) $argv[3], (int) $argv[4], (int) $argv[5], (int) $argv[6], (int) $argv[7], (int) $argv[8], $argv[9]];
    $shared = ($argv[10] ?? '') === 'shared';
    $user = User::findOrFail($userId);

    for ($i = 0; $i < $count; $i++) {
        try {
            LedgerContext::run($ctx, fn () => app(ReceiptService::class)->receive($ctx, $user, [
                'party_id' => $customer, 'cash_account_id' => $bank, 'amount_cents' => $cents, 'paid_on' => $today,
                'idempotency_key' => $shared ? $prefix : $prefix.'-'.getmypid().'-'.$i, 'allocations' => [['document_id' => $invoice, 'amount_cents' => $cents]],
            ]));
            echo "ok\n";
        } catch (SalesViolation $e) {
            echo 'refused:'.$e->reason."\n";
        }
    }
    exit(0);
}

if ($mode === 'verify') {
    $out = LedgerContext::run($ctx, function () use ($ctx) {
        $entries = JournalEntry::query()->forContext($ctx)->orderBy('entry_number')->pluck('entry_number')->map(fn ($n) => (int) $n)->all();
        $numbers = SalesDocument::query()->forContext($ctx)->whereNotNull('number')->where('kind', 'tax_invoice')->orderBy('id')->pluck('number')->all();
        $verify = app(GlVerifier::class)->verify($ctx);

        return [
            'invoice_numbers' => $numbers, 'entries_gap_free' => $entries === range(1, count($entries)), 'entry_count' => count($entries),
            'reconciles' => app(ReceivablesLedger::class)->reconcile($ctx)['ok'], 'payments' => Payment::query()->forContext($ctx)->count(),
            'verify_ok' => $verify['ok'], 'verify_failed' => collect($verify['checks'])->where('ok', false)->pluck('code')->all(),
        ];
    });
    echo json_encode($out), "\n";
    exit(0);
}
