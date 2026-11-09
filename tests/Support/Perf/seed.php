<?php

// Local performance fixture runner. Usage (throwaway database only):
//   php artisan finance:demo-seed --sales && php -d memory_limit=2G tests/Support/Perf/seed.php
// Reads PERF_TX / PERF_INV / PERF_BILL / PERF_PARTIES / PERF_SEED from the environment.

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Perf\PerfSeeder;

require __DIR__.'/../../../vendor/autoload.php';
require __DIR__.'/PerfSeeder.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! in_array(config('database.default'), ['pgsql'], true) || ! app()->environment(['local', 'testing'])) {
    fwrite(STDERR, "Refusing: this fixture only runs on a local PostgreSQL database.\n");
    exit(1);
}

$t = microtime(true);
$counts = (new PerfSeeder)->run();
fwrite(STDOUT, json_encode($counts + ['seconds' => round(microtime(true) - $t, 1)], JSON_PRETTY_PRINT)."\n");

$seeder = new PerfSeeder;
fwrite(STDOUT, json_encode(['bank' => $seeder->bankStatements()], JSON_PRETTY_PRINT)."\n");
fwrite(STDOUT, json_encode(['tax' => $seeder->tax()], JSON_PRETTY_PRINT)."\n");
