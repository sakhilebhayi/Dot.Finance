<?php

namespace App\Http\Controllers\Reporting;

use App\Enums\Capability;
use App\Models\Reporting\ReportExport;
use App\Services\Reporting\Export\ReportCsv;
use App\Services\Reporting\Export\XlsxWriter;
use App\Services\Reporting\ExportService;
use App\Services\Reporting\ReportRegistry;
use App\Services\Reporting\ReportRunner;
use App\Support\ExportAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends ReportingController
{
    public function index(): View
    {
        $this->access();

        return view('reports.exports.index', ['exports' => ReportExport::query()->latest('id')->limit(50)->get()]);
    }

    /**
     * Small exports stream straight back; a large one (or one asked to run in the background) is queued and the person is
     * taken to its status page. Either way the figures are the same ReportResult the page shows.
     */
    public function store(Request $request, ReportRegistry $registry, ReportRunner $runner, ExportService $exports): StreamedResponse|RedirectResponse
    {
        $this->require(Capability::ExportReports);
        $context = $this->ledger();

        $meta = $request->validate([
            'report' => ['required', 'string', 'in:'.implode(',', $registry->keys())],
            'format' => ['required', 'in:csv,xlsx'],
            'queue' => ['nullable', 'in:0,1'],
        ]);
        $input = $this->reportInput($request);

        if (! $runner->enabled($context)) {
            return redirect()->route('reports.index');
        }

        if ($meta['format'] === 'xlsx' && ! XlsxWriter::available()) {
            return back()->withErrors(['format' => 'Spreadsheet export needs the PHP zip extension on this server. Export CSV instead.']);
        }

        try {
            $built = $runner->request($context, $meta['report'], $input);
            $rows = $registry->get($meta['report'])->estimatedRows($built);

            if (($meta['queue'] ?? '0') === '1' || $rows > (int) config('reporting.export_queue_threshold_rows')) {
                $export = $exports->request($context, $request->user(), $meta['report'], $meta['format'], $input);

                return redirect()->route('reports.exports.show', $export)->with('status', $export->status === 'done' ? 'This export already exists for the current books.' : 'The export is being prepared.');
            }

            $result = $runner->build($meta['report'], $built);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['period' => $e->getMessage()]);
        }

        $name = $meta['report'].'-'.$built->period->to.'.'.$meta['format'];
        ExportAudit::record('Report '.$meta['report'].' to '.$built->period->to, $meta['format']);

        if ($meta['format'] === 'csv') {
            return response()->streamDownload(function () use ($result) {
                $out = fopen('php://output', 'w');
                ReportCsv::write($result, $out);
                fclose($out);
            }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'fin-xlsx');
        XlsxWriter::write($result, $tmp);

        return response()->streamDownload(function () use ($tmp) {
            readfile($tmp);
            @unlink($tmp);
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function show(ReportExport $reportExport): View
    {
        $this->require(Capability::ExportReports);

        return view('reports.exports.show', [
            'export' => $reportExport,
            'downloadUrl' => $reportExport->isDone() ? URL::temporarySignedRoute('reports.exports.download', now()->addMinutes((int) config('reporting.download_link_minutes')), ['reportExport' => $reportExport->id]) : null,
        ]);
    }

    public function download(ReportExport $reportExport): StreamedResponse
    {
        $this->require(Capability::ExportReports);
        abort_unless($reportExport->isDone() && Storage::disk($reportExport->disk)->exists($reportExport->path), 404);
        ExportAudit::record('Report export #'.$reportExport->id.' downloaded', (string) $reportExport->format);

        return Storage::disk($reportExport->disk)->download($reportExport->path, $reportExport->downloadName(), ['X-Content-SHA256' => $reportExport->sha256]);
    }
}
