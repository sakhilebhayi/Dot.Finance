<?php

namespace App\Services\Intelligence;

/**
 * A structured answer: the figures (facts and rows), where each came from (sources), what kind of statement it is
 * (basis) and one plain sentence worded from those figures. Every number a person is shown, and every number a
 * model is allowed to repeat, is in here.
 *
 *  - answered: figures found.
 *  - no_data: the question was understood but the ledger has nothing to answer it with. The sentence says
 *    "I don't have that information" and what would create it. Never a guess, never a zero in disguise.
 *  - refused: understood, but this person may not see it.
 *  - unrecognised: not one of the questions the assistant can answer.
 */
final class Answer
{
    public const ANSWERED = 'answered';

    public const NO_DATA = 'no_data';

    public const REFUSED = 'refused';

    public const UNRECOGNISED = 'unrecognised';

    /** @var list<string> */
    public readonly array $notes;

    /**
     * @param  list<array{label: string, value: string, tone?: string}>  $facts
     * @param  list<string>  $columns
     * @param  list<array{cells: list<string>, href?: string|null}>  $rows
     * @param  list<array{label: string, href: string}>  $sources
     * @param  list<string>  $notes  assumptions and caveats, shown with the answer
     * @param  list<array{kind: string, payload: array<string, mixed>, summary: string}>  $drafts
     */
    public function __construct(
        public readonly Intent $intent,
        public readonly string $status,
        public readonly string $sentence,
        public readonly array $facts = [],
        public readonly array $columns = [],
        public readonly array $rows = [],
        public readonly array $sources = [],
        public readonly Basis $basis = Basis::Calculated,
        array $notes = [],
        public readonly array $drafts = [],
    ) {
        $this->notes = array_values(array_filter($notes, fn ($n) => is_string($n) && $n !== ''));
    }

    public static function noData(Intent $intent, string $sentence, array $sources = [], array $notes = []): self
    {
        return new self($intent, self::NO_DATA, $sentence, sources: $sources, notes: $notes);
    }

    public function isAnswered(): bool
    {
        return $this->status === self::ANSWERED;
    }

    public function withSentence(string $sentence, Basis $basis): self
    {
        return new self($this->intent, $this->status, $sentence, $this->facts, $this->columns, $this->rows, $this->sources, $basis, $this->notes, $this->drafts);
    }

    public function withDrafts(array $drafts): self
    {
        return new self($this->intent, $this->status, $this->sentence, $this->facts, $this->columns, $this->rows, $this->sources, $this->basis, $this->notes, $drafts);
    }

    /** Everything numeric a rewrite may repeat: the computed sentence, facts, rows and notes, as one string. */
    public function figuresText(): string
    {
        $parts = [$this->sentence];
        foreach ($this->facts as $fact) {
            $parts[] = $fact['label'].' '.$fact['value'];
        }
        foreach ($this->rows as $row) {
            $parts[] = implode(' ', $row['cells']);
        }

        return implode("\n", array_merge($parts, $this->notes));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent->value,
            'status' => $this->status,
            'facts' => $this->facts,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'sources' => $this->sources,
            'notes' => $this->notes,
            'drafts' => $this->drafts,
        ];
    }
}
