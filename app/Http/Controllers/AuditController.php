<?php

namespace App\Http\Controllers;

use App\Models\FinanceAuditLog;
use Illuminate\Http\Request;

/**
 * A read-only view of the signed-in user's own audit entries. There is no
 * write route for this resource at all: no create, update or delete.
 */
class AuditController extends Controller
{
    private const SUBJECTS = ['transaction', 'account', 'category', 'budget', 'recurring_rule', 'import'];

    public function index(Request $request)
    {
        $subject = in_array($request->query('subject'), self::SUBJECTS, true) ? $request->query('subject') : null;

        $entries = FinanceAuditLog::query()
            ->when($subject, fn ($q) => $q->where('subject_type', $subject))
            ->latest('id')
            ->paginate(40)
            ->withQueryString();

        return view('audit.index', ['entries' => $entries, 'subject' => $subject, 'subjects' => self::SUBJECTS]);
    }
}
