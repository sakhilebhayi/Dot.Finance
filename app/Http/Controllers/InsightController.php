<?php

namespace App\Http\Controllers;

use App\Models\Insight;

class InsightController extends Controller
{
    /**
     * Display the authenticated user's weekly rule-based summaries, newest first.
     *
     * No explicit user_id filter needed: Insight's HasUserScope trait
     * applies it automatically to every query against the model, the
     * same convention TransactionController's index() already documents.
     */
    public function index()
    {
        $insights = Insight::latest('id')->paginate(20);

        return view('insights.index', compact('insights'));
    }
}
