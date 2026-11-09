<?php

namespace App\Services\Reporting\Reports;

use App\Enums\ReportBasis;
use App\Models\Accounting\GlAccount;
use App\Services\Reporting\Books;
use App\Services\Reporting\Reconciliations;
use App\Services\Reporting\ReportPeriod;
use App\Services\Reporting\ReportRequest;
use App\Services\Reporting\ReportResult;
use App\Support\Money;

/**
 * A report: a key, a title, the options it accepts, and build(), which turns a ReportRequest into a ReportResult.
 * Nothing here writes to the books and nothing returns a float.
 */
abstract class Report
{
    public function __construct(protected readonly Books $books, protected readonly Reconciliations $recon) {}

    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    abstract public function build(ReportRequest $request): ReportResult;

    /** @return list<string> the option names (beyond preset, from, to, compare and basis) this report reads */
    public function optionKeys(): array
    {
        return [];
    }

    public function supportsBasis(): bool
    {
        return false;
    }

    public function supportsComparison(): bool
    {
        return false;
    }

    /** True when the report is a point in time (a balance sheet, an ageing) so only the period's end date matters. */
    public function isPointInTime(): bool
    {
        return false;
    }

    /** A rough count of rows the report will produce, used to decide whether an export should be queued. */
    public function estimatedRows(ReportRequest $request): int
    {
        return 200;
    }

    /** The group the hub lists it under. */
    public function group(): string
    {
        return 'statements';
    }

    // ---- shared pieces -------------------------------------------------------------------------

    /** @param  list<string>  $assumptions */
    protected function header(ReportRequest $r, array $assumptions = [], ?string $period = null): array
    {
        return [
            'ledger' => $r->context->label(),
            'period' => $period ?? $r->period->describe(),
            'comparison' => $r->comparison === null ? null : ($this->isPointInTime() ? 'As at '.ReportPeriod::date($r->comparison->to) : $r->comparison->describe()),
            'currency' => $r->currency(),
            'basis' => $this->supportsBasis() ? $r->basis->label() : 'Accrual',
            'generated_at' => $r->generatedAt->toIso8601String(),
            'assumptions' => $assumptions,
            'rule_versions' => [
                'Posting rules' => 'v'.($r->settings->posting_rule_version ?? 1),
                'Chart of accounts' => $r->settings->chart_template ?? 'custom',
                'Journal sequence' => '#'.$r->journalSeq,
            ],
        ];
    }

    /** @return list<string> */
    protected function basisAssumptions(ReportRequest $r): array
    {
        if (! $this->supportsBasis()) {
            return [];
        }

        return $r->basis === ReportBasis::Cash
            ? [
                'Cash basis: invoices and bills count for the share that has been paid, on each payment date. Other entries count on their own date.',
                'Credit notes count when issued. Money received on account counts once it is allocated. Accruals, prepayments and depreciation are taken as posted.',
            ]
            : ['Accrual basis: entries count on their posting date, whether or not the money has moved.'];
    }

    protected function accountLabel(GlAccount $a): string
    {
        return $a->code.' '.$a->name;
    }

    /** Basis points of $part in $whole, rounded half away from zero; null when there is no whole to measure against. */
    protected function bp(int $part, int $whole): ?int
    {
        return $whole === 0 ? null : Money::divideRound($part * 10000, $whole);
    }

    /** @return array{key: string, label: string, type: string, projected: bool} */
    protected function col(string $key, string $label, string $type = 'money'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type, 'projected' => false];
    }

    protected function section(string $label): array
    {
        return ['type' => 'section', 'label' => $label, 'depth' => 0, 'cells' => [], 'drill' => []];
    }

    protected function row(string $type, string $label, array $cells, array $drill = [], int $depth = 1, ?string $href = null): array
    {
        return ['type' => $type, 'label' => $label, 'depth' => $depth, 'cells' => $cells, 'drill' => $drill, 'href' => $href];
    }

    /** @param  array<string, mixed>  $extra */
    protected function drill(string $from = '', string $to = '', array $extra = [], ?ReportBasis $basis = null): array
    {
        return array_filter(['from' => $from, 'to' => $to, 'basis' => $basis?->value] + $extra, fn ($v) => $v !== null && $v !== '');
    }
}
