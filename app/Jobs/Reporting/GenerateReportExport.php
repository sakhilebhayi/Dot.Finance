<?php

namespace App\Jobs\Reporting;

use App\Models\Reporting\ReportExport;
use App\Services\Reporting\ExportService;
use App\Support\Ledger\LedgerContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Builds one queued export. It carries ids only (the export and its ledger owner), re-reads the row under the ledger it
 * names, and does nothing if the export is already done: a retried or duplicated job cannot make a second file.
 */
class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly int $exportId, public readonly ?int $userId, public readonly ?int $teamId) {}

    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(ExportService $service): void
    {
        $context = LedgerContext::fromOwner($this->userId, $this->teamId);

        $export = LedgerContext::run($context, fn () => ReportExport::query()->whereKey($this->exportId)->first());
        if ($export === null) {
            return;
        }

        $service->generate($export);
    }
}
