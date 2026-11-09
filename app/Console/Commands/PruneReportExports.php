<?php

namespace App\Console\Commands;

use App\Services\Reporting\ExportService;
use Illuminate\Console\Command;

class PruneReportExports extends Command
{
    protected $signature = 'finance:reports:prune-exports';

    protected $description = 'Delete report export files and rows past their retention period';

    public function handle(ExportService $exports): int
    {
        $this->info('Removed '.$exports->prune().' expired report export(s).');

        return self::SUCCESS;
    }
}
