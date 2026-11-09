<?php

// Local export benchmark on the busy ledger: php -d memory_limit=128M tests/Support/Perf/export-bench.php KEY FORMAT [account-code]
//   e.g. general-ledger csv 1011 | general-ledger xlsx 1011 | profit-loss csv
// Generates the export the way the queued job does and reports time, peak memory and size. Use a throwaway COPY (it writes export rows/files).

use App\Models\Accounting\GlAccount;
use App\Models\Team;
use App\Models\User;
use App\Services\Reporting\ExportService;
use App\Support\Ledger\LedgerContext;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$key, $format] = [$argv[1] ?? 'general-ledger', $argv[2] ?? 'csv'];
$team = Team::query()->where('name', 'Karoo Roasters (Pty) Ltd')->firstOrFail();
$user = User::query()->findOrFail($team->user_id);
$ctx = LedgerContext::company($team);
$input = ['from' => '2025-11-01', 'to' => '2026-11-08'];
if ($key === 'general-ledger') {
    $input['account'] = (string) LedgerContext::run($ctx, fn () => GlAccount::query()->where('code', $argv[3] ?? '1011')->value('id'));
}
$service = app(ExportService::class);
$t = microtime(true);
$export = LedgerContext::run($ctx, function () use ($service, $ctx, $user, $key, $format, $input) {
    \Illuminate\Support\Facades\Queue::fake();
    $export = $service->request($ctx, $user, $key, $format, $input);

    return $service->generate($export);
});
printf("%s %s: status %s, %d rows, %.1f MB file, %.1fs, peak %.0f MB\n", $key, $format, $export->status, (int) $export->row_count, ($export->size_bytes ?? 0) / 1048576, microtime(true) - $t, memory_get_peak_usage(true) / 1048576);
if ($export->status !== 'done') {
    echo $export->error."\n";
}
