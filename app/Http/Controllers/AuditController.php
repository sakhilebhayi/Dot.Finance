<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Category;
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
