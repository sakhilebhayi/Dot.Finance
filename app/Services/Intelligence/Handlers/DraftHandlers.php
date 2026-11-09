<?php

namespace App\Services\Intelligence\Handlers;

use App\Contracts\Intelligence\InvoiceReminderSender;
use App\Models\Account;
use App\Models\Category;
use App\Models\Shared\Party;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Intelligence\Answer;
use App\Services\Intelligence\Intent;
use App\Services\Intelligence\RecognisedIntent;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;

/**
 * The questions that end in a proposal. They only ever build the proposal: the assistant stores it as a pending
 * draft, and DraftExecutor is the one class that can turn a draft into a real record, for a signed-in person
 * pressing "Review and confirm". Nothing here writes to the books.
 */
final class DraftHandlers extends Handler
{
    public function __construct(private readonly InvoiceReminderSender $reminders) {}

    public function intents(): array
    {
        return [Intent::DraftTransaction, Intent::DraftCategorise, Intent::DraftReminder];
    }

    public function handle(RecognisedIntent $question, LedgerContext $context, ?User $user = null): Answer
    {
        return match ($question->intent) {
            Intent::DraftTransaction => $this->transaction($question, $context),
            Intent::DraftCategorise => $this->categorise($context),
            default => $this->reminder($question, $context),
        };
    }

    private function transaction(RecognisedIntent $q, LedgerContext $context): Answer
    {
        $link = $this->sources(['Transactions', fx_route('transactions.index')]);

        if ($q->amountCents === null) {
            return Answer::noData(Intent::DraftTransaction, 'I need an amount to draft that. Try "Add an expense of R250 for fuel".', $link);
        }

        $description = trim((string) ($q->entities['description'] ?? ''));
        if (mb_strlen($description) < 3) {
            return Answer::noData(Intent::DraftTransaction, 'I need to know what it was for. Try "Add an expense of R250 for fuel".', $link);
        }

        $type = (string) ($q->entities['type'] ?? 'expense');
        $accounts = Account::query()->forContext($context)->orderBy('name')->get();
        $account = $accounts->count() === 1 ? $accounts->first() : $accounts->first(fn (Account $a) => str_contains($q->text, mb_strtolower($a->name)));
        $category = $this->guessCategory($context, $type, $description, $q->text);

        $payload = [
            'type' => $type,
            'amount' => Money::toDecimalString($q->amountCents),
            'amount_cents' => $q->amountCents,
            'description' => mb_substr(ucfirst($description), 0, 255),
            'date' => (string) ($q->entities['date'] ?? now()->toDateString()),
            'account_id' => $account?->id,
            'category_id' => $category?->id,
            'currency' => $account?->currency,
        ];

        $summary = ucfirst($type).' of '.Money::format($q->amountCents, $account?->currency).' for '.$payload['description'].' on '.$payload['date'];

        return new Answer(
            Intent::DraftTransaction,
            Answer::ANSWERED,
            "I have drafted: {$summary}. Nothing has been recorded yet: review it and confirm to add it.",
            sources: $link,
            notes: array_values(array_filter([
                $account ? null : 'Choose the account when you review it.',
                $category ? 'Suggested category: '.$category->name.' (from your earlier entries).' : 'Choose a category when you review it, or leave it blank.',
            ])),
            drafts: [['kind' => 'transaction', 'payload' => $payload, 'summary' => $summary]],
        );
    }

    private function categorise(LedgerContext $context): Answer
    {
        $link = $this->sources(['Transactions with no category', fx_route('transactions.index', ['category_id' => 'none'])]);

        $uncategorised = Transaction::query()->forContext($context)->counted()
            ->whereIn('type', ['income', 'expense'])->whereNull('category_id')
            ->where('date', '>=', now()->subDays(90)->toDateString())
            ->orderByDesc('date')->limit(40)->get();

        if ($uncategorised->isEmpty()) {
            return new Answer(Intent::DraftCategorise, Answer::ANSWERED, 'Every recent transaction already has a category, so there is nothing to categorise.', sources: $link);
        }

        $history = Transaction::query()->forContext($context)->counted()
            ->whereNotNull('category_id')->whereIn('type', ['income', 'expense'])
            ->selectRaw('lower(description) AS d, type, category_id, COUNT(*) AS n')
            ->groupBy('d', 'type', 'category_id')->get();

        $best = [];
        foreach ($history as $row) {
            $key = $row->type.'|'.$row->d;
            if (! isset($best[$key]) || $row->n > $best[$key]['n']) {
                $best[$key] = ['category_id' => (int) $row->category_id, 'n' => (int) $row->n];
            }
        }

        $names = Category::query()->forContext($context)->pluck('name', 'id');
        $items = [];
        $rows = [];
        foreach ($uncategorised as $t) {
            $guess = $best[$t->type.'|'.mb_strtolower($t->description)] ?? null;
            if ($guess === null || ! isset($names[$guess['category_id']])) {
                continue;
            }

            $items[] = [
                'transaction_id' => $t->id,
                'category_id' => $guess['category_id'],
                'category_name' => (string) $names[$guess['category_id']],
                'description' => (string) $t->description,
                'amount_cents' => (int) $t->amount_cents,
                'currency' => $t->currency,
                'date' => $t->date?->toDateString(),
                'reason' => 'Same description as '.$this->plural($guess['n'], 'earlier entry', 'earlier entries').' in that category',
            ];
            $rows[] = ['cells' => [(string) $t->date?->toDateString(), (string) $t->description, Money::format((int) $t->amount_cents, $t->currency), (string) $names[$guess['category_id']]], 'href' => fx_route('transactions.edit', ['transaction' => $t->id])];

            if (count($items) >= 10) {
                break;
            }
        }

        if ($items === []) {
            return Answer::noData(Intent::DraftCategorise, "I found {$this->plural($uncategorised->count(), 'uncategorised transaction', 'uncategorised transactions')} but none matches an earlier entry I could learn a category from, so I will not guess.", $link);
        }

        $summary = 'Set the category of '.$this->plural(count($items), 'transaction', 'transactions');

        return new Answer(
            Intent::DraftCategorise,
            Answer::ANSWERED,
            'I have drafted categories for '.$this->plural(count($items), 'transaction', 'transactions')." out of {$uncategorised->count()} without one. Nothing has changed yet: review them and confirm.",
            columns: ['Date', 'Description', 'Amount', 'Suggested category'],
            rows: $rows,
            sources: $link,
            notes: ['A suggestion is only made when the same description was categorised before. Confirming changes only the category, nothing else.'],
            drafts: [['kind' => 'categorise', 'payload' => ['items' => $items], 'summary' => $summary]],
        );
    }

    private function reminder(RecognisedIntent $q, LedgerContext $context): Answer
    {
        $link = $this->sources(['Invoices', fx_route(['sales.invoices.index', 'invoices.index'])]);

        if (! $this->reminders->available()) {
            return Answer::noData(Intent::DraftReminder, "I can't draft a reminder: payment reminders are sent from the Sales module, which is not available in this ledger yet.", $link);
        }

        $name = trim((string) ($q->entities['party'] ?? ''));
        if ($name === '') {
            return Answer::noData(Intent::DraftReminder, 'Tell me which customer to remind, for example "Remind Acme about their overdue invoice".', $link);
        }

        $matches = Party::query()->forContext($context)->customers()->whereRaw('lower(name) like ?', ['%'.mb_strtolower($name).'%'])->limit(3)->get();

        if ($matches->count() !== 1) {
            return Answer::noData(Intent::DraftReminder, $matches->isEmpty() ? "I don't have a customer matching \"{$name}\"." : 'More than one customer matches "'.$name.'". Use the full name.', $link);
        }

        $party = $matches->first();
        $summary = 'Send a payment reminder to '.$party->name;

        return new Answer(
            Intent::DraftReminder,
            Answer::ANSWERED,
            "I have drafted a payment reminder for {$party->name}. Nothing has been sent: review it and confirm.",
            sources: $link,
            drafts: [['kind' => 'invoice_reminder', 'payload' => ['party_id' => $party->id, 'party_name' => $party->name], 'summary' => $summary]],
        );
    }

    private function guessCategory(LedgerContext $context, string $type, string $description, string $text): ?Category
    {
        $earlier = Transaction::query()->forContext($context)->counted()
            ->where('type', $type)->whereNotNull('category_id')->whereRaw('lower(description) = ?', [mb_strtolower($description)])
            ->selectRaw('category_id, COUNT(*) AS n')->groupBy('category_id')->orderByDesc('n')->first();

        if ($earlier !== null) {
            return Category::query()->forContext($context)->where('type', $type)->find($earlier->category_id);
        }

        return Category::query()->forContext($context)->where('type', $type)->get()
            ->first(fn (Category $c) => mb_strlen($c->name) >= 3 && str_contains($text, mb_strtolower($c->name)));
    }
}
