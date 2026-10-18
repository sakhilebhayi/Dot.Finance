<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Category;
use App\Models\FinanceAuditLog;
use Illuminate\Http\Request;

/**
 * A read-only view of the active ledger's audit entries: the person's own in the
 * personal ledger, the whole company's (every member's actions) in a company ledger,
 * where only an owner or admin may read it. There is no write route for this
 * resource at all: no create, update or delete.
 */
class AuditController extends Controller
{
    private const SUBJECTS = ['transaction', 'account', 'category', 'budget', 'recurring_rule', 'import'];

    public function index(Request $request)
    {
        $this->authorize('view-audit');

        $subject = in_array($request->query('subject'), self::SUBJECTS, true) ? $request->query('subject') : null;

        $entries = FinanceAuditLog::query()
            ->when($subject, fn ($q) => $q->where('subject_type', $subject))
            ->latest('id')
            ->paginate(40)
            ->withQueryString();

        // account_id / category_id are shown by name (deleted ones included).
        $ids = ['account_id' => [], 'category_id' => []];
        foreach ($entries as $entry) {
            foreach (['account_id', 'category_id'] as $field) {
                foreach (['before', 'after'] as $side) {
                    $value = $entry->changes[$field][$side] ?? null;
                    if ($value !== null) {
                        $ids[$field][] = (int) $value;
                    }
                }
            }
        }

        $names = [
            'account_id' => Account::withTrashed()->whereIn('id', array_unique($ids['account_id']))->pluck('name', 'id')->all(),
            'category_id' => Category::withTrashed()->whereIn('id', array_unique($ids['category_id']))->pluck('name', 'id')->all(),
        ];

        return view('audit.index', ['entries' => $entries, 'subject' => $subject, 'subjects' => self::SUBJECTS, 'names' => $names]);
    }
}
