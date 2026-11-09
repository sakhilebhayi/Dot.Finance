<?php

namespace App\Services\Bank;

use App\Support\Money;

/**
 * Proposes which statement lines and which cash-ledger rows say the same thing, with a score and the reason in words.
 * Pure: it is given prepared arrays and returns proposals; it reads and writes nothing, so it is tested on its own and
 * the same code answers a 20-line statement and a 5,000-line one.
 *
 * One to one: a line and a row with the SAME signed amount within a date window. The score is built from evidence and
 * the reasons say which evidence:
 *
 *    same amount            40   (the entry condition)
 *    same day / 1 / 2 / 3 days apart / within the window     30 / 25 / 20 / 15 / 8
 *    same description       25   or shared words up to 20
 *    the same document number in both (INV-0042)             20
 *    a recorded payment carrying a reference found in the line   10
 *    the only row for this amount near this date, and the only line for it   +15 (within a day)
 *
 * Two rows with the same amount near one line are ambiguous: unless the evidence clearly separates them the score is
 * capped below the automatic threshold, so an ambiguous match is always a suggestion a person confirms, never silent.
 *
 * Splits: one line against two to four rows that add up to it exactly, or several lines against one row. They are
 * always suggestions (never automatic) and say so.
 *
 * Money in the engine is signed integer cents everywhere; there is no float.
 */
final class MatchEngine
{
    /** At or above this a proposal is "matched for you": accepted in one click for the whole list. */
    public const AUTO = 85;

    /** Below this a pairing is not offered at all. */
    public const SUGGEST = 45;

    private const MAX_SPLIT_POOL = 14;

    private const MAX_SPLIT_PARTS = 4;

    private const MAX_SPLIT_SEARCHES = 400;

    private const SPLIT_NODE_BUDGET = 20000;

    public function __construct(private readonly int $windowDays = 7) {}

    /**
     * @param  list<array{id: int, day: int, cents: int, text: string, tokens: list<string>, docs: list<string>}>  $lines
     * @param  list<array{id: int, day: int, cents: int, text: string, tokens: list<string>, docs: list<string>, type?: string, payment_ref?: ?string, payment_ref_text?: ?string}>  $entries
     * @return list<array{line_ids: list<int>, entry_ids: list<int>, shape: string, confidence: int, reason: string, amount_cents: int}>
     */
    public function propose(array $lines, array $entries, string $currency): array
    {
        $proposals = [];
        $usedLines = [];
        $usedEntries = [];

        // ---- one to one ----
        $byCents = [];
        foreach ($entries as $ei => $entry) {
            $byCents[$entry['cents']][] = $ei;
        }

        $pairs = [];
        $perLine = [];
        $perEntry = [];

        foreach ($lines as $li => $line) {
            foreach ($byCents[$line['cents']] ?? [] as $ei) {
                $days = abs($line['day'] - $entries[$ei]['day']);
                if ($days > $this->windowDays) {
                    continue;
                }

                [$score, $reasons] = $this->score($line, $entries[$ei], $days, $currency);
                $pairs[] = ['score' => $score, 'days' => $days, 'li' => $li, 'ei' => $ei, 'reasons' => $reasons];
                $perLine[$li][] = $score;
                $perEntry[$ei][] = $score;
            }
        }

        foreach ($pairs as &$pair) {
            $lineScores = $perLine[$pair['li']];
            $entryScores = $perEntry[$pair['ei']];
            $competitors = max(count($lineScores), count($entryScores));

            if ($competitors === 1 && $pair['days'] <= 1) {
                $pair['score'] = min(100, $pair['score'] + 15);
                $pair['reasons'][] = 'it is the only entry for this amount near this date';
            } elseif ($competitors > 1) {
                $others = array_merge(self::without($lineScores, $pair['score']), self::without($entryScores, $pair['score']));
                $closest = $others === [] ? 0 : max($others);

                // The evidence must clearly favour this pairing, or it stays a suggestion.
                if ($pair['score'] - $closest < 15) {
                    $pair['score'] = min($pair['score'], max(self::SUGGEST, 80 - 5 * ($competitors - 1)));
                    $pair['reasons'][] = ($competitors - 1).' other '.($competitors - 1 === 1 ? 'entry has' : 'entries have').' the same amount near this date, so check it is the right one';
                }
            }
        }
        unset($pair);

        usort($pairs, fn ($a, $b) => [$b['score'], $a['days'], $a['li'], $a['ei']] <=> [$a['score'], $b['days'], $b['li'], $b['ei']]);

        foreach ($pairs as $pair) {
            if ($pair['score'] < self::SUGGEST || isset($usedLines[$pair['li']]) || isset($usedEntries[$pair['ei']])) {
                continue;
            }

            $usedLines[$pair['li']] = true;
            $usedEntries[$pair['ei']] = true;
            $line = $lines[$pair['li']];
            $proposals[] = [
                'line_ids' => [$line['id']],
                'entry_ids' => [$entries[$pair['ei']]['id']],
                'shape' => 'one_to_one',
                'confidence' => $pair['score'],
                'reason' => self::sentence($pair['reasons']),
                'amount_cents' => $line['cents'],
            ];
        }

        // ---- splits ----
        foreach ($this->splits($lines, $entries, $usedLines, $usedEntries, $currency) as $split) {
            $proposals[] = $split;
        }

        usort($proposals, fn ($a, $b) => [$b['confidence'], $a['line_ids'][0]] <=> [$a['confidence'], $b['line_ids'][0]]);

        return $proposals;
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $entry
     * @return array{0: int, 1: list<string>}
     */
    private function score(array $line, array $entry, int $days, string $currency): array
    {
        $score = 40;
        $reasons = ['same amount ('.Money::format(abs($line['cents']), $currency).')'];

        [$points, $label] = match (true) {
            $days === 0 => [30, 'same day'],
            $days === 1 => [25, '1 day apart'],
            $days === 2 => [20, '2 days apart'],
            $days === 3 => [15, '3 days apart'],
            default => [8, "{$days} days apart"],
        };
        $score += $points;
        $reasons[] = $label;

        if ($line['text'] !== '' && $line['text'] === $entry['text']) {
            $score += 25;
            $reasons[] = 'the same description';
        } else {
            $common = array_values(array_intersect($line['tokens'], $entry['tokens']));
            if ($common !== []) {
                $ratio = count($common) / max(1, min(count($line['tokens']), count($entry['tokens'])));
                $score += max(8, (int) round(20 * $ratio));
                $reasons[] = "both mention \"{$common[0]}\"";
            }
        }

        $docs = array_values(array_intersect($line['docs'], $entry['docs']));
        if ($docs !== []) {
            $score += 20;
            $reasons[] = "both refer to {$docs[0]}";
        }

        $paymentRef = $entry['payment_ref'] ?? null;
        if ($paymentRef !== null && $paymentRef !== '' && str_contains($line['text'], mb_strtolower($paymentRef))) {
            $score += 10;
            $reasons[] = "a payment you recorded carries the reference \"{$paymentRef}\"";
        } elseif (($entry['type'] ?? null) === 'transfer') {
            $reasons[] = 'it is a transfer leg you recorded';
        }

        return [min(100, $score), $reasons];
    }

    /** @param  list<string>  $reasons */
    public static function sentence(array $reasons): string
    {
        $text = implode(', ', $reasons);

        return 'Matched because: '.$text.'.';
    }

    /**
     * @param  list<int>  $scores
     * @return list<int>
     */
    private static function without(array $scores, int $score): array
    {
        $found = array_search($score, $scores, true);
        if ($found !== false) {
            unset($scores[$found]);
        }

        return array_values($scores);
    }

    // ── splits ────────────────────────────────────────────────────────────

    /**
     * @param  array<int, true>  $usedLines
     * @param  array<int, true>  $usedEntries
     * @return list<array{line_ids: list<int>, entry_ids: list<int>, shape: string, confidence: int, reason: string, amount_cents: int}>
     */
    private function splits(array $lines, array $entries, array $usedLines, array $usedEntries, string $currency): array
    {
        $out = [];
        $searches = 0;

        // one line, several entries
        $order = array_keys($lines);
        usort($order, fn ($a, $b) => abs($lines[$b]['cents']) <=> abs($lines[$a]['cents']));

        foreach ($order as $li) {
            if (isset($usedLines[$li]) || $searches >= self::MAX_SPLIT_SEARCHES) {
                continue;
            }
            $line = $lines[$li];
            $pool = [];
            foreach ($entries as $ei => $entry) {
                if (isset($usedEntries[$ei]) || ($entry['cents'] > 0) !== ($line['cents'] > 0) || abs($entry['cents']) >= abs($line['cents']) || abs($entry['day'] - $line['day']) > $this->windowDays) {
                    continue;
                }
                $pool[] = $ei;
            }
            if (count($pool) < 2) {
                continue;
            }
            $searches++;

            usort($pool, fn ($a, $b) => [abs($entries[$a]['day'] - $line['day']), $entries[$a]['id']] <=> [abs($entries[$b]['day'] - $line['day']), $entries[$b]['id']]);
            $pool = array_slice($pool, 0, self::MAX_SPLIT_POOL);

            $solutions = $this->subsets(array_map(fn ($ei) => $entries[$ei]['cents'], $pool), $line['cents']);
            if ($solutions === []) {
                continue;
            }

            $best = $this->bestSolution($solutions, $pool, fn ($ei) => $entries[$ei], $line);
            $members = array_map(fn ($k) => $pool[$k], $best);
            $confidence = $this->splitConfidence(count($solutions), array_map(fn ($ei) => $entries[$ei], $members), $line);

            if ($confidence < self::SUGGEST) {
                continue;
            }

            foreach ($members as $ei) {
                $usedEntries[$ei] = true;
            }
            $usedLines[$li] = true;
            $out[] = [
                'line_ids' => [$line['id']],
                'entry_ids' => array_map(fn ($ei) => $entries[$ei]['id'], $members),
                'shape' => 'one_to_many',
                'confidence' => $confidence,
                'reason' => 'Suggested because: '.count($members).' entries ('.implode(' + ', array_map(fn ($ei) => Money::format(abs($entries[$ei]['cents']), $currency), $members)).') add up to this one line exactly'.(count($solutions) > 1 ? ', but other combinations also fit, so check these are the right ones' : '').'. Confirm to match them together.',
                'amount_cents' => $line['cents'],
            ];
        }

        // several lines, one entry
        $order = array_keys($entries);
        usort($order, fn ($a, $b) => abs($entries[$b]['cents']) <=> abs($entries[$a]['cents']));

        foreach ($order as $ei) {
            if (isset($usedEntries[$ei]) || $searches >= self::MAX_SPLIT_SEARCHES * 2) {
                continue;
            }
            $entry = $entries[$ei];
            $pool = [];
            foreach ($lines as $li => $line) {
                if (isset($usedLines[$li]) || ($line['cents'] > 0) !== ($entry['cents'] > 0) || abs($line['cents']) >= abs($entry['cents']) || abs($line['day'] - $entry['day']) > $this->windowDays) {
                    continue;
                }
                $pool[] = $li;
            }
            if (count($pool) < 2) {
                continue;
            }
            $searches++;

            usort($pool, fn ($a, $b) => [abs($lines[$a]['day'] - $entry['day']), $lines[$a]['id']] <=> [abs($lines[$b]['day'] - $entry['day']), $lines[$b]['id']]);
            $pool = array_slice($pool, 0, self::MAX_SPLIT_POOL);

            $solutions = $this->subsets(array_map(fn ($li) => $lines[$li]['cents'], $pool), $entry['cents']);
            if ($solutions === []) {
                continue;
            }

            $best = $this->bestSolution($solutions, $pool, fn ($li) => $lines[$li], $entry);
            $members = array_map(fn ($k) => $pool[$k], $best);
            $confidence = $this->splitConfidence(count($solutions), array_map(fn ($li) => $lines[$li], $members), $entry);

            if ($confidence < self::SUGGEST) {
                continue;
            }

            foreach ($members as $li) {
                $usedLines[$li] = true;
            }
            $usedEntries[$ei] = true;
            $out[] = [
                'line_ids' => array_map(fn ($li) => $lines[$li]['id'], $members),
                'entry_ids' => [$entry['id']],
                'shape' => 'many_to_one',
                'confidence' => $confidence,
                'reason' => 'Suggested because: '.count($members).' statement lines ('.implode(' + ', array_map(fn ($li) => Money::format(abs($lines[$li]['cents']), $currency), $members)).') add up to this one entry exactly'.(count($solutions) > 1 ? ', but other combinations also fit, so check these are the right ones' : '').'. Confirm to match them together.',
                'amount_cents' => $entry['cents'],
            ];
        }

        return $out;
    }

    /**
     * Index sets of 2 to MAX parts whose values add up to $target (all the same sign), found depth-first within a node budget.
     *
     * @param  list<int>  $values
     * @return list<list<int>>
     */
    private function subsets(array $values, int $target): array
    {
        $solutions = [];
        $nodes = 0;
        $n = count($values);

        $walk = function (int $from, int $remaining, array $chosen) use (&$walk, &$solutions, &$nodes, $values, $n): void {
            if ($nodes++ > self::SPLIT_NODE_BUDGET || count($solutions) >= 5) {
                return;
            }
            if ($remaining === 0 && count($chosen) >= 2) {
                $solutions[] = $chosen;

                return;
            }
            if (count($chosen) >= self::MAX_SPLIT_PARTS) {
                return;
            }
            for ($i = $from; $i < $n; $i++) {
                // all values share the target's sign and are smaller in magnitude than it, so a part that overshoots is skipped
                if (abs($values[$i]) > abs($remaining)) {
                    continue;
                }
                $walk($i + 1, $remaining - $values[$i], [...$chosen, $i]);
            }
        };

        $walk(0, $target, []);

        return $solutions;
    }

    /**
     * @param  list<list<int>>  $solutions
     * @param  list<int>  $pool
     * @return list<int>
     */
    private function bestSolution(array $solutions, array $pool, callable $row, array $anchor): array
    {
        $best = $solutions[0];
        $bestScore = -1;

        foreach ($solutions as $solution) {
            $score = 0;
            foreach ($solution as $k) {
                $r = $row($pool[$k]);
                $score += 10 - min(10, abs($r['day'] - $anchor['day']));
                $score += 5 * count(array_intersect($r['tokens'], $anchor['tokens']));
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $solution;
            }
        }

        return $best;
    }

    /** @param  list<array<string, mixed>>  $members */
    private function splitConfidence(int $solutionCount, array $members, array $anchor): int
    {
        $confidence = 55;
        $allClose = true;
        $shared = false;

        foreach ($members as $m) {
            $allClose = $allClose && abs($m['day'] - $anchor['day']) <= 1;
            $shared = $shared || array_intersect($m['tokens'], $anchor['tokens']) !== [];
        }

        $confidence += $allClose ? 8 : 0;
        $confidence += $shared ? 12 : 0;
        $confidence -= $solutionCount > 1 ? 15 : 0;

        return min(75, $confidence);
    }
}
