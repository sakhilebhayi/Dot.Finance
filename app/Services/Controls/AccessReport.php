<?php

namespace App\Services\Controls;

use App\Models\FinanceAuditLog;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Who has what" for one ledger: each member, the role InfoDot gives them, any local grant (with
 * who gave it and when), the role in force, how many capabilities that is, when they were last
 * seen and what they last did here, and the history of changes to their role.
 *
 * Honesty about "last sign-in": the app does not record sign-ins. What it can show is the latest
 * activity of a session still stored in the sessions table (sessions expire, so this is a lower
 * bound and is empty for people with no live session) and the latest audit-log action by that
 * person in this ledger. Both are labelled as such on the page and in the export.
 */
final class AccessReport
{
    public function __construct(private readonly LedgerRoles $roles) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(LedgerContext $context): Collection
    {
        $members = $this->roles->members($context);
        $ids = $members->pluck('user.id')->all();

        $sessions = Schema::hasTable('sessions')
            ? DB::table('sessions')->whereIn('user_id', $ids)->groupBy('user_id')->selectRaw('user_id, MAX(last_activity) as last_activity')->pluck('last_activity', 'user_id')
            : collect();

        $lastAction = FinanceAuditLog::query()->forContext($context)->whereIn('actor_user_id', $ids)
            ->groupBy('actor_user_id')->selectRaw('actor_user_id, MAX(created_at) as last_at')->pluck('last_at', 'actor_user_id');

        $history = FinanceAuditLog::query()->forContext($context)->where('subject_type', 'member_role')
            ->where('context', 'like', 'member:%')->with('actorUser:id,name')->latest('id')->limit(500)->get()
            ->groupBy(fn (FinanceAuditLog $row) => (int) substr((string) $row->context, 7));

        return $members->map(function (array $row) use ($sessions, $lastAction, $history) {
            $user = $row['user'];
            $access = $row['access'];
            $grant = $row['grant'];

            return [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'infodot_role' => $row['infodot'],
                'baseline' => $access->baselineRole(),
                'local_role' => $access->hasLocalRole() ? $access->financeRole() : null,
                'role' => $access->financeRole(),
                'capability_count' => count($access->financeRole()->capabilities()),
                'granted_by' => $grant?->grantor?->name,
                'granted_at' => $grant?->granted_at,
                'last_session_activity' => isset($sessions[$user->id]) ? Carbon::createFromTimestamp((int) $sessions[$user->id]) : null,
                'last_action_here' => isset($lastAction[$user->id]) ? Carbon::parse($lastAction[$user->id]) : null,
                'history' => ($history[$user->id] ?? collect())->take(10)->values(),
            ];
        });
    }

    /** The CSV for download: one line per member, formula-safe. */
    public function csv(LedgerContext $context): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Ledger', 'Name', 'Email', 'Role from InfoDot', 'Local role', 'Role in force', 'Capabilities', 'Granted by', 'Granted at', 'Last session activity (live sessions only)', 'Last action in this ledger (audit log)'], ',', '"', '');

        foreach ($this->rows($context) as $row) {
            fputcsv($out, array_map([$this, 'safe'], [
                $context->label(), $row['name'], $row['email'], $row['infodot_role'], $row['local_role']?->label() ?? '',
                $row['role']->label(), $row['capability_count'], $row['granted_by'] ?? '', $row['granted_at']?->toDateTimeString() ?? '',
                $row['last_session_activity']?->toDateTimeString() ?? '', $row['last_action_here']?->toDateTimeString() ?? '',
            ]), ',', '"', '');
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** Spreadsheet formula injection: a cell that starts with = + - @ tab or CR is made literal. */
    private function safe(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }
}
