<?php

namespace App\Console\Commands;

use App\Services\Reporting\ExportService;
use Illuminate\Console\Command;

class PruneReportExports extends Command
{
    protected $signature = 'finance:reports:prune-exports';

    protected $description = 'Delete report export files and rows past their retention period, and requeue exports whose worker died';

    public function handle(ExportService $exports): int
    {
        $requeued = $exports->requeueStale();
        $line = 'Removed '.$exports->prune().' expired report export(s)';
        $this->info($line.($requeued > 0 ? ", put {$requeued} export(s) whose worker died back in the queue" : '').'.');

        return self::SUCCESS;
    }
}
