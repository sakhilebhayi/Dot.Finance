<?php

namespace Tests\Unit\Bank;

use App\Services\Bank\MatchEngine;
use App\Services\Bank\MatchText;
use PHPUnit\Framework\TestCase;

class MatchEngineTest extends TestCase
{
    private function line(int $id, string $date, int $cents, string $text, ?string $ref = null): array
    {
        return ['id' => $id, 'day' => MatchText::day($date), 'cents' => $cents] + MatchText::prepare($text, $ref);
    }

    private function entry(int $id, string $date, int $cents, string $text, array $extra = []): array
    {
        return ['id' => $id, 'day' => MatchText::day($date), 'cents' => $cents] + MatchText::prepare($text) + $extra;
    }

    private function propose(array $lines, array $entries): array
    {
        return (new MatchEngine(7))->propose($lines, $entries, 'ZAR');
    }

    public function test_same_amount_same_day_shared_words_is_matched_for_you_with_the_reason_in_words(): void
    {
        $p = $this->propose([$this->line(1, '2026-10-03', 2_500_000, 'EFT CREDIT ACME LTD INV-0042')], [$this->entry(10, '2026-10-03', 2_500_000, 'Acme Ltd payment INV-0042')]);

        $this->assertCount(1, $p);
        $this->assertGreaterThanOrEqual(MatchEngine::AUTO, $p[0]['confidence']);
        $this->assertSame('one_to_one', $p[0]['shape']);
        $this->assertStringContainsString('same amount (ZAR 25,000.00)', $p[0]['reason']);
        $this->assertStringContainsString('same day', $p[0]['reason']);
        $this->assertStringContainsString('INV42', $p[0]['reason']);
    }

    public function test_the_amount_alone_is_never_automatic_unless_it_is_the_only_candidate_close_in_time(): void
    {
        $unrelated = $this->propose([$this->line(1, '2026-10-03', 123_457, 'POS 4821')], [$this->entry(10, '2026-10-06', 123_457, 'Stationery')]);
        $this->assertCount(1, $unrelated);
        $this->assertLessThan(MatchEngine::AUTO, $unrelated[0]['confidence']);

        $sole = $this->propose([$this->line(1, '2026-10-03', 123_457, 'POS 4821')], [$this->entry(10, '2026-10-03', 123_457, 'Stationery')]);
        $this->assertGreaterThanOrEqual(MatchEngine::AUTO, $sole[0]['confidence']);
        $this->assertStringContainsString('only entry for this amount', $sole[0]['reason']);
    }

    public function test_two_entries_with_the_same_amount_are_a_suggestion_and_the_better_evidence_wins(): void
    {
        $p = $this->propose(
            [$this->line(1, '2026-10-05', -50_000, 'DEBIT ORDER VODACOM')],
            [$this->entry(10, '2026-10-05', -50_000, 'Vodacom contract'), $this->entry(11, '2026-10-05', -50_000, 'Rent top-up')],
        );

        $this->assertCount(1, $p);
        $this->assertSame([10], $p[0]['entry_ids']);
    }

    public function test_identical_candidates_stay_below_the_automatic_threshold_and_say_so(): void
    {
        $p = $this->propose(
            [$this->line(1, '2026-10-05', -9_900, 'CARD PURCHASE')],
            [$this->entry(10, '2026-10-05', -9_900, 'Lunch'), $this->entry(11, '2026-10-05', -9_900, 'Taxi')],
        );

        $this->assertLessThan(MatchEngine::AUTO, $p[0]['confidence']);
        $this->assertStringContainsString('same amount near this date', $p[0]['reason']);
    }

    public function test_outside_the_window_or_a_different_amount_is_not_offered(): void
    {
        $this->assertSame([], $this->propose([$this->line(1, '2026-10-03', 1_000, 'x')], [$this->entry(10, '2026-10-20', 1_000, 'x')]));
        $this->assertSame([], $this->propose([$this->line(1, '2026-10-03', 1_000, 'x')], [$this->entry(10, '2026-10-03', 1_001, 'x')]));
        $this->assertSame([], $this->propose([$this->line(1, '2026-10-03', 1_000, 'x')], [$this->entry(10, '2026-10-03', -1_000, 'x')]), 'money in is not money out');
    }

    public function test_each_line_and_each_entry_is_used_once(): void
    {
        $p = $this->propose(
            [$this->line(1, '2026-10-05', -9_900, 'Lunch'), $this->line(2, '2026-10-05', -9_900, 'Taxi')],
            [$this->entry(10, '2026-10-05', -9_900, 'Lunch'), $this->entry(11, '2026-10-05', -9_900, 'Taxi')],
        );

        $this->assertCount(2, $p);
        $this->assertEqualsCanonicalizing([[1, 10], [2, 11]], array_map(fn ($x) => [$x['line_ids'][0], $x['entry_ids'][0]], $p));
    }

    public function test_a_recorded_payment_reference_in_the_narrative_adds_evidence(): void
    {
        $without = $this->propose([$this->line(1, '2026-10-05', 500_000, 'EFT FROM J SMITH 8841')], [$this->entry(10, '2026-10-06', 500_000, 'Payment received')]);
        $with = $this->propose([$this->line(1, '2026-10-05', 500_000, 'EFT FROM J SMITH 8841')], [$this->entry(10, '2026-10-06', 500_000, 'Payment received', ['payment_ref' => '8841'])]);

        $this->assertGreaterThan($without[0]['confidence'], $with[0]['confidence']);
        $this->assertStringContainsString('payment you recorded carries the reference "8841"', $with[0]['reason']);
    }

    public function test_one_line_for_several_entries_that_add_up(): void
    {
        $p = $this->propose([$this->line(1, '2026-10-11', 1_000_000, 'BULK EFT')], [$this->entry(10, '2026-10-10', 600_000, 'A'), $this->entry(11, '2026-10-10', 400_000, 'B'), $this->entry(12, '2026-10-10', 123_456, 'C')]);

        $this->assertCount(1, $p);
        $this->assertSame('one_to_many', $p[0]['shape']);
        $this->assertEqualsCanonicalizing([10, 11], $p[0]['entry_ids']);
        $this->assertLessThan(MatchEngine::AUTO, $p[0]['confidence']);
        $this->assertStringContainsString('add up to this one line exactly', $p[0]['reason']);
    }

    public function test_several_lines_for_one_entry(): void
    {
        $p = $this->propose([$this->line(1, '2026-10-15', -500_000, 'RENT 1'), $this->line(2, '2026-10-16', -400_000, 'RENT 2')], [$this->entry(10, '2026-10-15', -900_000, 'Rent')]);

        $this->assertCount(1, $p);
        $this->assertSame('many_to_one', $p[0]['shape']);
        $this->assertSame([1, 2], $p[0]['line_ids']);
    }

    public function test_a_split_that_does_not_add_up_is_not_invented(): void
    {
        $this->assertSame([], $this->propose([$this->line(1, '2026-10-11', 1_000_000, 'BULK')], [$this->entry(10, '2026-10-10', 600_000, 'A'), $this->entry(11, '2026-10-10', 399_999, 'B')]));
    }

    public function test_document_numbers_are_normalised(): void
    {
        $this->assertSame(['INV42'], MatchText::docs('Payment inv 0042 thanks'));
        $this->assertSame(['INV42'], MatchText::docs('INV-42'));
        $this->assertSame([], MatchText::docs('A 7'));
    }
}
