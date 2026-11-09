<?php

namespace App\Services\Reporting\Reports;

use App\Contracts\Reporting\TaxSummaryProvider;
use App\Services\Reporting\Books;
use App\Services\Reporting\Reconciliations;
use App\Services\Reporting\ReportRequest;
use App\Services\Reporting\ReportResult;
use Carbon\CarbonImmutable;

/**
 * Tax position: what the ledger says is owed or claimable at the end date (VAT, PAYE, UIF, SDL, income tax), and, when a
 * Tax module is connected, its estimates, obligations and statuses, each labelled calculated, estimate or user provided.
 */
class TaxSummaryReport extends Report
{
    private const KEYS = [
        'vat_output' => ['VAT output (owed to SARS)', 'liability'],
        'vat_input' => ['VAT input (claimable)', 'asset'],
        'vat_control' => ['VAT control (net payable)', 'liability'],
        'paye_payable' => ['PAYE payable', 'liability'],
        'uif_payable' => ['UIF payable', 'liability'],
        'sdl_payable' => ['SDL payable', 'liability'],
        'income_tax_payable' => ['Income tax payable', 'liability'],
        'deferred_tax' => ['Deferred tax', 'liability'],
    ];

    public function __construct(Books $books, Reconciliations $recon, private readonly TaxSummaryProvider $provider)
    {
        parent::__construct($books, $recon);
    }

    public function key(): string
    {
        return 'tax-summary';
    }

    public function title(): string
    {
        return 'Tax summary';
    }

    public function description(): string
    {
        return 'What the ledger says is owed or claimable for each tax at a date, plus the Tax module\'s estimates and deadlines when it is connected.';
    }

    public function isPointInTime(): bool
    {
        return true;
    }

    public function group(): string
    {
        return 'tax';
    }

    public function build(ReportRequest $r): ReportResult
    {
        $asAt = $r->period->to;
        $accounts = $this->books->accounts($r->context);
        $balances = $this->books->balancesThrough($r, $asAt);

        $rows = [$this->section('Balances in the ledger (calculated)')];
        foreach (self::KEYS as $key => [$label, $side]) {
            $a = $accounts->firstWhere('system_key', $key);
            if ($a === null) {
                continue;
            }
            $t = $balances[$a->id] ?? ['d' => 0, 'c' => 0];
            $value = $side === 'asset' ? $t['d'] - $t['c'] : $t['c'] - $t['d'];
            if ($value === 0) {
                continue;
            }
            $rows[] = $this->row('line', $label, ['kind' => 'Calculated', 'amount' => $value], ['amount' => $this->drill('', $asAt, ['account' => $a->id])], 1);
        }
        if (count($rows) === 1) {
            $rows[] = $this->row('info', 'No tax balances in the ledger on this date.', [], [], 1);
        }

        $notes = [];
        $summary = $this->provider->summary($r->context, $r->period->from, $r->period->to);
        if ($summary === null) {
            $notes[] = 'The Tax module is not connected to reporting, so no estimates, obligations or filing statuses are shown here. Nothing on this page is an estimate.';
        } else {
            $rows[] = $this->section('From the Tax module: '.$summary['source']);
            foreach ($summary['items'] as $item) {
                $rows[] = $this->row('line', $item['label'].(isset($item['status']) && $item['status'] ? ' ('.$item['status'].')' : ''), [
                    'kind' => ['calculated' => 'Calculated', 'estimate' => 'Estimate', 'user_provided' => 'User provided'][$item['kind']] ?? 'Estimate',
                    'due' => $item['due_on'] ?? null, 'amount' => $item['amount_cents'] ?? null,
                ], [], 1, $item['href'] ?? null);
                if (! empty($item['note'])) {
                    $notes[] = $item['label'].': '.$item['note'];
                }
            }
        }

        return new ReportResult($this->key(), $this->title(), $this->header($r, [
            'Balances are as posted at the end date. This is a summary of the books, not tax advice and not a return.',
        ], 'As at '.CarbonImmutable::parse($asAt)->format('j M Y')), [$this->col('label', 'Tax', 'text'), $this->col('kind', 'Status of the figure', 'text'), $this->col('due', 'Due', 'date'), $this->col('amount', 'Amount')],
            $rows, [$this->recon->bank($r, $asAt)], $notes, null);
    }
}
