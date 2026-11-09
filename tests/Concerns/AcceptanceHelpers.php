<?php

namespace Tests\Concerns;

use App\Models\FinanceAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Small helpers shared by the R-44 acceptance tests (tests/Feature/Acceptance). The acceptance tests drive real HTTP routes and real
 * services on a real database; nothing is mocked. Each class says which of the owner's 34 criteria it automates.
 */
trait AcceptanceHelpers
{
    /** Sign in as $user with the test company active. */
    protected function asMember(User $user): static
    {
        return $this->inCompany($user, $this->company);
    }

    protected function asOwner(): static
    {
        return $this->asMember($this->owner);
    }

    /** Parse a CSV response body into rows. @return list<list<string>> */
    protected function csvRows(string $body): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\n|\r/', trim($body)) as $line) {
            $rows[] = str_getcsv($line, ',', '"', '\\');
        }

        return $rows;
    }

    /** Audit rows of one action for the company. @return \Illuminate\Support\Collection<int, FinanceAuditLog> */
    protected function auditRows(?string $action = null, ?string $subjectType = null)
    {
        return FinanceAuditLog::withoutGlobalScopes()->where('team_id', $this->company->id)
            ->when($action, fn ($q) => $q->where('action', $action))
            ->when($subjectType, fn ($q) => $q->where('subject_type', $subjectType))
            ->orderBy('id')->get();
    }

    /** Sum of journal debits and credits for the company (must be equal on every database). @return array{int, int} */
    protected function journalTotals(): array
    {
        $r = DB::table('journal_lines')->where('team_id', $this->company->id)->selectRaw('COALESCE(SUM(debit_cents),0) d, COALESCE(SUM(credit_cents),0) c')->first();

        return [(int) $r->d, (int) $r->c];
    }
}
