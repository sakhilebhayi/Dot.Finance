<?php

namespace Tests\Feature\Polish;

use App\Services\Purchases\ExpenseTrends;
use App\Support\Ledger\LedgerContextManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsPurchases;
use Tests\TestCase;

/** R-44 polish (jury defect 7): "where spending moved" never compares part of a month with the whole of the last one. */
class LikeForLikeSpendingTest extends TestCase
{
    use BuildsPurchases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPurchases();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_nine_days_of_this_month_are_compared_with_the_same_nine_days_of_last_month(): void
    {
        // October: R1,000 on the 3rd (inside the first nine days) and R5,000 on the 20th (outside them). November: R1,500 on the 4th.
        $this->postedBill(['bill_date' => '2026-10-03', 'supplier_reference' => 'L-1', 'lines' => [$this->line(['unit_price_cents' => 100_000])]]);
        $this->postedBill(['bill_date' => '2026-10-20', 'supplier_reference' => 'L-2', 'lines' => [$this->line(['unit_price_cents' => 500_000])]]);
        $this->postedBill(['bill_date' => '2026-11-04', 'supplier_reference' => 'L-3', 'lines' => [$this->line(['unit_price_cents' => 150_000])]]);

        $cmp = app(ExpenseTrends::class)->likeForLike($this->ctx, CarbonImmutable::parse('2026-11-09'));

        $this->assertSame(9, $cmp['days']);
        $this->assertSame(['2026-11-01', '2026-11-09', '2026-10-01', '2026-10-09'], [$cmp['this_from'], $cmp['this_to'], $cmp['last_from'], $cmp['last_to']]);
        $row = collect($cmp['rows'])->firstWhere('code', '6190');
        $this->assertSame(100_000, $row['last_cents'], 'only the first nine days of October count, not the R5,000 on the 20th');
        $this->assertSame(150_000, $row['this_cents']);
        $this->assertSame(50_000, $row['change_cents']);
        $this->assertSame(50, $row['change_pct'], 'up 50%, not down 75% as all of October would have said');

        // The old full-month comparison is still there for the callers that want it, and it is the misleading one.
        $full = collect(app(ExpenseTrends::class)->monthly($this->ctx, 2, CarbonImmutable::parse('2026-11-09'))['rows'])->firstWhere('code', '6190');
        $this->assertSame(-450_000, $full['change_cents']);
    }

    public function test_the_window_is_capped_at_the_length_of_the_shorter_last_month(): void
    {
        $cmp = app(ExpenseTrends::class)->likeForLike($this->ctx, CarbonImmutable::parse('2026-03-31'));

        $this->assertSame(28, $cmp['days'], 'the 31st of March is compared with all 28 days of February, not a 31-day February that does not exist');
        $this->assertSame('2026-02-28', $cmp['last_to']);
    }

    public function test_nothing_spent_last_time_has_no_percentage_and_the_panel_says_so_in_words_without_brackets(): void
    {
        $this->postedBill(['bill_date' => '2026-11-04', 'supplier_reference' => 'N-1', 'lines' => [$this->line(['unit_price_cents' => 60_000])]]);

        $row = collect(app(ExpenseTrends::class)->likeForLike($this->ctx, CarbonImmutable::parse('2026-11-09'))['rows'])->firstWhere('code', '6190');
        $this->assertNull($row['change_pct']);

        $this->actingAs($this->owner)->withSession([LedgerContextManager::SESSION_KEY => $this->company->id]);
        $html = $this->get(route('purchases.index'))->assertOk()->getContent();
        $this->assertStringContainsString('same days of last month', $html);
        $this->assertStringContainsString('nothing spent in the same days last month', $html);
    }
}
