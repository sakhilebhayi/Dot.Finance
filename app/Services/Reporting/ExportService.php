<?php

namespace App\Services\Reporting;

use App\Jobs\Reporting\GenerateReportExport;
use App\Models\Reporting\ReportExport;
use App\Models\User;
use App\Services\Reporting\Export\ReportCsv;
use App\Services\Reporting\Export\XlsxWriter;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Queued, idempotent report exports.
 *
 * The idempotency key is a hash of (ledger, report, parameters, format, journal sequence). Asking again for the same
 * thing about the same books returns the same row (and so the same file); a posting changes the journal sequence, so a new
 * ask is a new export of the new books and a stale file is never offered as current. The job body is safe to run twice:
 * the file path is derived from the row, the file is written to a temporary name and renamed, and a row that is already
 * done is left alone.
 */
class ExportService
{
    public function __construct(private readonly ReportRunner $runner, private readonly ReportRegistry $registry) {}

    /**
     * Create (or find) the export for these parameters and queue it. A failed one is put back in the queue, not duplicated.
     *
     * @param  array<string, mixed>  $input
     */
    public function request(LedgerContext $context, User $by, string $reportKey, string $format, array $input): ReportExport
    {
        $request = $this->runner->request($context, $reportKey, $input);
        $params = $request->params();
        ksort($params);

        $key = hash('sha256', implode('|', [$context->key(), $reportKey, $format, json_encode($params), $request->journalSeq]));

        $export = DB::transaction(function () use ($context, $by, $reportKey, $format, $params, $key, $request) {
            $existing = ReportExport::query()->forContext($context)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            try {
                return ReportExport::create($context->ownerAttributes() + [
                    'requested_by' => $by->id, 'report_key' => $reportKey, 'format' => $format, 'params' => $params,
                    'params_hash' => hash('sha256', json_encode($params)), 'idempotency_key' => $key, 'journal_seq' => $request->journalSeq,
                    'status' => 'queued', 'expires_at' => now()->addDays((int) config('reporting.export_retention_days')),
                ]);
            } catch (UniqueConstraintViolationException) {
                return ReportExport::query()->forContext($context)->where('idempotency_key', $key)->firstOrFail();
            }
        });

        if ($export->wasRecentlyCreated) {
            FinanceAudit::record($context->ownerAttributes(), 'exported', 'report_export', $export->id, $export->auditLabel(), ['report' => ['after' => $reportKey], 'format' => ['after' => $format]], 'queued', null, $by->id);
        }

        if ($export->status === 'failed') {
            $export->forceFill(['status' => 'queued', 'error' => null])->save();
        }

        if ($export->status === 'queued') {
            GenerateReportExport::dispatch($export->id, $context->userId, $context->teamId);
        }

        return $export->refresh();
    }

    /** Produce the file. Safe to call twice and from a retried job. */
    public function generate(ReportExport $export): ReportExport
    {
        $context = $export->ledgerContext();

        return LedgerContext::run($context, function () use ($export, $context) {
            [$row, $claimed] = DB::transaction(function () use ($export) {
                $row = ReportExport::query()->whereKey($export->id)->lockForUpdate()->first();
                if ($row === null || $row->status === 'done') {
                    return [$row, false];
                }
                // Another worker took it a moment ago and has not finished: leave it to them (a crashed worker's claim goes stale).
                if ($row->status === 'running' && $row->started_at !== null && $row->started_at->gt(now()->subMinutes(10))) {
                    return [$row, false];
                }
                $row->forceFill(['status' => 'running', 'started_at' => now(), 'attempts' => $row->attempts + 1, 'error' => null])->save();

                return [$row, true];
            });

            if (! $claimed) {
                return $row ?? $export;
            }

            try {
                $report = $this->runner->run($context, $row->report_key, $row->params);
                $disk = Storage::disk(config('reporting.export_disk'));
                $dir = 'report-exports/'.str_replace(':', '-', $context->key());
                $path = $dir.'/'.$row->id.'-'.substr($row->idempotency_key, 0, 12).'.'.$row->format;
                $disk->makeDirectory($dir);
                $absolute = $disk->path($path);

                if ($row->format === 'xlsx') {
                    $rows = XlsxWriter::write($report, $absolute);
                } else {
                    $handle = fopen($absolute.'.part', 'w');
                    $rows = ReportCsv::write($report, $handle);
                    fclose($handle);
                    rename($absolute.'.part', $absolute);
                }

                $row->forceFill([
                    'status' => 'done', 'disk' => config('reporting.export_disk'), 'path' => $path, 'sha256' => hash_file('sha256', $absolute),
                    'size_bytes' => filesize($absolute), 'row_count' => $rows, 'finished_at' => now(), 'error' => null,
                ])->save();
            } catch (Throwable $e) {
                $row->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()])->save();

                throw $e;
            }

            return $row->refresh();
        });
    }

    /** Remove files and rows past their retention date. Returns how many exports were removed. */
    public function prune(): int
    {
        $removed = 0;
        foreach (LedgerContext::owningRowsIn('report_exports') as $context) {
            LedgerContext::run($context, function () use (&$removed) {
                foreach (ReportExport::query()->where('expires_at', '<', now())->get() as $export) {
                    if ($export->path !== null) {
                        Storage::disk($export->disk ?? config('reporting.export_disk'))->delete($export->path);
                    }
                    $export->delete();
                    $removed++;
                }
            });
        }

        return $removed;
    }

    public function reportExists(string $key): bool
    {
        return $this->registry->has($key);
    }
}
