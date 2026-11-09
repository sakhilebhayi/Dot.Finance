<?php

// Local fixture for backfill/kill drills: a company ledger that has only the cash ledger (no accounting yet), N rows.
//   php tests/Support/Perf/legacy-ledger.php N   -> prints the new team id
// Throwaway database only.

use App\Models\Team;
use App\Models\User;
use App\Services\Accounting\ChartTemplates;
use App\Services\Accounting\GlBackfill;
use App\Support\Ledger\LedgerContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$n = (int) ($argv[1] ?? 20000);
$owner = User::query()->orderBy('id')->firstOrFail();
$team = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Legacy Co '.Str::random(4), 'personal_team' => false, 'infodot_team_uuid' => (string) Str::uuid()]);
$now = now()->toDateTimeString();
foreach (['Main', 'Savings'] as $i => $name) {
    DB::table('accounts')->insert(['team_id' => $team->id, 'name' => $name, 'type' => $i ? 'savings' : 'checking', 'currency' => 'ZAR', 'balance' => 0, 'opening_balance_cents' => 1_000_000, 'opening_balance_date' => '2025-01-01', 'created_at' => $now, 'updated_at' => $now]);
}
$accounts = DB::table('accounts')->where('team_id', $team->id)->pluck('id')->all();
foreach (['Sales' => 'income', 'Rent' => 'expense', 'Stock' => 'expense', 'Fuel' => 'expense'] as $name => $type) {
    DB::table('categories')->insert(['team_id' => $team->id, 'name' => $name, 'type' => $type, 'created_at' => $now, 'updated_at' => $now]);
}
$cats = DB::table('categories')->where('team_id', $team->id)->pluck('type', 'id')->all();
mt_srand(11);
$rows = [];
for ($i = 1; $i <= $n; $i++) {
    $cid = array_rand($cats);
    $cents = mt_rand(1500, 400000);
    $rows[] = ['team_id' => $team->id, 'account_id' => $accounts[$i % 2], 'category_id' => $cid, 'description' => 'Legacy row '.$i, 'amount' => number_format($cents / 100, 2, '.', ''), 'amount_cents' => $cents, 'currency' => 'ZAR', 'type' => $cats[$cid], 'date' => date('Y-m-d', strtotime('2025-01-02 +'.mt_rand(0, 600).' days')), 'version' => 1, 'recurrence_interval' => 1, 'created_at' => $now, 'updated_at' => $now];
    if (count($rows) === 1000) {
        DB::table('transactions')->insert($rows);
        $rows = [];
    }
}
if ($rows) {
    DB::table('transactions')->insert($rows);
}
// the accounting set-up wizard's first step: settings and the chart (the history is posted by finance:gl:backfill)
$ctx = LedgerContext::company($team);
LedgerContext::run($ctx, fn () => app(GlBackfill::class)->prepare($ctx, 'ZAR', 1, ChartTemplates::COMPANY));
echo $team->id."\n";
