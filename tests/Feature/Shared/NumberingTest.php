<?php

namespace Tests\Feature\Shared;

use App\Models\Shared\DocumentSequence;
use App\Services\Shared\Numbering;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\NumberingException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

class NumberingTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private function numbering(): Numbering
    {
        return app(Numbering::class);
    }

    private function issue(string $type, ?string $on = null, ?LedgerContext $context = null): string
    {
        return DB::transaction(fn () => $this->numbering()->next($type, $on ? CarbonImmutable::parse($on) : null, $context));
    }

    public function test_numbers_run_one_after_another_from_one(): void
    {
        $user = $this->user();
        $this->inPersonal($user);

        $this->assertSame('00001', $this->issue('invoice'));
        $this->assertSame('00002', $this->issue('invoice'));
        $this->assertSame('00003', $this->issue('invoice'));
    }

    public function test_prefix_and_padding(): void
    {
        $this->inPersonal($this->user());
        $this->numbering()->configure('invoice', prefix: 'INV', padding: 6);

        $this->assertSame('INV-000001', $this->issue('invoice'));
        $this->assertSame('INV-000002', $this->issue('invoice'));
    }

    public function test_a_number_wider_than_the_padding_just_grows(): void
    {
        $this->inPersonal($this->user());
        $this->numbering()->configure('invoice', prefix: 'INV', padding: 2);
        DocumentSequence::query()->update(['next_number' => 99]);

        $this->assertSame('INV-99', $this->issue('invoice'));
        $this->assertSame('INV-100', $this->issue('invoice'));
    }

    public function test_document_types_count_separately(): void
    {
        $this->inPersonal($this->user());
        $this->numbering()->configure('invoice', 'INV');
        $this->numbering()->configure('credit_note', 'CN');

        $this->assertSame('INV-00001', $this->issue('invoice'));
        $this->assertSame('CN-00001', $this->issue('credit_note'));
        $this->assertSame('INV-00002', $this->issue('invoice'));
        $this->assertSame('CN-00002', $this->issue('Credit_Note'), 'the type name is case-insensitive');
    }

    public function test_every_ledger_counts_separately(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $company = $this->company($owner);

        $personal = LedgerContext::personal($owner);
        $companyContext = LedgerContext::company($company);
        $strangers = LedgerContext::personal($other);

        $this->assertSame('00001', $this->issue('invoice', context: $personal));
        $this->assertSame('00002', $this->issue('invoice', context: $personal));
        $this->assertSame('00001', $this->issue('invoice', context: $companyContext));
        $this->assertSame('00001', $this->issue('invoice', context: $strangers));
        $this->assertSame('00003', $this->issue('invoice', context: $personal));
    }

    public function test_a_yearly_sequence_restarts_and_carries_the_year(): void
    {
        $this->inPersonal($this->user());
        $this->numbering()->configure('invoice', 'INV', 5, yearReset: true);

        $this->assertSame('INV-2026-00001', $this->issue('invoice', '2026-03-01'));
        $this->assertSame('INV-2026-00002', $this->issue('invoice', '2026-12-31'));
        $this->assertSame('INV-2027-00001', $this->issue('invoice', '2027-01-01'));
        $this->assertSame('INV-2027-00002', $this->issue('invoice', '2027-06-01'));
    }

    public function test_a_number_for_an_earlier_year_is_refused_once_the_sequence_has_moved_on(): void
    {
        $this->inPersonal($this->user());
        $this->numbering()->configure('invoice', 'INV', 5, yearReset: true);
        $this->issue('invoice', '2027-01-01');

        $this->expectException(NumberingException::class);
        $this->expectExceptionMessage('already moved on to 2027');
        $this->issue('invoice', '2026-12-31');
    }

    public function test_a_sequence_that_does_not_reset_ignores_the_year(): void
    {
        $this->inPersonal($this->user());
        $this->numbering()->configure('invoice', 'INV');

        $this->assertSame('INV-00001', $this->issue('invoice', '2026-12-31'));
        $this->assertSame('INV-00002', $this->issue('invoice', '2027-01-01'));
    }

    public function test_a_failed_issue_gives_the_number_back(): void
    {
        $this->inPersonal($this->user());
        $this->numbering()->configure('invoice', 'INV');

        $this->assertSame('INV-00001', $this->issue('invoice'));

        try {
            DB::transaction(function () {
                $this->assertSame('INV-00002', $this->numbering()->next('invoice'));
                throw new \RuntimeException('the document could not be saved');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('INV-00002', $this->issue('invoice'), 'the number taken by the failed transaction is handed out again: no gap');
        $this->assertSame('INV-00003', $this->issue('invoice'));
    }

    public function test_it_refuses_to_number_outside_a_transaction(): void
    {
        $this->inPersonal($this->user());
        DB::rollBack(0);   // leave the test's own wrapper: nothing is written below, the call must throw first

        $this->expectException(NumberingException::class);
        $this->expectExceptionMessage('inside the transaction');
        $this->numbering()->next('invoice');
    }

    public function test_it_refuses_without_a_ledger(): void
    {
        $this->expectException(NumberingException::class);
        DB::transaction(fn () => $this->numbering()->next('invoice'));
    }

    public function test_the_format_of_a_sequence_cannot_change_after_its_first_number(): void
    {
        $this->inPersonal($this->user());
        $this->numbering()->configure('invoice', 'INV');

        $this->numbering()->configure('invoice', 'BILL', 4);   // not used yet: allowed
        $this->assertSame('BILL-0001', $this->issue('invoice'));

        $this->expectException(NumberingException::class);
        $this->numbering()->configure('invoice', 'OTHER');
    }

    public function test_peek_shows_the_next_number_without_taking_it(): void
    {
        $this->inPersonal($this->user());

        $this->assertNull($this->numbering()->peek('invoice'), 'nothing is set up yet');

        $this->numbering()->configure('invoice', 'INV');
        $this->assertSame('INV-00001', $this->numbering()->peek('invoice'));
        $this->assertSame('INV-00001', $this->numbering()->peek('invoice'));
        $this->assertSame('INV-00001', $this->issue('invoice'));
        $this->assertSame('INV-00002', $this->numbering()->peek('invoice'));
    }

    public function test_a_type_with_no_setup_starts_at_one_with_the_defaults(): void
    {
        $this->inPersonal($this->user());

        $this->assertSame('00001', $this->issue('quote'));
        $this->assertSame(1, DocumentSequence::query()->count());
    }

    public function test_a_blank_or_oversize_type_is_refused(): void
    {
        $this->inPersonal($this->user());

        foreach (['', '   ', str_repeat('x', 41)] as $type) {
            try {
                $this->issue($type);
                $this->fail('type refused');
            } catch (NumberingException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_other_ledgers_sequences_are_invisible_to_the_scope(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        $this->issue('invoice', context: LedgerContext::personal($alice));

        $this->inPersonal($bob);
        $this->assertSame(0, DocumentSequence::query()->count());
    }
}
