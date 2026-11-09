<?php

namespace App\Services\Intelligence;

use App\Contracts\Intelligence\InvoiceReminderSender;
use App\Enums\ApprovalSubject;
use App\Enums\Capability;
use App\Models\Account;
use App\Models\Category;
use App\Models\Intelligence\AssistantDraft;
use App\Models\Shared\Party;
use App\Models\Transaction;
use App\Models\User;
use App\Rules\MoneyAmount;
use App\Services\Accounting\Exceptions\PeriodClosed;
use App\Services\Accounting\GlSync;
use App\Services\Controls\ApprovalService;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use App\Support\Ledger\LedgerRule;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The ONLY place an assistant draft becomes a real record, and only when called from the confirmation route for
 * the signed-in person who was shown the draft. It goes through the same steps as the normal screens:
 *
 *   person   the draft must be theirs, in the ledger they have open, still pending and not expired;
 *   rights   the same capability the normal screen needs (record-entries, issue-invoice) in that ledger;
 *   rules    the same validation as the transaction form, the approval rules (an amount that needs approval is
 *            refused here and goes through the normal flow), the closed-period rule (via GlSync), the audit log;
 *   once     the draft is locked and re-read inside the transaction, so a double click or a replay does nothing.
 *
 * Nothing else in App\Services\Intelligence may call the services that write the books (a test enforces it).
 */
final class DraftExecutor
{
    public function __construct(
        private readonly GlSync $gl,
        private readonly InvoiceReminderSender $reminders,
    ) {}

    /**
     * @param  array<string, mixed>  $input  what the person chose on the review screen
     */
    public function confirm(AssistantDraft $draft, User $user, array $input = []): AssistantDraft
    {
        $context = $this->guard($draft, $user);

        // Capability first, so a person without it learns nothing about the draft's contents.
        $needed = match ($draft->kind) {
            'invoice_reminder' => Capability::IssueInvoice,
            default => Capability::RecordEntries,
        };
        if (! LedgerAccess::allows($needed, $user, $context)) {
            throw new AuthorizationException('You do not have the rights in this ledger to confirm this.');
        }

        $result = DB::transaction(function () use ($draft, $user, $input, $context) {
            $locked = AssistantDraft::query()->forContext($context)->lockForUpdate()->findOrFail($draft->id);

            if ($locked->status === 'confirmed') {
                return $locked; // a replay: the record already exists, nothing more happens
            }

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['draft' => 'This draft is no longer open ('.$locked->status.').']);
            }

            if (! $locked->expires_at->isFuture()) {
                // Recorded (the transaction commits), then refused below.
                $locked->forceFill(['status' => 'expired'])->save();

                return $locked;
            }

            [$type, $id] = FinanceAudit::withContext('Confirmed from an assistant draft', fn () => match ($locked->kind) {
                'transaction' => $this->transaction($locked, $user, $input, $context),
                'categorise' => $this->categorise($locked, $input, $context),
                'invoice_reminder' => $this->reminder($locked, $user, $context),
                default => throw ValidationException::withMessages(['draft' => 'Unknown draft.']),
            });

            $locked->forceFill(['status' => 'confirmed', 'decided_by' => $user->id, 'decided_at' => now(), 'result_type' => $type, 'result_id' => $id])->save();

            FinanceAudit::record($context->ownerAttributes(), 'confirmed', 'assistant_draft', $locked->id, $locked->auditLabel(), ['result' => ['type' => $type, 'id' => $id]], 'Confirmed from an assistant draft', null, $user->id);

            return $locked;
        });

        if ($result->status === 'expired') {
            throw ValidationException::withMessages(['draft' => 'This draft has expired. Ask again to get a fresh one.']);
        }

        return $result;
    }

    public function dismiss(AssistantDraft $draft, User $user): AssistantDraft
    {
        $context = $this->guard($draft, $user);

        return DB::transaction(function () use ($draft, $user, $context) {
            $locked = AssistantDraft::query()->forContext($context)->lockForUpdate()->findOrFail($draft->id);

            if ($locked->status === 'pending') {
                $locked->forceFill(['status' => 'dismissed', 'decided_by' => $user->id, 'decided_at' => now()])->save();
                FinanceAudit::record($context->ownerAttributes(), 'dismissed', 'assistant_draft', $locked->id, $locked->auditLabel(), null, null, null, $user->id);
            }

            return $locked;
        });
    }

    private function guard(AssistantDraft $draft, User $user): LedgerContext
    {
        $context = LedgerContext::current();

        if ($context === null || ! $context->equals($draft->ledgerContext()) || $draft->asker_id !== $user->id) {
            throw new AuthorizationException('This draft is not yours to confirm.');
        }

        return $context;
    }

    /** @return array{0: string, 1: int} */
    private function transaction(AssistantDraft $draft, User $user, array $input, LedgerContext $context): array
    {
        $data = Validator::make($input + ($draft->payload ?? []), [
            'account_id' => ['required', 'integer', LedgerRule::exists('accounts', $context)->whereNull('deleted_at')],
            'category_id' => ['nullable', 'integer', LedgerRule::exists('categories', $context)->whereNull('deleted_at')],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', new MoneyAmount],
            'type' => ['required', Rule::in(['income', 'expense'])],
            'date' => ['required', 'date_format:Y-m-d'],
        ])->validate();

        if (! empty($data['category_id'])) {
            $category = Category::query()->forContext($context)->find($data['category_id']);
            if ($category !== null && $category->type !== $data['type']) {
                throw ValidationException::withMessages(['category_id' => "\"{$category->name}\" is an {$category->type} category, so it cannot be used on an {$data['type']}."]);
            }
        }

        $account = Account::query()->forContext($context)->findOrFail($data['account_id']);

        if ($data['type'] === 'expense' && ApprovalService::requiresApproval(ApprovalSubject::Expense->value, Money::parse((string) $data['amount']), $account->currency, $context)) {
            throw ValidationException::withMessages(['draft' => 'An expense of this size needs approval under your approval rules, so it cannot be added from a draft. Record it through the normal expense flow.']);
        }

        try {
            $transaction = Transaction::create([
                'account_id' => $account->id,
                'category_id' => $data['category_id'] ?? null,
                'description' => $data['description'],
                'amount' => $data['amount'],
                'type' => $data['type'],
                'date' => $data['date'],
                'notes' => 'Added from an assistant draft, confirmed by '.$user->name.'.',
            ] + $context->ownerAttributes());

            $this->gl->transaction($transaction);
        } catch (PeriodClosed $e) {
            throw ValidationException::withMessages(['date' => $e->getMessage().' Choose a date in an open period.']);
        }

        return ['transaction', $transaction->id];
    }

    /** @return array{0: string, 1: int} */
    private function categorise(AssistantDraft $draft, array $input, LedgerContext $context): array
    {
        $proposed = collect($draft->payload['items'] ?? [])->keyBy('transaction_id');
        $chosen = collect($input['items'] ?? $proposed->keys()->all())->map(fn ($id) => (int) $id)->filter(fn ($id) => $proposed->has($id))->unique()->values();

        if ($chosen->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Choose at least one transaction to categorise.']);
        }

        $changed = 0;
        foreach ($chosen as $transactionId) {
            $item = $proposed[$transactionId];
            $transaction = Transaction::query()->forContext($context)->lockForUpdate()->find($transactionId);
            $category = Category::query()->forContext($context)->find($item['category_id']);

            // Only a transaction that is still uncategorised, not a transfer, with a category of the right type.
            if ($transaction === null || $category === null || $transaction->category_id !== null || $transaction->isTransfer() || $category->type !== $transaction->type) {
                continue;
            }

            try {
                $transaction->update(['category_id' => $category->id]);
                $this->gl->transaction($transaction->unsetRelation('account'));
                $changed++;
            } catch (PeriodClosed) {
                // It sits in a closed period: leave it alone rather than rewrite closed books.
                continue;
            }
        }

        if ($changed === 0) {
            throw ValidationException::withMessages(['items' => 'None of these could be changed: they have a category now, or sit in a closed period.']);
        }

        return ['categorisation', $changed];
    }

    /** @return array{0: string, 1: int} */
    private function reminder(AssistantDraft $draft, User $user, LedgerContext $context): array
    {
        if (! $this->reminders->available()) {
            throw ValidationException::withMessages(['draft' => 'Payment reminders are not available in this ledger.']);
        }

        $party = Party::query()->forContext($context)->customers()->findOrFail($draft->payload['party_id'] ?? 0);
        $this->reminders->send($party, $user);

        return ['party', $party->id];
    }
}
