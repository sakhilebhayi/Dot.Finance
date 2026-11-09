<?php

/*
 * A worker process for PurchasesConcurrencyTest. It boots the application against the database named in its environment.
 *
 *   php purchases_worker.php setup
 *       builds a company with accounting on, a funded bank account, a supplier and one posted bill of R1,150.00; prints JSON ids
 *   php purchases_worker.php pay <team_id> <user_id> <account_id> <party_id> <bill_id> <idempotency_key> <amount_cents> <hold_ms>
 *       pays the bill; prints "ok <payment_id>" or "refused <reason>"
 */

use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Purchases\PurchaseSetting;
use App\Models\Shared\Party;
use App\Models\Team;
use App\Models\User;
use App\Services\Accounting\ChartTemplates;
use App\Services\Accounting\GlBackfill;
use App\Services\Purchases\BillWorkflow;
use App\Services\Purchases\PurchasesException;
use App\Services\Purchases\SupplierPayments;
use App\Support\Ledger\LedgerContext;
use Database\Seeders\TaxRulesSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';

if ($mode === 'setup') {
    (new TaxRulesSeeder)->run();
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Concurrency (Pty) Ltd', 'personal_team' => false, 'infodot_team_uuid' => (string) Str::uuid()]);
    $ctx = LedgerContext::company($team);
    $account = Account::factory()->forCompany($team)->create(['name' => 'Cheque', 'currency' => 'ZAR', 'opening_balance_cents' => 50_000_000, 'opening_balance_date' => '2026-01-01']);

    LedgerContext::run($ctx, function () use ($ctx, $user, $team, $account) {
        $backfill = app(GlBackfill::class);
        $backfill->prepare($ctx, 'ZAR', 1, ChartTemplates::COMPANY);
        $backfill->apply($ctx);
        $backfill->enable($ctx);
        PurchaseSetting::query()->create($ctx->ownerAttributes() + ['vat_registered' => true]);
        $party = Party::factory()->forCompany($team)->supplier()->create(['tax_number' => '4123456789']);
        $expense = GlAccount::query()->where('code', '6190')->firstOrFail();
        $workflow = app(BillWorkflow::class);
        $bill = $workflow->submit($user, $workflow->saveDraft($ctx, $user, [
            'party_id' => $party->id, 'bill_date' => '2026-11-02', 'document_type' => 'tax_invoice', 'supplier_reference' => 'C-1',
            'lines' => [['description' => 'x', 'gl_account_id' => $expense->id, 'quantity' => '1', 'unit_price_cents' => 100_000, 'tax_code' => 'ZA_VAT_STD']],
        ]));
        echo json_encode(['team' => $team->id, 'user' => $user->id, 'account' => $account->id, 'party' => $party->id, 'bill' => $bill->id]), "\n";
    });
    exit(0);
}

if ($mode === 'pay') {
    [, , $teamId, $userId, $accountId, $partyId, $billId, $key, $amount, $hold] = $argv;
    $ctx = LedgerContext::company((int) $teamId);
    usleep(((int) $hold) * 1000);

    try {
        $payment = app(SupplierPayments::class)->pay($ctx, User::findOrFail((int) $userId), [
            'party_id' => (int) $partyId, 'cash_account_id' => (int) $accountId, 'paid_on' => '2026-11-09', 'idempotency_key' => $key,
            'allocations' => [['type' => 'bill', 'id' => (int) $billId, 'amount_cents' => (int) $amount]],
        ]);
        echo 'ok ', $payment->id, "\n";
    } catch (PurchasesException $e) {
        echo 'refused ', $e->reason, "\n";
    } catch (Throwable $e) {
        echo 'error ', get_class($e), ': ', $e->getMessage(), "\n";
    }
    exit(0);
}
