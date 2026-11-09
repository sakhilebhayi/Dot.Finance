<?php

namespace App\Services\Intelligence;

use App\Enums\Capability;
use App\Models\Intelligence\AssistantDraft;
use App\Models\Intelligence\AssistantMessage;
use App\Models\User;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Answers a question about one ledger from that ledger's own data, and nothing else.
 *
 *  1. The rules recognise the question (a model may help only to choose among the same fixed intents).
 *  2. A handler reads the ledger through existing services, inside the named ledger context, and returns a
 *     structured Answer. If the ledger cannot answer, the answer says "I don't have that information".
 *  3. The sentence is the one the handler worded from its numbers. An optional model may reword it, and the
 *     rewording is discarded unless every number in it is in those numbers (NumberGuard).
 *  4. The exchange is stored for the person who asked, with the structured answer beside the sentence.
 *  5. A proposal (a draft transaction, a categorisation, a reminder) is stored as a PENDING draft. It changes
 *     nothing: only DraftExecutor, run for a person pressing "Review and confirm", can.
 */
final class FinanceAssistant
{
    public function __construct(
        private readonly IntentRecognizer $recognizer,
        private readonly ModelIntentClassifier $classifier,
        private readonly ModelProseWriter $writer,
        private readonly HandlerRegistry $handlers,
    ) {}

    public function ask(User $user, LedgerContext $context, string $question): AssistantMessage
    {
        $access = LedgerAccess::for($user, $context);

        if ($access === null || ! $access->can(Capability::ViewFinance)) {
            throw new AuthorizationException('You may not ask about this ledger.');
        }

        $question = mb_substr(trim(preg_replace('/\s+/u', ' ', $question) ?? ''), 0, 500);

        $recognised = $this->recognizer->recognise($question, CarbonImmutable::now());

        if ($recognised->intent === Intent::Unknown && ($guess = $this->classifier->classify($question)) !== null) {
            $recognised = $recognised->with($guess);
        }

        $answer = $this->answer($recognised, $context, $user, $access);

        $rewritten = $this->writer->rewrite($answer);
        $answer = $rewritten['answer'];

        return DB::transaction(function () use ($user, $context, $question, $recognised, $answer, $rewritten) {
            $message = AssistantMessage::create($context->ownerAttributes() + [
                'asker_id' => $user->id,
                'question' => $question,
                'intent' => $recognised->intent->value,
                'status' => $answer->status,
                'answer_text' => $answer->sentence,
                'answer' => $answer->toArray(),
                'basis' => $answer->basis->value,
                'model_used' => $rewritten['used'],
                'model_rejected' => $rewritten['rejected'],
            ]);

            if ($answer->isAnswered()) {
                foreach ($answer->drafts as $proposal) {
                    AssistantDraft::create($context->ownerAttributes() + [
                        'asker_id' => $user->id,
                        'message_id' => $message->id,
                        'kind' => $proposal['kind'],
                        'payload' => $proposal['payload'],
                        'summary' => mb_substr($proposal['summary'], 0, 300),
                        'status' => 'pending',
                        'expires_at' => now()->addHours((int) config('intelligence.assistant.draft_hours', 24)),
                    ]);
                }
            }

            return LedgerContext::run($context, fn () => $message->load('drafts'));
        });
    }

    private function answer(RecognisedIntent $q, LedgerContext $context, User $user, LedgerAccess $access): Answer
    {
        if ($q->intent === Intent::Unknown) {
            return new Answer(
                Intent::Unknown,
                Answer::UNRECOGNISED,
                "I can't answer that one. I can answer questions about your spending, cash, who owes you, bills, profit, VAT, budgets and what needs attention, and draft a transaction for you to confirm.",
                notes: ['I only answer from the entries recorded in this ledger.'],
            );
        }

        $draftNeeds = match ($q->intent) {
            Intent::DraftTransaction, Intent::DraftCategorise => Capability::RecordEntries,
            Intent::DraftReminder => Capability::IssueInvoice,
            default => null,
        };

        if ($draftNeeds !== null && ! $access->can($draftNeeds)) {
            return new Answer($q->intent, Answer::REFUSED, 'You can read this ledger but your role does not let you add or change entries, so I cannot draft that for you.');
        }

        $handler = $this->handlers->for($q->intent);

        if ($handler === null) {
            return Answer::noData($q->intent, "I don't have that information.");
        }

        try {
            return LedgerContext::run($context, fn () => $handler->handle($q, $context, $user));
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Assistant handler failed.', ['intent' => $q->intent->value, 'error' => class_basename($e)]);

            return Answer::noData($q->intent, "I couldn't read the ledger just now, so I can't answer. Nothing was changed. Please try again.");
        }
    }
}
