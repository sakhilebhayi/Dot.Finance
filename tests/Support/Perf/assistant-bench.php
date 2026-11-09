<?php

// Local benchmark: every suggested assistant question on the busy ledger (rule-based; no model key set).
//   php -d memory_limit=128M tests/Support/Perf/assistant-bench.php   (use a throwaway copy: it stores the messages)

use App\Models\Team;
use App\Models\User;
use App\Services\Intelligence\FinanceAssistant;
use App\Services\Intelligence\Intent;
use App\Support\Ledger\LedgerContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$team = Team::query()->where('name', 'Karoo Roasters (Pty) Ltd')->firstOrFail();
$user = User::query()->findOrFail($team->user_id);
$ctx = LedgerContext::company($team);
$q = 0;
DB::listen(function () use (&$q) {
    $q++;
});
printf("%-44s %8s %8s %8s\n", 'question', 'seconds', 'queries', 'peak MB');
foreach (Intent::suggestions() as $question) {
    $q = 0;
    $t = microtime(true);
    memory_reset_peak_usage();
    $m = LedgerContext::run($ctx, fn () => app(FinanceAssistant::class)->ask($user, $ctx, $question));
    printf("%-44s %8.2f %8d %8.0f   %s\n", $question, microtime(true) - $t, $q, memory_get_peak_usage(true) / 1048576, $m->intent ?? '');
}
