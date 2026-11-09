<?php

/*
 * Worker process for tests/Feature/Accounting/ConcurrencyTest.php (PostgreSQL only). Boots the application against the
 * database named in the environment and does one job, starting at a shared instant so the workers really overlap.
 *
 *   php tests/Support/concurrent_worker.php post <user-id> <worker> <count> <start-at-microtime>
 *   php tests/Support/concurrent_worker.php catchup <user-id> <rule-id> <start-at-microtime>
 */

use App\Models\RecurringRule;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\TransactionRecurrenceService;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1];
$context = LedgerContext::personal((int) $argv[2]);
$startAt = (float) end($argv);

while (microtime(true) < $startAt) {
    usleep(200);
}

LedgerContext::run($context, function () use ($mode, $argv, $context) {
    if ($mode === 'post') {
        $worker = (int) $argv[3];
        $count = (int) $argv[4];
        $poster = app(JournalPoster::class);

        for ($i = 0; $i < $count; $i++) {
            // Every worker also tries the same shared key: exactly one of them may create it.
            foreach (["w{$worker}-{$i}", "shared-{$i}"] as $sourceId) {
                $poster->post(new PostingRequest(CarbonImmutable::parse('2026-03-10'), 'manual', $sourceId, 1, "Concurrent {$sourceId}", [
                    new PostingLine('cash', debitCents: 100 + $i),
                    new PostingLine('sales', creditCents: 100 + $i),
                ]));
            }
        }
    }

    if ($mode === 'catchup') {
        $rule = RecurringRule::query()->forContext($context)->findOrFail((int) $argv[3]);
        echo app(TransactionRecurrenceService::class)->catchUp($rule);
    }
});
