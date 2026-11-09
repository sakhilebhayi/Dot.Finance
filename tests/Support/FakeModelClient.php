<?php

namespace Tests\Support;

use App\Contracts\Intelligence\ModelClient;

/** A model that says whatever the test tells it to, and remembers what it was asked. */
final class FakeModelClient implements ModelClient
{
    /** @var list<array{system: string, user: string}> */
    public array $calls = [];

    public function __construct(public ?string $reply = null, public bool $on = true, public bool $throws = false) {}

    public function enabled(): bool
    {
        return $this->on;
    }

    public function complete(string $system, string $user, int $maxTokens = 400): ?string
    {
        $this->calls[] = ['system' => $system, 'user' => $user];

        if ($this->throws) {
            throw new \RuntimeException('model down');
        }

        return $this->reply;
    }
}
