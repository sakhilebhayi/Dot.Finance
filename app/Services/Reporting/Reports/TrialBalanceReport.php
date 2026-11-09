<?php

namespace App\Services\Reporting\Reports;

use App\Services\Accounting\TrialBalance;
use App\Services\Reporting\Books;
use App\Services\Reporting\Reconciliations;
use App\Services\Reporting\ReportRequest;
use App\Services\Reporting\ReportResult;
use App\Support\Money;

/** The trial balance as at the period's end date, in the common report shape (so it exports and drills like the rest). */
class TrialBalanceReport extends Report
{
    public function __construct(Books $books, Reconciliations $recon, private readonly TrialBalance $trialBalance)
    {
        parent::__construct($books, $recon);
    }

    public function key(): string
    {
        return 'trial-balance';
    }

    public function title(): string
    {
        return 'Trial balance';
    }

    public function description(): string
    {
        return 'Every account\'s debit or credit balance on a date. Debits must equal credits.';
    }

    public function isPointInTime(): bool
    {
        return true;
    }

    public function build(ReportRequest $r): ReportResult
    {
        $asAt = $r->period->to;
        $tb = $this->trialBalance->asOf($r->context, $asAt);

        $rows = [];
        foreach ($tb['rows'] as $row) {
            $a = $row['account'];
            $rows[] = $this->row('line', $this->accountLabel($a), [
                'class' => ucfirst($a->class), 'debit' => $row['debit_balance'] ?: null, 'credit' => $row['credit_balance'] ?: null,
            ], ['debit' => $this->drill('', $asAt, ['account' => $a->id, 'sign' => 'debit']), 'credit' => $this->drill('', $asAt, ['account' => $a->id, 'sign' => 'credit'])], 1);
        }
        $rows[] = $this->row('total', 'Total', ['debit' => $tb['total_debits'], 'credit' => $tb['total_credits']], [], 0);

        $eq = $tb['equation'];
        $checks = [
            $this->recon->trialBalance($r, $tb),
            $this->recon->check('equation', 'Assets equal liabilities plus equity plus profit', $eq['holds'], $eq['assets'] - ($eq['liabilities'] + $eq['equity'] + $eq['income'] - $eq['expenses']), 'Assets '.Money::format($eq['assets'], $r->currency()).' = liabilities '.Money::format($eq['liabilities'], $r->currency()).' + equity '.Money::format($eq['equity'], $r->currency()).' + income '.Money::format($eq['income'], $r->currency()).' - expenses '.Money::format($eq['expenses'], $r->currency()).'.'),
            $this->recon->bank($r, $asAt),
        ];

        return new ReportResult($this->key(), $this->title(), $this->header($r, ['Balances are as at the end date, from every posted journal line including year-end closing entries.'], 'As at '.\Carbon\CarbonImmutable::parse($asAt)->format('j M Y')),
            [$this->col('label', 'Account', 'text'), $this->col('class', 'Class', 'text'), $this->col('debit', 'Debit'), $this->col('credit', 'Credit')], $rows, $checks, [],
            $tb['rows'] === [] ? 'No entries yet. Record a transaction or post a journal and it appears here.' : null);
    }
}
