<?php

namespace App\Http\Controllers\Bank;

use App\Enums\Capability;
use App\Exceptions\Bank\ReconciliationRefused;
use App\Models\Account;
use App\Models\Bank\BankMatch;
use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankStatement;
use App\Models\Category;
use App\Rules\MoneyAmount;
use App\Services\Bank\DocumentSources;
use App\Services\Bank\ExceptionClassifier;
use App\Services\Bank\MatchingService;
use App\Services\Bank\ReconciliationBalances;
use App\Services\Bank\ReconciliationReport;
use App\Services\Bank\ReconciliationService;
use App\Support\ExportAudit;
use App\Support\Ledger\LedgerRule;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReconciliationController extends BankController
{
    /** How many rows of each list the workspace draws; the counts and totals always cover everything. */
    private const SHOWN = 25;

    public function index(Request $request, ReconciliationReport $report): View
    {
        $this->read();
        $context = $this->ledger();

        $drafts = BankReconciliation::query()->with('account')->where('status', 'draft')->orderBy('statement_date')->get();
        $completed = BankReconciliation::query()->with('account')->where('status', 'completed')->orderByDesc('statement_date')->paginate(10);
        $accounts = Account::query()->orderBy('name')->get();
        $statements = BankStatement::query()->with('account')->orderByDesc('date_to')->limit(50)->get();

        return view('bank.reconciliation.index', [
            'drafts' => $drafts,
            'completed' => $completed,
            'accounts' => $accounts,
            'accountsWithDraft' => $drafts->pluck('account_id')->all(),
            'statements' => $statements,
            'ageing' => $report->ageing($context),
            'canWork' => $this->can(Capability::RunReconciliation),
        ]);
    }

    public function start(Request $request, ReconciliationService $service): RedirectResponse
    {
        $this->work();
        $context = $this->ledger();

        $validated = $request->validate([
            'account_id' => ['required', 'integer', LedgerRule::exists('accounts', $context)->whereNull('deleted_at')],
            'bank_statement_id' => ['nullable', 'integer', LedgerRule::exists('bank_statements', $context)],
            'statement_date' => ['nullable', 'date_format:Y-m-d'],
            'closing' => ['nullable', new MoneyAmount(allowZero: true, allowNegative: true)],
            'opening' => ['nullable', new MoneyAmount(allowZero: true, allowNegative: true)],
        ]);

        $account = Account::findOrFail($validated['account_id']);
        $statement = isset($validated['bank_statement_id']) ? BankStatement::findOrFail($validated['bank_statement_id']) : null;

        $date = $validated['statement_date'] ?? $statement?->date_to?->toDateString();
        $closing = filled($validated['closing'] ?? null) ? Money::parse((string) $validated['closing']) : $statement?->closing_balance_cents;
        $opening = filled($validated['opening'] ?? null) ? Money::parse((string) $validated['opening']) : null;

        if ($date === null || $closing === null) {
            return back()->withErrors(['bank' => 'Give the statement date and the closing balance printed on the statement (a statement file that carries them fills these in).'])->withInput();
        }

        try {
            $session = $service->start($request->user(), $account, $date, $closing, $opening, $statement);
        } catch (ReconciliationRefused $e) {
            return $this->refused($e);
        }

        return redirect()->route('bank.reconciliation.show', $session)->with('status', 'Reconciliation started. Matched entries and the difference update as you work.');
    }

    public function show(
        Request $request,
        BankReconciliation $reconciliation,
        MatchingService $matching,
        ExceptionClassifier $classifier,
        ReconciliationBalances $balances,
        ReconciliationReport $report,
        DocumentSources $documents,
    ): View {
        $this->read();
        $session = $reconciliation->load('account', 'statement');
        $account = $session->account;
        $date = $session->statement_date->toDateString();
        $canWork = $this->can(Capability::RunReconciliation);

        $data = [
            'session' => $session,
            'account' => $account,
            'canWork' => $canWork,
            'canVariance' => $this->can(Capability::PostJournal),
            'documentsAvailable' => $documents->available(),
            'shown' => self::SHOWN,
            'events' => $session->events()->with('actor:id,name')->get()->reverse()->take(15)->values(),
            'previousClosing' => $report->previousClosing($session),
        ];

        if ($session->isCompleted()) {
            return view('bank.reconciliation.show', $data + [
                'report' => $report->build($session),
                'matchRows' => $this->activeMatches($session, 40),
                'matchCount' => BankMatch::query()->where('bank_reconciliation_id', $session->id)->where('state', 'active')->count(),
            ]);
        }

        $baseline = $session->baseline_date?->toDateString();
        $cleared = $balances->clearedBalance($account, $baseline);
        $difference = $session->statement_closing_cents - $cleared;
        $suggest = $matching->suggest($session);

        $proposed = [];
        foreach ($suggest['proposals'] as $proposal) {
            foreach ($proposal['line_ids'] as $id) {
                $proposed[$id] = true;
            }
        }

        $unproposed = collect($suggest['lines'])->reject(fn ($line, $id) => isset($proposed[$id]))->values();
        $groups = $classifier->classify($session, $unproposed, collect($suggest['entries']));

        $openCount = count($suggest['lines']);

        return view('bank.reconciliation.show', $data + [
            'cleared' => $cleared,
            'difference' => $difference,
            'auto' => $suggest['auto'],
            'review' => $suggest['review'],
            'lines' => $suggest['lines'],
            'entries' => $suggest['entries'],
            'groups' => $groups,
            'openCount' => $openCount,
            'unproposedCount' => $unproposed->count(),
            'matchRows' => $this->activeMatches($session, self::SHOWN),
            'matchCount' => BankMatch::query()->where('bank_reconciliation_id', $session->id)->where('state', 'active')->count(),
            'outstanding' => $balances->outstanding($account, $date, self::SHOWN, $baseline),
            'outstandingTotals' => $balances->outstandingTotals($account, $date, $baseline),
            'broughtForward' => $balances->broughtForward($account, $baseline, $report->previousClosing($session)),
            'categories' => Category::query()->orderBy('name')->get(),
            'otherAccounts' => Account::query()->where('id', '<>', $account->id)->where('currency', $account->currency)->orderBy('name')->get(),
        ]);
    }

    public function report(Request $request, BankReconciliation $reconciliation, ReconciliationReport $report): View
    {
        $this->read();

        return view('bank.reconciliation.report', ['report' => $report->build($reconciliation->load('account'))]);
    }

    public function csv(Request $request, BankReconciliation $reconciliation, ReconciliationReport $report): StreamedResponse
    {
        $this->authorize('export-reports');

        $rows = $report->csvRows($report->build($reconciliation->load('account')));
        $name = 'bank-reconciliation-'.$reconciliation->id.'-'.$reconciliation->statement_date->toDateString().'.csv';
        ExportAudit::record('Bank reconciliation #'.$reconciliation->id);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                // A cell that starts with = + - or @ is read as a formula by spreadsheets: neutralise it.
                fputcsv($out, array_map(fn ($cell) => is_string($cell) && preg_match('/^[=+\-@\t\r]/', $cell) === 1 && ! is_numeric($cell) ? "'".$cell : $cell, $row), ',', '"', '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function activeMatches(BankReconciliation $session, int $limit)
    {
        $matches = BankMatch::query()->where('bank_reconciliation_id', $session->id)->where('state', 'active')->orderByDesc('id')->limit($limit)->get();

        if ($matches->isEmpty()) {
            return collect();
        }

        $ids = $matches->pluck('id');
        $lines = DB::table('bank_statement_lines')->whereIn('bank_match_id', $ids)->get(['id', 'bank_match_id', 'posted_on', 'amount_cents', 'description'])->groupBy('bank_match_id');
        $entries = DB::table('transactions')->whereIn('bank_match_id', $ids)->get(['id', 'bank_match_id', 'date', 'amount_cents', 'type', 'transfer_direction', 'description'])->groupBy('bank_match_id');

        return $matches->map(fn (BankMatch $m) => ['match' => $m, 'lines' => $lines[$m->id] ?? collect(), 'entries' => $entries[$m->id] ?? collect()]);
    }
}
