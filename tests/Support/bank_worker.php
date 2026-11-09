<?php

/*
 * A worker process for BankConcurrencyTest. It boots the application against the database named in its environment
 * and acts on one reconciliation, so several of these running at once really do contend for the same rows.
 *
 *   php bank_worker.php setup <lines>
 *       creates a user, an account, ONE ledger entry of 10.00, <lines> statement lines of 10.00 each and an open session;
 *       prints "userId accountId sessionId entryId firstLineId"
 *   php bank_worker.php match <userId> <sessionId> <entryId> <lineId> <holdMs>
 *       tries to match that line to that entry; prints OK or REFUSED:<reason>
 *   php bank_worker.php complete <userId> <sessionId> <holdMs>
 *       tries to complete the session; prints OK or REFUSED:<reason>
 */

use App\Exceptions\Bank\ReconciliationRefused;
use App\Models\Account;
use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankStatement;
use App\Models\Bank\BankStatementLine;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Bank\ReconciliationService;
use App\Services\Bank\StatementFingerprint;
use App\Support\Ledger\LedgerContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// SQLite only lets one writer in: take the write lock when a transaction begins and wait for it, which is the contention being tested.
config(['database.connections.sqlite.transaction_mode' => 'IMMEDIATE', 'database.connections.sqlite.busy_timeout' => 30000]);
DB::purge();

$mode = $argv[1] ?? '';

if ($mode === 'setup') {
    $count = (int) ($argv[2] ?? 4);
    $user = User::forceCreate(['name' => 'Bank worker', 'email' => 'bank-'.uniqid().'@example.test', 'password' => 'x']);
    $context = LedgerContext::personal($user);

    $ids = LedgerContext::run($context, function () use ($user, $count) {
        $account = Account::create(['user_id' => $user->id, 'name' => 'Race', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance_cents' => 0, 'opening_balance_date' => '2026-01-01']);
        $entry = Transaction::create(['user_id' => $user->id, 'account_id' => $account->id, 'description' => 'One entry', 'amount_cents' => 1000, 'currency' => 'ZAR', 'type' => 'income', 'date' => '2026-10-05']);
        $statement = BankStatement::create(['user_id' => $user->id, 'account_id' => $account->id, 'source' => 'csv', 'original_name' => 'race.csv', 'file_sha256' => hash('sha256', uniqid()), 'currency' => 'ZAR', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $first = null;
        for ($i = 1; $i <= $count; $i++) {
            $line = BankStatementLine::create(['user_id' => $user->id, 'bank_statement_id' => $statement->id, 'account_id' => $account->id, 'line_no' => $i, 'posted_on' => '2026-10-05', 'amount_cents' => 1000, 'currency' => 'ZAR', 'description' => "Line {$i}", 'fingerprint' => StatementFingerprint::make($account->id, '2026-10-05', 1000, "Line {$i}", 1)]);
            $first ??= $line->id;
        }
        $session = app(ReconciliationService::class)->start(User::findOrFail($user->id), $account, '2026-10-31', 1000);

        return [$account->id, $session->id, $entry->id, $first];
    });

    echo $user->id, ' ', implode(' ', $ids), "\n";
    exit(0);
}

$user = User::findOrFail((int) ($argv[2] ?? 0));
$context = LedgerContext::personal($user);
$service = app(ReconciliationService::class);

try {
    LedgerContext::run($context, function () use ($mode, $argv, $service, $user) {
        $session = BankReconciliation::findOrFail((int) $argv[3]);

        if ($mode === 'match') {
            $hold = (int) ($argv[6] ?? 0);
            DB::transaction(function () use ($service, $session, $argv, $user, $hold) {
                $service->matchRows($session, [(int) $argv[5]], [(int) $argv[4]], $user);
                if ($hold > 0) {
                    usleep($hold * 1000);
                }
            });
        } else {
            $hold = (int) ($argv[4] ?? 0);
            DB::transaction(function () use ($service, $session, $user, $hold) {
                $service->complete($session, $user);
                if ($hold > 0) {
                    usleep($hold * 1000);
                }
            });
        }
    });
    echo "OK\n";
} catch (ReconciliationRefused $e) {
    echo 'REFUSED:', $e->reason, "\n";
}
