<?php

namespace App\Contracts\Intelligence;

/** A language-model call: one system prompt, one user message, one text reply. Null when it fails or is off. */
interface ModelClient
{
    public function enabled(): bool;

    public function complete(string $system, string $user, int $maxTokens = 400): ?string;
}
