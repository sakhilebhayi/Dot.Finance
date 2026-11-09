<?php

namespace Tests\Unit\Intelligence;

use App\Services\Intelligence\Intent;
use App\Services\Intelligence\IntentRecognizer;
use App\Services\Intelligence\RecognisedIntent;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IntentRecognizerTest extends TestCase
{
    private function recognise(string $q): RecognisedIntent
    {
        return (new IntentRecognizer)->recognise($q, CarbonImmutable::parse('2026-11-18 10:00'));
    }

    /** @return array<string, array{0: string, 1: Intent}> */
    public static function questions(): array
    {
        return [
            'spend' => ['How much did I spend this month?', Intent::Spend],
            'spend synonym' => ['what are my expenses for last month', Intent::Spend],
            'increase' => ['Which expenses increased the most?', Intent::ExpensesIncreased],
            'went up' => ['what costs have gone up since last month', Intent::ExpensesIncreased],
            'who owes' => ['Who owes me money?', Intent::WhoOwesMe],
            'debtors' => ['show my debtors', Intent::WhoOwesMe],
            'overdue' => ['Which invoices are overdue?', Intent::OverdueInvoices],
            'late payers' => ['any late payers?', Intent::OverdueInvoices],
            'bills' => ['What bills are coming up?', Intent::UpcomingBills],
            'payables' => ['what do I have to pay soon', Intent::UpcomingBills],
            'cash' => ['What is my cash position?', Intent::CashPosition],
            'bank balance' => ['how much money do I have in the bank', Intent::CashPosition],
            'projected' => ['What will my cash be in 60 days?', Intent::ProjectedCash],
            'runway' => ['when will I run out of cash', Intent::ProjectedCash],
            'profit' => ['Am I profitable this month?', Intent::Profitable],
            'loss' => ['am I making a loss', Intent::Profitable],
            'vat' => ['What is my VAT liability so far?', Intent::VatLiability],
            'tax' => ['What tax do I need to prepare for?', Intent::TaxToPrepare],
            'provisional' => ['when is provisional tax due', Intent::TaxToPrepare],
            'attention' => ['Which transactions need attention?', Intent::NeedsAttention],
            'anything wrong' => ['is there anything wrong in the books', Intent::NeedsAttention],
            'budget' => ['How are my budgets doing?', Intent::BudgetPerformance],
            'afford' => ['Can I afford R5,000?', Intent::CanIAfford],
            'draft tx' => ['Add an expense of R250 for fuel', Intent::DraftTransaction],
            'draft categorise' => ['categorise my uncategorised transactions', Intent::DraftCategorise],
            'draft reminder' => ['Remind Acme about their overdue invoice', Intent::DraftReminder],
            'nonsense' => ['what is the weather like', Intent::Unknown],
            'empty' => ['   ', Intent::Unknown],
        ];
    }

    #[DataProvider('questions')]
    public function test_a_question_maps_to_its_intent(string $question, Intent $expected): void
    {
        $this->assertSame($expected, $this->recognise($question)->intent, $question);
    }

    public function test_the_same_words_always_give_the_same_answer(): void
    {
        $a = $this->recognise('Which expenses increased the most?');
        $b = $this->recognise('Which expenses increased the most?');

        $this->assertSame($a->intent, $b->intent);
        $this->assertSame($a->score, $b->score);
    }

    public function test_periods_are_read_from_the_words(): void
    {
        $this->assertSame(['2026-11-01', '2026-11-18'], [$this->recognise('spend this month')->period->fromDate(), $this->recognise('spend this month')->period->toDate()]);
        $last = $this->recognise('how much did I spend last month')->period;
        $this->assertSame(['2026-10-01', '2026-10-31'], [$last->fromDate(), $last->toDate()]);
        $oct = $this->recognise('spending in October')->period;
        $this->assertSame(['2026-10-01', '2026-10-31'], [$oct->fromDate(), $oct->toDate()]);
        $days = $this->recognise('spend over the last 30 days')->period;
        $this->assertSame(['2026-10-20', '2026-11-18'], [$days->fromDate(), $days->toDate()]);
        $this->assertFalse($this->recognise('how much did I spend')->period->explicit);
    }

    public function test_may_as_a_verb_is_not_the_month(): void
    {
        $this->assertFalse($this->recognise('how much may I spend')->period->explicit);
        $this->assertTrue($this->recognise('how much did I spend in may')->period->explicit);
    }

    public function test_amounts_are_read_only_where_they_mean_money(): void
    {
        $this->assertSame(500000, $this->recognise('Can I afford R5,000?')->amountCents);
        $this->assertSame(500000, $this->recognise('can i afford 5 000')->amountCents);
        $this->assertSame(123450, $this->recognise('can I afford R1 234.50')->amountCents);
        $this->assertNull($this->recognise('what will my cash be in 60 days')->amountCents);
        $this->assertSame(25000, $this->recognise('add an expense of R250 for fuel')->amountCents);
    }

    public function test_the_projection_horizon_is_read_and_capped(): void
    {
        $this->assertSame(60, $this->recognise('cash in 60 days')->horizonDays);
        $this->assertSame(90, $this->recognise('cash in 3 months')->horizonDays);
        $this->assertSame(180, $this->recognise('cash in 99 months')->horizonDays);
        $this->assertSame(30, $this->recognise('what is my cash forecast')->horizonDays);
    }

    public function test_a_draft_reads_its_details(): void
    {
        $d = $this->recognise('Add an expense of R250 for fuel yesterday');
        $this->assertSame('expense', $d->entities['type']);
        $this->assertSame('2026-11-17', $d->entities['date']);
        $this->assertStringContainsString('fuel', $d->entities['description']);
        $this->assertSame('income', $this->recognise('record an income of R900 from Acme')->entities['type']);
    }
}
