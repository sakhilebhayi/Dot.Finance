<?php

namespace App\Http\Controllers\Accounting;

use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Rules\MoneyAmount;
use App\Services\Accounting\Exceptions\AccountingException;
use App\Services\Accounting\GlQuery;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Support\Ledger\LedgerContext;
use App\Support\Ledger\LedgerRule;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class JournalController extends AccountingController
{
    private const MANUAL_LINES = 6;

    public function index(Request $request): View|RedirectResponse
    {
        $this->access();
        if ($redirect = $this->unlessEnabled()) {
            return $redirect;
        }

        $validated = $request->validate([
            'source' => ['nullable', Rule::in(PostingRequest::SOURCE_TYPES)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $entries = JournalEntry::query()
            ->when($validated['source'] ?? null, fn ($q, $s) => $q->where('source_type', $s))
            ->when($validated['from'] ?? null, fn ($q, $d) => $q->where('entry_date', '>=', $d))
            ->when($validated['to'] ?? null, fn ($q, $d) => $q->where('entry_date', '<=', $d))
            ->orderByDesc('entry_number')
            ->paginate(25)->withQueryString();

        return view('accounting.journals.index', ['entries' => $entries, 'filters' => $validated, 'sources' => PostingRequest::SOURCE_TYPES, 'canPost' => $this->access()->canPost()]);
    }

    public function show(JournalEntry $entry, GlQuery $query): View
    {
        $this->access();
        $entry->load(['lines.glAccount', 'lines.cashAccount', 'lines.category', 'reverses', 'reversedBy', 'period']);

        return view('accounting.journals.show', [
            'entry' => $entry,
            'source' => $query->source($entry),
            'canPost' => $this->access()->canPost(),
            'reversible' => $entry->source_type === 'manual' && ! $entry->isReversed(),
        ]);
    }

    public function create(): View|RedirectResponse
    {
        $this->require('canPost');
        if ($redirect = $this->unlessEnabled()) {
            return $redirect;
        }

        return view('accounting.journals.create', ['accounts' => $this->postableAccounts(), 'lineCount' => self::MANUAL_LINES]);
    }

    public function store(Request $request, JournalPoster $poster): RedirectResponse
    {
        $this->require('canPost');
        if ($redirect = $this->unlessEnabled()) {
            return $redirect;
        }

        $context = $this->ledger();

        $validated = $request->validate([
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'memo' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.account_id' => ['nullable', 'integer', LedgerRule::exists('gl_accounts', $context)],
            'lines.*.debit' => ['nullable', new MoneyAmount(allowZero: true)],
            'lines.*.credit' => ['nullable', new MoneyAmount(allowZero: true)],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $lines = [];
        foreach ($validated['lines'] as $i => $row) {
            $debit = filled($row['debit'] ?? null) ? Money::parse((string) $row['debit']) : 0;
            $credit = filled($row['credit'] ?? null) ? Money::parse((string) $row['credit']) : 0;

            if (blank($row['account_id'] ?? null) && $debit === 0 && $credit === 0) {
                continue; // an unused row
            }

            if (blank($row['account_id'] ?? null) || ($debit === 0) === ($credit === 0)) {
                throw ValidationException::withMessages(["lines.{$i}.account_id" => 'Each used line needs an account and either a debit or a credit (not both, not neither).']);
            }

            $lines[] = new PostingLine((int) $row['account_id'], $debit, $credit, description: $row['description'] ?? null);
        }

        if (count($lines) < 2) {
            throw ValidationException::withMessages(['lines' => 'A journal entry needs at least two lines.']);
        }

        $this->refuseCashMappedAccounts($lines, $context);

        try {
            $entry = DB::transaction(fn () => $poster->post(new PostingRequest(
                CarbonImmutable::parse($validated['entry_date']), 'manual', (string) Str::uuid(), 1, $validated['memo'], $lines, $request->user()->id,
            )));
        } catch (AccountingException $e) {
            return back()->withErrors(['entry' => $e->getMessage()])->withInput();
        }

        return redirect()->route('accounting.journals.show', $entry)->with('status', "Entry {$entry->reference()} posted.");
    }

    public function reverse(Request $request, JournalEntry $entry, JournalPoster $poster): RedirectResponse
    {
        $this->require('canPost');
        $validated = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        // Entries made from a transaction or transfer follow their source; reversing them by hand would put the
        // journals out of step with the cash ledger. Only manual entries are reversed here.
        if ($entry->source_type !== 'manual') {
            return back()->withErrors(['reason' => 'This entry was posted from '.str_replace('_', ' ', $entry->source_type).'. Change or delete that record instead and the journal follows.']);
        }

        try {
            $reversal = $poster->reverse($entry, $validated['reason']);
        } catch (AccountingException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('accounting.journals.show', $reversal)->with('status', "Entry {$entry->reference()} reversed by {$reversal->reference()}.");
    }

    /** @return \Illuminate\Support\Collection<int, GlAccount> */
    private function postableAccounts()
    {
        return GlAccount::query()->where('is_active', true)->where('is_postable', true)->orderBy('code')->get();
    }

    /**
     * A GL account that stands for a cash account moves only through the cash ledger (a transaction or transfer),
     * otherwise the bank balance and the cash ledger would drift apart.
     *
     * @param  list<PostingLine>  $lines
     */
    private function refuseCashMappedAccounts(array $lines, LedgerContext $context): void
    {
        $ids = collect($lines)->pluck('account')->unique()->all();
        $mapped = Account::query()->forContext($context)->withTrashed()->whereIn('gl_account_id', $ids)->first();

        if ($mapped !== null) {
            throw ValidationException::withMessages(['lines' => "The GL account for \"{$mapped->name}\" is a cash account. Record the movement as a transaction or transfer instead, so the cash ledger and the journals stay in step."]);
        }
    }
}
