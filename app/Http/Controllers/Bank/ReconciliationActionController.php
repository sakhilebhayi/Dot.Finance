<?php

namespace App\Http\Controllers\Bank;

use App\Exceptions\Bank\ReconciliationRefused;
use App\Models\Bank\BankMatch;
use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankStatementLine;
use App\Services\Bank\DocumentSources;
use App\Services\Bank\LineActions;
use App\Services\Bank\MatchEngine;
use App\Services\Bank\MatchingService;
use App\Services\Bank\MatchText;
use App\Services\Bank\ReconciliationService;
use App\Support\Ledger\LedgerRule;
use App\Support\Shared\Exceptions\AllocationRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * What a person does inside a reconciliation. Every action:
 *  - needs run-reconciliation (reopening and accepting a difference also need post-journal, asked again by the service);
 *  - takes ids only, never amounts or totals: the server recomputes proposals, sums and candidate documents itself;
 *  - runs in one database transaction under a lock on the session, and writes nothing when a rule says no.
 * The session in the path and every id in the body are confined to the active ledger; an id from another ledger fails
 * validation or is a 404.
 */
class ReconciliationActionController extends BankController
{
    /** Take the matches the engine proposes (recomputed here, never read from the browser). */
    public function accept(Request $request, BankReconciliation $reconciliation, MatchingService $matching): RedirectResponse
    {
        $this->work();

        $validated = $request->validate([
            'mode' => ['required', Rule::in(['auto', 'one'])],
            'line_id' => ['required_if:mode,one', 'nullable', 'integer'],
        ]);

        try {
            $proposals = $matching->suggest($reconciliation)['proposals'];

            $chosen = $validated['mode'] === 'auto'
                ? array_values(array_filter($proposals, fn ($p) => $p['confidence'] >= MatchEngine::AUTO && $p['shape'] === 'one_to_one'))
                : array_values(array_filter($proposals, fn ($p) => in_array((int) $validated['line_id'], $p['line_ids'], true)));

            if ($chosen === []) {
                return back()->withErrors(['bank' => 'That suggestion no longer stands (something changed since the page was drawn). Review the list again.']);
            }

            $result = $matching->accept($reconciliation, $chosen, $request->user(), $validated['mode'] === 'auto' ? 'auto' : 'manual');
        } catch (ReconciliationRefused $e) {
            return $this->refused($e);
        }

        $message = $result['accepted'].($result['accepted'] === 1 ? ' match' : ' matches').' recorded.';
        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} skipped because they changed meanwhile.";
        }

        return back()->with('status', $message);
    }

    /** Match the lines and entries a person ticked. */
    public function match(Request $request, BankReconciliation $reconciliation, ReconciliationService $service): RedirectResponse
    {
        $this->work();
        $context = $this->ledger();

        $validated = $request->validate([
            'line_ids' => ['required', 'array', 'min:1', 'max:50'],
            'line_ids.*' => ['integer', LedgerRule::exists('bank_statement_lines', $context)],
            'entry_ids' => ['required', 'array', 'min:1', 'max:50'],
            'entry_ids.*' => ['integer', LedgerRule::exists('transactions', $context)],
        ], ['line_ids.required' => 'Tick at least one statement line.', 'entry_ids.required' => 'Tick at least one ledger entry to match it with.']);

        try {
            $service->matchRows($reconciliation, $validated['line_ids'], $validated['entry_ids'], $request->user());
        } catch (ReconciliationRefused $e) {
            return $this->refused($e);
        }

        return back()->with('status', 'Matched.');
    }

    public function unmatch(Request $request, BankReconciliation $reconciliation, BankMatch $bankMatch, ReconciliationService $service): RedirectResponse
    {
        $this->work();
        abort_unless($bankMatch->bank_reconciliation_id === $reconciliation->id, 404);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $service->unmatch($bankMatch, $request->user(), $validated['reason'] ?? null);
        } catch (ReconciliationRefused $e) {
            return $this->refused($e);
        }

        return back()->with('status', 'Un-matched. The match is kept in the history with your name and the time. Any entry that was created for it stays in your ledger.');
    }

    /** Create the entries a group of lines call for: your category, each line's rule, bank charges or interest. */
    public function entries(Request $request, BankReconciliation $reconciliation, LineActions $actions): RedirectResponse
    {
        $this->work();
        $this->authorize('record-entries');
        $context = $this->ledger();

        $validated = $request->validate([
            'line_ids' => ['required', 'array', 'min:1', 'max:500'],
            'line_ids.*' => ['integer', LedgerRule::exists('bank_statement_lines', $context)],
            'mode' => ['required', Rule::in(['entry', 'rule', 'bank_charge', 'interest'])],
            'category_id' => ['nullable', 'integer', LedgerRule::exists('categories', $context)->whereNull('deleted_at')],
        ], ['line_ids.required' => 'Tick at least one line.']);

        try {
            $result = $actions->createEntries($reconciliation, $request->user(), $validated['line_ids'], $validated['mode'], isset($validated['category_id']) ? (int) $validated['category_id'] : null);
        } catch (ReconciliationRefused $e) {
            return $this->refused($e);
        }

        $back = back();
        if ($result['created'] > 0) {
            $back->with('status', $result['created'].($result['created'] === 1 ? ' entry' : ' entries').' created and matched.');
        }
        if ($result['failed'] !== []) {
            $back->withErrors(['bank' => count($result['failed']).' could not be done: '.implode(' ', array_unique(array_column($result['failed'], 'message')))]);
        }

        return $back;
    }

    public function transfer(Request $request, BankReconciliation $reconciliation, LineActions $actions): RedirectResponse
    {
        $this->work();
        $this->authorize('record-entries');
        $context = $this->ledger();

        $validated = $request->validate([
            'line_id' => ['required', 'integer', LedgerRule::exists('bank_statement_lines', $context)],
            'other_account_id' => ['required', 'integer', LedgerRule::exists('accounts', $context)->whereNull('deleted_at')],
            'other_line_id' => ['nullable', 'integer', LedgerRule::exists('bank_statement_lines', $context)],
        ]);

        try {
            $result = $actions->createTransfer($reconciliation, $request->user(), (int) $validated['line_id'], (int) $validated['other_account_id'], isset($validated['other_line_id']) ? (int) $validated['other_line_id'] : null);
        } catch (ReconciliationRefused $e) {
            return $this->refused($e);
        }

        return back()->with('status', 'Transfer recorded and this side matched.'.($result['other_matched'] ? ' The other account\'s line was matched too.' : ' The other side will match when you reconcile that account.'));
    }

    public function settle(Request $request, BankReconciliation $reconciliation, LineActions $actions, DocumentSources $documents): RedirectResponse
    {
        $this->work();
        $this->authorize('record-payment');
        $context = $this->ledger();

        $validated = $request->validate([
            'line_id' => ['required', 'integer', LedgerRule::exists('bank_statement_lines', $context)],
            'document_type' => ['required', 'string', 'max:60'],
            'document_id' => ['required', 'integer'],
        ]);

        $line = BankStatementLine::query()->where('account_id', $reconciliation->account_id)->findOrFail($validated['line_id']);
        $text = MatchText::prepare($line->description, $line->reference);

        // The browser names a document; the document source says whether it really is a candidate for this line.
        $candidates = $documents->candidates($context, [['id' => $line->id, 'cents' => $line->amount_cents, 'currency' => $reconciliation->currency, 'text' => $text['text'], 'docs' => $text['docs']]])[$line->id] ?? [];
        $document = collect($candidates)->first(fn ($d) => $d->type === $validated['document_type'] && $d->id === (int) $validated['document_id']);

        if ($document === null) {
            return back()->withErrors(['bank' => 'That invoice or bill is not an open candidate for this line (any more).']);
        }

        try {
            $actions->settleDocument($reconciliation, $request->user(), $line, $document);
        } catch (ReconciliationRefused|AllocationRejected $e) {
            return back()->withErrors(['bank' => $e->getMessage()]);
        }

        return back()->with('status', $document->number.' settled and the line matched.');
    }

    public function difference(Request $request, BankReconciliation $reconciliation, LineActions $actions): RedirectResponse
    {
        $this->work();
        $this->authorize('record-entries');
        $context = $this->ledger();

        $validated = $request->validate([
            'line_id' => ['required', 'integer', LedgerRule::exists('bank_statement_lines', $context)],
            'entry_id' => ['required', 'integer', LedgerRule::exists('transactions', $context)],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ]);

        try {
            $actions->matchWithDifference($reconciliation, $request->user(), (int) $validated['line_id'], (int) $validated['entry_id'], $validated['reason']);
        } catch (ReconciliationRefused $e) {
            return $this->refused($e);
        }

        return back()->with('status', 'The difference was recorded as its own entry and the line matched.');
    }

    public function complete(Request $request, BankReconciliation $reconciliation, ReconciliationService $service): RedirectResponse
    {
        $this->work();

        $validated = $request->validate([
            'accept_variance' => ['nullable', 'boolean'],
            'variance_reason' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $done = $service->complete($reconciliation, $request->user(), (bool) ($validated['accept_variance'] ?? false), $validated['variance_reason'] ?? null);
        } catch (ReconciliationRefused $e) {
            return $this->refused($e);
        }

        return redirect()->route('bank.reconciliation.show', $done)->with('status', 'Reconciled to '.$done->statement_date->toDateString().'. The entries it cleared are now locked.');
    }

    public function reopen(Request $request, BankReconciliation $reconciliation, ReconciliationService $service): RedirectResponse
    {
        $this->work();
        $this->authorize('post-journal');

        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']]);

        try {
            $service->reopen($reconciliation, $request->user(), $validated['reason']);
        } catch (ReconciliationRefused $e) {
            return $this->refused($e);
        }

        return back()->with('status', 'Reopened. The entries it locked can be changed again once they are un-matched; the reason is in the history.');
    }
}
