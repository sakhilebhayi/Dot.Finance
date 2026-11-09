<?php

namespace App\Services\Intelligence;

use App\Contracts\Intelligence\ModelClient;
use Illuminate\Support\Facades\Http;

/** The Anthropic Messages API over the app's HTTP client. Returns null on any failure: the assistant never depends on it. */
final class AnthropicClient implements ModelClient
{
    public function enabled(): bool
    {
        return filled(config('services.anthropic.api_key'));
    }

    public function complete(string $system, string $user, int $maxTokens = 400): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $response = Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.api_key'),
            'anthropic-version' => '2023-06-01',
        ])->acceptJson()->timeout((int) config('intelligence.model.timeout', 12))->post('https://api.anthropic.com/v1/messages', [
            'model' => (string) config('services.anthropic.model'),
            'max_tokens' => $maxTokens,
            'temperature' => 0,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
        ]);

        if (! $response->successful()) {
            return null;
        }

        $text = collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode('');

        return $text !== '' ? $text : null;
    }
}
