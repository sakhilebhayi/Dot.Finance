<?php

namespace App\Services\Intelligence;

/** What the recogniser made of a question: the intent, how sure the rules are, and the details it found. */
final class RecognisedIntent
{
    /** @param array<string, mixed> $entities */
    public function __construct(
        public readonly Intent $intent,
        public readonly int $score,
        public readonly Period $period,
        public readonly int $horizonDays,
        public readonly ?int $amountCents,
        public readonly string $text,
        public readonly array $entities = [],
    ) {}

    public function with(Intent $intent): self
    {
        return new self($intent, $this->score, $this->period, $this->horizonDays, $this->amountCents, $this->text, $this->entities);
    }
}
