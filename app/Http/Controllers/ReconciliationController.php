<?php

namespace App\Http\Controllers;

use App\Services\LedgerReconciler;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function index(Request $request, LedgerReconciler $reconciler)
    {
        $report = $reconciler->run($this->ledger());

        return view('reconciliation.index', [
            'accounts' => $report['accounts'],
            'findings' => $report['findings'],
            'problems' => LedgerReconciler::problems($report),
        ]);
    }
}
