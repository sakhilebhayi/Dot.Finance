<?php

namespace App\Services\Intelligence;

use Carbon\CarbonImmutable;

/**
 * Turns a question into one of the fixed intents with keyword and synonym rules. Deterministic and offline:
 * the same words always give the same intent. Every rule has a weight; the highest total wins, ties go to the
 * order of the table (the more specific intent first), and a total below the floor is "Unknown", which the
 * assistant answers with "I don't have that" and a list of what it can do. A model never decides on its own:
 * when the optional model layer is on it is asked only to choose among these same intents (see ModelIntentClassifier).
 */
final class IntentRecognizer
{
    private const FLOOR = 3;

    /**
     * Pattern weights per intent. Patterns run on the lower-cased question with punctuation kept.
     *
     * @var array<string, list<array{0: string, 1: int}>>
     */
    private const RULES = [
        'draft_reminder' => [['/\b(remind|reminder|chase|nudge|follow up with)\b/', 8], ['/\b(customer|client|invoice|payment)\b/', 1]],
        'draft_transaction' => [['/\b(add|record|log|create|enter|capture|note)\b.{0,25}\b(expense|income|transaction|payment|sale|purchase|cost)\b/', 6], ['/\b(paid|bought|received)\b.{0,30}\b(r|zar|\$)?\s?\d/', 2]],
        'draft_categorise' => [['/\b(categori[sz]e|recategori[sz]e|tag|sort out|label)\b/', 5], ['/\buncategori[sz]ed\b/', 1], ['/\btransactions?\b/', 1]],
        'can_i_afford' => [['/\bafford\b/', 6], ['/\bcan i (buy|pay|spend|take on|commit)\b/', 5], ['/\benough (money|cash)\b/', 4]],
        'vat_liability' => [['/\bvat\b/', 6], ['/\b(output|input) (tax|vat)\b/', 3], ['/\bsars\b.{0,20}\bowe\b/', 1]],
        'tax_to_prepare' => [['/\b(income tax|provisional tax|paye|uif|sdl|emp201|itr14|irp6|tax return|tax deadline|sars)\b/', 5], ['/\btax\b/', 3], ['/\b(prepare|deadline|due|submit|file|filing)\b/', 1]],
        'budget_performance' => [['/\bbudgets?\b/', 6], ['/\b(over|under|within) (my |the )?(budget|limit)\b/', 2]],
        'overdue_invoices' => [['/\b(overdue|past due|late payers?|in arrears|arrears)\b/', 5], ['/\binvoices?\b/', 2], ['/\bunpaid invoices?\b/', 3]],
        'who_owes_me' => [['/\b(owes?|owing|owed) (me|us)\b/', 6], ['/\bwho owes\b/', 6], ['/\b(receivables?|debtors?|outstanding from|amounts? due (to|from))\b/', 5], ['/\bcustomers?\b.{0,20}\b(unpaid|outstanding|owe)\b/', 4], ['/\b(owed to|due to) (me|us)\b/', 5]],
        'upcoming_bills' => [['/\b(upcoming|coming up|due soon|due next|next due|due this)\b/', 4], ['/\bbills?\b/', 4], ['/\b(payables?|what (do )?i (have|need) to pay|must pay|going to pay)\b/', 4], ['/\bcoming out\b/', 2]],
        'projected_cash' => [['/\b(project(ed|ion)?|forecast|predict|run out|runway)\b/', 5], ['/\b(will|would) (my |our )?(cash|balance|money)\b/', 5], ['/\b(in|after|over the next|next) \d{1,3} (days?|weeks?|months?)\b/', 3], ['/\b(cash|balance)\b/', 1]],
        'cash_position' => [['/\bcash (position|balance|on hand|in the bank|available|flow now)\b/', 6], ['/\b(bank )?balances?\b/', 4], ['/\bhow much (money|cash)\b.{0,25}\b(have|got|left|in)\b/', 5], ['/\b(cash|money) (do i have|left)\b/', 5], ['/\bposition\b/', 2]],
        'profitable' => [['/\b(profitable|profit|loss|making money|losing money|net income|in the red|margin|break ?even)\b/', 6], ['/\b(earning|earn)\b/', 2]],
        'expenses_increased' => [['/\b(increase[sd]?|gone up|went up|risen|rising|jumped?|spiked?|grown|higher|more than (last|usual)|up (from|on) last)\b/', 4], ['/\b(expenses?|costs?|spending|categories)\b/', 2], ['/\bwhich (expenses?|costs?|categories)\b/', 3], ['/\b(most|biggest|largest)\b/', 1]],
        'spend' => [['/\b(spen[dt]|spending|expenses?|costs?|outgoings?)\b/', 5], ['/\bhow much\b/', 2], ['/\bmoney (going out|out)\b/', 4]],
        'needs_attention' => [['/\b(attention|needs? (my |a )?(review|look|action)|anything wrong|problems?|issues?|alerts?|flagged|unusual|anomal(y|ies)|unreconciled|uncategori[sz]ed|to do|todo|action)\b/', 5], ['/\bwhat should i (look at|check|do)\b/', 5]],
    ];

    public function __construct(private readonly PeriodParser $periods = new PeriodParser) {}

    public function recognise(string $question, ?CarbonImmutable $now = null): RecognisedIntent
    {
        $now ??= CarbonImmutable::now();
        $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $question) ?? ''));

        $scores = [];
        foreach (self::RULES as $intent => $rules) {
            foreach ($rules as [$pattern, $weight]) {
                if (preg_match($pattern, $text) === 1) {
                    $scores[$intent] = ($scores[$intent] ?? 0) + $weight;
                }
            }
        }

        // "Spending went up" is an increase question, not a total; "tax" with "vat" is VAT; a question with a draft verb is a draft.
        $best = Intent::Unknown;
        $bestScore = 0;
        foreach (array_keys(self::RULES) as $intent) {
            $score = $scores[$intent] ?? 0;
            if ($score > $bestScore) {
                $best = Intent::from($intent);
                $bestScore = $score;
            }
        }

        if ($bestScore < self::FLOOR) {
            $best = Intent::Unknown;
        }

        $period = $this->periods->parse($text, $now);
        $amount = $this->amountCents($text, $best);

        return new RecognisedIntent($best, $bestScore, $period, $this->periods->horizonDays($text), $amount, $text, $this->entities($text, $best, $now));
    }

    /** The amount a question mentions: "R5,000", "5 000", "r1200.50", "zar 20000". Only for intents that need one. */
    public function amountCents(string $text, Intent $intent): ?int
    {
        if (! in_array($intent, [Intent::CanIAfford, Intent::DraftTransaction], true)) {
            return null;
        }

        $pattern = '/(?:\b(?:r|zar|usd|eur|gbp)\b\s?|[$€£]\s?)(\d{1,3}(?:[ ,]\d{3})+|\d+)(?:[.,](\d{1,2}))?(?!\d)/u';
        if (preg_match($pattern, $text, $m) !== 1) {
            // A bare number counts only when it is clearly money: not followed by a unit of time.
            if (preg_match('/(?<![\d\/-])(\d{1,3}(?:[ ,]\d{3})+|\d{2,})(?:[.,](\d{1,2}))?(?!\d|\s?(?:days?|weeks?|months?|years?|%|percent|\/))/u', $text, $m) !== 1) {
                return null;
            }
        }

        $whole = (int) preg_replace('/[^\d]/', '', $m[1]);
        $cents = isset($m[2]) && $m[2] !== '' ? (int) str_pad($m[2], 2, '0') : 0;
        $total = $whole * 100 + $cents;

        return $total > 0 && $total <= 99_999_999_999_999 ? $total : null;
    }

    /** @return array<string, mixed> */
    private function entities(string $text, Intent $intent, CarbonImmutable $now): array
    {
        $entities = [];

        if ($intent === Intent::DraftTransaction) {
            $entities['type'] = preg_match('/\b(income|sale|received|revenue|paid me|payment from)\b/', $text) ? 'income' : 'expense';

            if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $m) && checkdate((int) substr($m[1], 5, 2), (int) substr($m[1], 8, 2), (int) substr($m[1], 0, 4))) {
                $entities['date'] = $m[1];
            } elseif (str_contains($text, 'yesterday')) {
                $entities['date'] = $now->subDay()->toDateString();
            } else {
                $entities['date'] = $now->toDateString();
            }

            if (preg_match('/\b(?:for|on|from|to|at)\s+(.{3,80}?)(?:\s+(?:today|yesterday|on \d{4}-\d{2}-\d{2})|[.?!]|$)/u', $text, $m)) {
                $entities['description'] = trim(preg_replace('/\b(?:r|zar)\s?\d[\d ,.]*/u', '', $m[1]) ?? $m[1]);
            }
        }

        if ($intent === Intent::DraftReminder && preg_match('/\b(?:remind|chase|nudge|follow up with)\s+(.{2,60}?)(?:\s+(?:about|to|that|on|for)\b|[.?!]|$)/u', $text, $m)) {
            $entities['party'] = trim($m[1]);
        }

        return $entities;
    }
}
