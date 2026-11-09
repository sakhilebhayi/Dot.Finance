<?php

namespace App\Services\Intelligence;

use App\Contracts\Intelligence\ModelClient;
use Illuminate\Support\Facades\Log;

/**
 * Optional friendlier wording for an answer that has already been computed. The model sees the computed sentence
 * and figures and nothing else; it must not add, drop or change a number. Its reply is checked by NumberGuard and
 * thrown away on any violation, in which case the computed sentence stands. Off unless FINANCE_AI_ENABLED is true
 * and a key is set. Errors, timeouts and empty replies are the same as "not used".
 */
final class ModelProseWriter
{
    public function __construct(private readonly ModelClient $client) {}

    public function enabled(): bool
    {
        return (bool) config('intelligence.model.enabled') && $this->client->enabled();
    }

    /** @return array{answer: Answer, used: bool, rejected: bool} */
    public function rewrite(Answer $answer): array
    {
        if (! $this->enabled() || $answer->status !== Answer::ANSWERED) {
            return ['answer' => $answer, 'used' => false, 'rejected' => false];
        }

        $system = 'You reword one finance answer for a small-business owner in plain, calm English (South African spelling). '
            .'Rules: use ONLY the figures in the facts; never add, round, convert, total, or compare any number; never invent a name, '
            .'date, cause or advice; do not give financial advice; at most two sentences; no markdown, no links. '
            .'If you cannot reword it without changing a figure, repeat the original sentence exactly.';

        $user = "Original sentence:\n{$answer->sentence}\n\nFacts:\n".$answer->figuresText();

        try {
            $reply = $this->client->complete($system, $user, (int) config('intelligence.model.max_tokens', 400));
        } catch (\Throwable $e) {
            Log::warning('Assistant model call failed; using the computed sentence.', ['error' => class_basename($e)]);

            return ['answer' => $answer, 'used' => false, 'rejected' => false];
        }

        $reply = is_string($reply) ? trim($reply) : '';
        if ($reply === '') {
            return ['answer' => $answer, 'used' => false, 'rejected' => false];
        }

        $problems = NumberGuard::violations($reply, $answer->figuresText());
        if ($problems !== []) {
            Log::info('Assistant model wording rejected.', ['problems' => count($problems)]);

            return ['answer' => $answer, 'used' => false, 'rejected' => true];
        }

        return ['answer' => $answer->withSentence($reply, Basis::AiSummary), 'used' => true, 'rejected' => false];
    }
}
