<?php

namespace App\Http\Controllers;

use App\Models\AiInsight;

class InsightController extends Controller
{
    /**
     * Display the authenticated user's generated insights, newest first.
     *
     * No explicit user_id filter needed: AiInsight's HasUserScope trait
     * applies it automatically to every query against the model, the
     * same convention TransactionController's index() already documents.
     */
    public function index()
    {
        $insights = AiInsight::latest()->paginate(20);

        return view('insights.index', compact('insights'));
    }
}
