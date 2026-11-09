<?php

/*
 * A worker process for NumberingConcurrencyTest. It boots the application against the database named in its
 * environment and issues document numbers in separate transactions, so several of these running at once
 * really do contend for the same sequence row.
 *
 *   php numbering_worker.php setup
 *       creates a user and prints its id
 *   php numbering_worker.php issue <user_id> <how_many> <hold_milliseconds> <abort_every>
 *       issues numbers; every <abort_every>-th transaction (0 = never) is rolled back after taking a number.
 *       Prints one committed number per line, only after its transaction has committed.
 *   php numbering_worker.php peek <user_id>
 *       prints the number the sequence would hand out next
 */

use App\Services\Shared\Numbering;
use App\Support\Ledger\LedgerContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';

if ($mode === 'setup') {
    $id = DB::table('users')->insertGetId([
        'name' => 'Numbering worker', 'email' => 'numbering-'.uniqid().'@example.test', 'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    echo $id, "\n";
    exit(0);
}

$context = LedgerContext::personal((int) ($argv[2] ?? 0));
$numbering = app(Numbering::class);

if ($mode === 'peek') {
    echo $numbering->peek('invoice', null, $context), "\n";
    exit(0);
}

$count = (int) ($argv[3] ?? 1);
$holdMs = (int) ($argv[4] ?? 0);
$abortEvery = (int) ($argv[5] ?? 0);

for ($i = 1; $i <= $count; $i++) {
    try {
        $number = DB::transaction(function () use ($numbering, $context, $holdMs, $abortEvery, $i) {
            $issued = $numbering->issue('invoice', null, $context)['number'];

            if ($holdMs > 0) {
                usleep($holdMs * 1000);
            }
            if ($abortEvery > 0 && $i % $abortEvery === 0) {
                throw new RuntimeException('simulated failure after taking a number');
            }

            return $issued;
        });

        echo $number, "\n";
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'simulated failure after taking a number') {
            fwrite(STDERR, $e->getMessage()."\n");
            exit(1);
        }
    }
}
