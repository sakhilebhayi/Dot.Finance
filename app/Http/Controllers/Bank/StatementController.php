<?php

namespace App\Http\Controllers\Bank;

use App\Enums\Capability;
use App\Exceptions\Bank\ReconciliationRefused;
use App\Models\Account;
use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankStatement;
use App\Models\Bank\BankStatementLine;
use App\Services\Bank\StatementImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;

class StatementController extends BankController
{
    /** The bank home: where each account stands, then the statements imported. */
    public function index(Request $request): View
    {
        $this->read();
        $context = $this->ledger();

        $accounts = Account::query()->forContext($context)->orderBy('name')->get();

        $counts = BankStatementLine::query()->forContext($context)->toBase()->groupBy('account_id')
            ->selectRaw('account_id, COUNT(*) AS lines, SUM(CASE WHEN bank_match_id IS NULL AND reconciliation_id IS NULL THEN 1 ELSE 0 END) AS open_lines, MAX(posted_on) AS latest')->get()->keyBy('account_id');

        $reconciled = BankReconciliation::query()->forContext($context)->where('status', 'completed')->toBase()->groupBy('account_id')
            ->selectRaw('account_id, MAX(statement_date) AS reconciled_to')->pluck('reconciled_to', 'account_id');

        $drafts = BankReconciliation::query()->forContext($context)->where('status', 'draft')->pluck('id', 'account_id');

        $summary = $accounts->map(fn (Account $a) => [
            'account' => $a,
            'lines' => (int) ($counts[$a->id]->lines ?? 0),
            'open' => (int) ($counts[$a->id]->open_lines ?? 0),
            'latest' => isset($counts[$a->id]->latest) ? substr((string) $counts[$a->id]->latest, 0, 10) : null,
            'reconciled_to' => isset($reconciled[$a->id]) ? substr((string) $reconciled[$a->id], 0, 10) : null,
            'draft_id' => $drafts[$a->id] ?? null,
        ]);

        $statements = BankStatement::query()->with('account')->orderByDesc('date_to')->orderByDesc('id')->paginate(15);

        return view('bank.statements.index', [
            'summary' => $summary,
            'statements' => $statements,
            'canWork' => $this->can(Capability::RunReconciliation),
        ]);
    }

    public function show(Request $request, BankStatement $statement): View
    {
        $this->read();

        $lines = BankStatementLine::query()->where('bank_statement_id', $statement->id)->orderBy('line_no')->paginate(50)->withQueryString();

        $statement->load('account');

        return view('bank.statements.show', [
            'statement' => $statement,
            'lines' => $lines,
            'openLines' => BankStatementLine::query()->where('bank_statement_id', $statement->id)->whereNull('bank_match_id')->whereNull('reconciliation_id')->count(),
            'used' => $this->isUsed($statement),
            'draft' => BankReconciliation::query()->where('account_id', $statement->account_id)->where('status', 'draft')->first(),
            'canWork' => $this->can(Capability::RunReconciliation),
        ]);
    }

    /** Remove a statement nobody has worked on (a wrong file). One that has matched lines is kept as evidence. */
    public function destroy(Request $request, BankStatement $statement, StatementImporter $importer): RedirectResponse
    {
        $this->work();

        try {
            $removed = $importer->discardUnused($statement);
        } catch (InvalidArgumentException|ReconciliationRefused $e) {
            return back()->withErrors(['bank' => $e->getMessage()]);
        }

        return redirect()->route('bank.statements.index')->with('status', "Statement removed ({$removed} ".($removed === 1 ? 'line' : 'lines').'). The audit log keeps a record.');
    }

    private function isUsed(BankStatement $statement): bool
    {
        return DB::table('bank_statement_lines')->where('bank_statement_id', $statement->id)->where(fn ($q) => $q->whereNotNull('bank_match_id')->orWhereNotNull('reconciliation_id'))->exists()
            || DB::table('bank_reconciliations')->where('bank_statement_id', $statement->id)->exists();
    }
}
