<?php

// Local import benchmark: php -d memory_limit=128M tests/Support/Perf/import-bench.php ROWS [commit]
// Runs the real TransactionImporter (analyse, and with "commit" the commit) on a generated CSV against the busy ledger.
// Use a throwaway COPY of the fixture database: "commit" really imports.

use App\Models\Account;
use App\Models\Team;
use App\Models\User;
use App\Services\TransactionImporter;
use App\Support\Ledger\LedgerContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$rows = (int) ($argv[1] ?? 20000);
$commit = ($argv[2] ?? '') === 'commit';
$team = Team::query()->where('name', 'Karoo Roasters (Pty) Ltd')->firstOrFail();
$user = User::query()->findOrFail($team->user_id);
$ctx = LedgerContext::company($team);
$account = LedgerContext::run($ctx, fn () => Account::query()->where('name', 'Business Cheque')->firstOrFail());

$path = sys_get_temp_dir().'/import-bench-'.$rows.'.csv';
$h = fopen($path, 'w');
fputcsv($h, ['Date', 'Description', 'Amount', 'Category'], ',', '"', '');
mt_srand(7);
for ($i = 1; $i <= $rows; $i++) {
    fputcsv($h, [sprintf('2026-%02d-%02d', 1 + $i % 10, 1 + $i % 27), 'IMPORT BENCH '.$i.' '.substr(md5((string) $i), 0, 8), ($i % 3 ? '-' : '').number_format(mt_rand(1000, 900000) / 100, 2, '.', ''), $i % 3 ? 'Packaging' : 'Wholesale'], ',', '"', '');
}
fclose($h);
printf("csv: %d rows, %.1f MB\n", $rows, filesize($path) / 1048576);

$importer = app(TransactionImporter::class);
$t = microtime(true);
$token = $importer->stage($user, $ctx, $account, new UploadedFile($path, 'bench.csv', 'text/csv', null, true));
$preview = $importer->analyse($user, $ctx, $token);
printf("analyse: %.1fs, peak %.0f MB, importable %d, rows kept in the preview %d\n", microtime(true) - $t, memory_get_peak_usage(true) / 1048576, $preview['counts']['importable'], count($preview['rows']));

if ($commit) {
    $t = microtime(true);
    $result = LedgerContext::run($ctx, fn () => $importer->commit($user, $ctx, $token, $preview['date_format'], $preview['amount_locale'], null, []));
    printf("commit: %.1fs, peak %.0f MB, %s\n", microtime(true) - $t, memory_get_peak_usage(true) / 1048576, json_encode($result));
}
@unlink($path);
