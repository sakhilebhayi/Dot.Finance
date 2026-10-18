<?php

namespace App\Console\Commands;

use App\Services\TransactionImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:prune-imports {--hours=24 : Delete staged CSV files older than this}')]
#[Description('Delete uploaded CSV files that were previewed but never imported')]
class PruneImports extends Command
{
    public function handle(TransactionImporter $importer): int
    {
        $deleted = $importer->prune((int) $this->option('hours'));

        $this->info("Deleted {$deleted} abandoned staged file(s).");

        return self::SUCCESS;
    }
}
