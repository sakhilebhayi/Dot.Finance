<?php

// Local bank-reconciliation benchmark on the busy ledger (use a throwaway COPY: it records matches):
//   php -d memory_limit=128M tests/Support/Perf/bank-bench.php [STATEMENT_ID]

use App\Models\Account;
use App\Models\Bank\BankStatement;
use App\Models\Team;
use App\Models\User;
use App\Services\Bank\ExceptionClassifier;
use App\Services\Bank\MatchingService;
use App\Services\Bank\ReconciliationService;
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
DB::listen(function () use (&$q) { $q++; });
$step = function (string $label, callable $fn) use (&$q) {
    $q = 0;
    $t = microtime(true);
    $r = $fn();
    printf("%-34s %7.2fs  %5d queries  peak %.0f MB\n", $label, microtime(true) - $t, $q, memory_get_peak_usage(true) / 1048576);

    return $r;
};

LedgerContext::run($ctx, function () use ($user, $ctx, $argv, $step) {
    $statement = BankStatement::query()->findOrFail((int) ($argv[1] ?? 1));
    $account = Account::query()->findOrFail($statement->account_id);
    printf("statement #%d: %d lines, account %s, %s to %s\n", $statement->id, $statement->line_count, $account->name, $statement->date_from->toDateString(), $statement->date_to->toDateString());
    $session = $step('open the reconciliation', fn () => app(ReconciliationService::class)->start($user, $account, $statement->date_to->toDateString(), (int) $statement->closing_balance_cents ?: 0, null, $statement));
    $service = app(MatchingService::class);
    $suggest = $step('suggest matches', fn () => $service->suggest($session));
    printf("  proposals %d, auto %d, open lines %d\n", count($suggest['proposals']), count($suggest['auto']), count($suggest['lines']));
    $result = $step('accept the automatic matches', fn () => $service->accept($session, $suggest['auto'], $user));
    printf("  accepted %d\n", $result['accepted']);
    $open = $step('suggest again (what is left)', fn () => collect($service->suggest($session)['lines']));
    $step('classify the exceptions', fn () => app(ExceptionClassifier::class)->classify($session, $open, collect()));
    printf("  exception lines %d\n", $open->count());
});
