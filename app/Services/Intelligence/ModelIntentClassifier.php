<?php

namespace App\Services\Intelligence;

use App\Contracts\Intelligence\ModelClient;
use Illuminate\Support\Facades\Log;

/**
 * Optional help for a question the rules did not understand: the model is asked to pick ONE intent from the fixed
 * list, and its reply must be exactly one of those names. It cannot invent an intent, a figure or a query, and it
 * never sees ledger data, only the question text. Off with the rest of the model layer.
 */
final class ModelIntentClassifier
{
    public function __construct(private readonly ModelClient $client) {}

    public function enabled(): bool
    {
        return (bool) config('intelligence.model.enabled') && $this->client->enabled();
    }

    public function classify(string $question): ?Intent
    {
        if (! $this->enabled()) {
            return null;
        }

        $names = array_values(array_filter(array_map(fn (Intent $i) => $i->isDraft() || $i === Intent::Unknown ? null : $i->value, Intent::cases())));

        try {
            $reply = $this->client->complete(
                'Choose which ONE of these question types the user is asking, and answer with only its name, or "unknown": '.implode(', ', $names).'.',
                mb_substr($question, 0, 300),
                20,
            );
        } catch (\Throwable $e) {
            Log::warning('Assistant intent model failed.', ['error' => class_basename($e)]);

            return null;
        }

        $name = is_string($reply) ? strtolower(trim($reply, " \t\n\r\0\x0B\"'`.")) : '';
        $intent = in_array($name, $names, true) ? Intent::from($name) : null;

        return $intent;
    }
}
