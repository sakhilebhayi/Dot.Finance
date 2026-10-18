<?php

namespace App\Http\Controllers;

use App\Models\Insight;

class InsightController extends Controller
{
    /**
     * Display the active ledger's weekly rule-based summaries, newest first.
     *
     * No explicit owner filter needed: Insight's shared ledger scope
     * confines every query to the active context, the same convention
     * TransactionController's index() already documents.
     */
    public function index()
    {
        $insights = Insight::latest('id')->paginate(20);

        return view('insights.index', compact('insights'));
    }
}
