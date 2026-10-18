<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Category;
use App\Models\FinanceAuditLog;
use App\Models\User;
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

        $context = $this->ledger();

        // In a company's log: narrow to one member ("who did this?") or to the scheduler.
        $actor = $context->isCompany() ? $this->actorFilter($request->query('actor')) : null;

        $entries = FinanceAuditLog::query()
            ->with('actorUser:id,name')
            ->when($subject, fn ($q) => $q->where('subject_type', $subject))
            ->when($actor === 'system', fn ($q) => $q->where('actor', 'system'))
            ->when(is_int($actor), fn ($q) => $q->where('actor_user_id', $actor))
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

        // Only people who actually appear in THIS ledger's log are offered, so the filter never lists anyone else.
        $actors = $context->isCompany()
            ? User::query()->whereIn('id', FinanceAuditLog::query()->whereNotNull('actor_user_id')->distinct()->pluck('actor_user_id'))->orderBy('name')->get(['id', 'name'])
            : collect();

        return view('audit.index', [
            'entries' => $entries, 'subject' => $subject, 'subjects' => self::SUBJECTS, 'names' => $names,
            'actors' => $actors, 'actor' => $actor,
        ]);
    }

    /** @return int|'system'|null */
    private function actorFilter(mixed $value): int|string|null
    {
        if ($value === 'system') {
            return 'system';
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
