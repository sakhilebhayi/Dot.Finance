<?php

namespace Tests\Feature\Acceptance;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Services\Accounting\Exceptions\UnbalancedEntry;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Owner criterion 10: journal entries balance. A property test over many random postings (and many random attempts that are out of
 * balance by a single cent) and the manual journal form: an unbalanced entry is refused with nothing written, a posted entry can
 * never be edited or deleted, only reversed.
 */
class Acceptance10_JournalsBalanceTest extends AcceptanceCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntegration();
    }

    /** @return list<int> postable expense/income/liability GL ids that a manual journal may use (no control or cash accounts) */
    private function freeAccounts(): array
    {
        return GlAccount::query()->forContext($this->ledger)->whereIn('class', ['expense', 'income', 'equity'])->where('is_postable', true)->whereNull('system_key')->pluck('id')->all();
    }

    public function test_property_random_balanced_entries_post_and_every_entry_and_the_whole_ledger_balance(): void
    {
        $accounts = $this->freeAccounts();
        $this->assertGreaterThan(5, count($accounts));
        $posted = 0;

        foreach ([1, 7, 42, 99, 2026] as $seed) {
            mt_srand($seed);
            for ($n = 0; $n < 24; $n++) {
                $lines = [];
                $total = 0;
                $count = mt_rand(2, 7);
                for ($i = 0; $i < $count - 1; $i++) {
                    $amount = mt_rand(1, 99_999_999);          // from one cent to nearly a million rand
                    $debit = mt_rand(0, 1) === 1;
                    $total += $debit ? $amount : -$amount;
                    $lines[] = new PostingLine($accounts[array_rand($accounts)], debitCents: $debit ? $amount : 0, creditCents: $debit ? 0 : $amount);
                }
                if ($total === 0) {                                // the last line balances the rest; two lines of an equal pair balance themselves
                    $lines[] = new PostingLine($accounts[array_rand($accounts)], debitCents: 1);
                    $lines[] = new PostingLine($accounts[array_rand($accounts)], creditCents: 1);
                } else {
                    $lines[] = new PostingLine($accounts[array_rand($accounts)], debitCents: $total < 0 ? -$total : 0, creditCents: $total > 0 ? $total : 0);
                }
                LedgerContext::run($this->ledger, fn () => app(JournalPoster::class)->post(new PostingRequest(
                    CarbonImmutable::parse('2026-11-01')->addDays(mt_rand(0, 28)), 'manual', "prop-{$seed}-{$n}", 1, "Property {$seed}/{$n}", $lines,
                )));
                $posted++;
            }
        }

        $this->assertSame(120, $posted);
        $unbalanced = DB::table('journal_entries as e')->join('journal_lines as l', 'l.journal_entry_id', '=', 'e.id')->where('e.team_id', $this->company->id)
            ->groupBy('e.id')->havingRaw('SUM(l.debit_cents) <> SUM(l.credit_cents)')->pluck('e.id');
        $this->assertCount(0, $unbalanced, 'no entry is out of balance');
        [$debits, $credits] = $this->journalTotals();
        $this->assertSame($debits, $credits, 'and so the whole ledger balances');
        $this->assertBooksVerify();
    }

    public function test_property_an_entry_out_of_balance_by_any_amount_is_refused_and_leaves_no_trace(): void
    {
        $accounts = $this->freeAccounts();
        $before = JournalEntry::query()->forContext($this->ledger)->count();
        mt_srand(314);

        for ($n = 0; $n < 40; $n++) {
            $amount = mt_rand(1, 5_000_000);
            $off = mt_rand(1, 3) === 1 ? 1 : mt_rand(1, 1_000_000);   // a third of the attempts are out by exactly one cent
            $lines = [new PostingLine($accounts[0], debitCents: $amount + $off), new PostingLine($accounts[1], creditCents: $amount)];
            try {
                LedgerContext::run($this->ledger, fn () => app(JournalPoster::class)->post(new PostingRequest(CarbonImmutable::parse('2026-11-05'), 'manual', "bad-{$n}", 1, 'Unbalanced', $lines)));
                $this->fail("an entry out by {$off} cents was accepted");
            } catch (UnbalancedEntry) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($before, JournalEntry::query()->forContext($this->ledger)->count(), 'nothing was written by the refused attempts');
        $this->assertBooksVerify();
    }

    private function manual(array $over = []): array
    {
        [$a, $b] = $this->freeAccounts();

        return $over + [
            'entry_date' => '2026-11-10', 'memo' => 'Reclassify a cost',
            'lines' => [
                ['account_id' => $a, 'debit' => '250.00', 'credit' => '', 'description' => 'To'],
                ['account_id' => $b, 'debit' => '', 'credit' => '250.00', 'description' => 'From'],
                ['account_id' => '', 'debit' => '', 'credit' => '', 'description' => ''],
            ],
        ];
    }

    public function test_the_manual_journal_form_refuses_an_unbalanced_entry_and_writes_nothing(): void
    {
        $accountant = $this->memberAs('accountant');
        $before = JournalEntry::query()->forContext($this->ledger)->count();

        $payload = $this->manual();
        $payload['lines'][1]['credit'] = '249.99';
        $this->asMember($accountant)->post(route('accounting.journals.store'), $payload)->assertSessionHasErrors('entry');

        $oneSided = $this->manual();
        $oneSided['lines'][1] = ['account_id' => '', 'debit' => '', 'credit' => '', 'description' => ''];
        $this->asMember($accountant)->post(route('accounting.journals.store'), $oneSided)->assertSessionHasErrors();

        $both = $this->manual();
        $both['lines'][0]['credit'] = '5.00';
        $this->asMember($accountant)->post(route('accounting.journals.store'), $both)->assertSessionHasErrors();

        foreach (['1e3', '12.345', '-5', 'abc'] as $bad) {
            $p = $this->manual();
            $p['lines'][0]['debit'] = $bad;
            $this->asMember($accountant)->post(route('accounting.journals.store'), $p)->assertSessionHasErrors('lines.0.debit');
        }

        $this->assertSame($before, JournalEntry::query()->forContext($this->ledger)->count());
    }

    public function test_a_balanced_manual_journal_posts_and_can_only_be_reversed_never_edited_or_deleted(): void
    {
        $accountant = $this->memberAs('accountant');
        $this->asMember($accountant)->post(route('accounting.journals.store'), $this->manual())->assertSessionHasNoErrors();
        $entry = JournalEntry::query()->forContext($this->ledger)->where('source_type', 'manual')->firstOrFail();
        $this->assertSame(25_000, (int) $entry->total_cents);
        $this->assertSame($accountant->id, (int) $entry->posted_by_user_id);

        // no route edits or deletes an entry; the model refuses; the database refuses
        $this->asOwner()->put('/accounting/journals/'.$entry->id, ['memo' => 'changed'])->assertStatus(405);
        $this->asOwner()->delete('/accounting/journals/'.$entry->id)->assertStatus(405);
        $line = JournalLine::query()->where('journal_entry_id', $entry->id)->firstOrFail();
        foreach ([fn () => $entry->update(['memo' => 'x']), fn () => $entry->delete(), fn () => $line->update(['debit_cents' => 1]), fn () => $line->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('a posted journal row was changed through the model');
            } catch (\Throwable $e) {
                $this->assertStringNotContainsString('was changed', $e->getMessage());
            }
        }
        $this->assertDatabaseRefuses(fn () => DB::table('journal_lines')->where('id', $line->id)->update(['debit_cents' => 999]));
        $this->assertDatabaseRefuses(fn () => DB::table('journal_lines')->where('id', $line->id)->delete());
        $this->assertDatabaseRefuses(fn () => DB::table('journal_entries')->where('id', $entry->id)->delete());
        $this->assertSame(25_000, (int) JournalLine::query()->where('journal_entry_id', $entry->id)->sum('debit_cents'));

        $this->asMember($accountant)->post(route('accounting.journals.reverse', $entry), ['reason' => 'Posted to the wrong cost centre'])->assertSessionHasNoErrors();
        $this->assertSame('reversed', $entry->fresh()->status);
        $reversal = JournalEntry::query()->forContext($this->ledger)->where('source_type', 'reversal')->firstOrFail();
        $this->assertSame(25_000, (int) JournalLine::query()->where('journal_entry_id', $reversal->id)->sum('credit_cents'), 'the mirror image');
        $this->asMember($accountant)->post(route('accounting.journals.reverse', $entry), ['reason' => 'Again'])->assertSessionHasErrors();
        $this->assertBooksVerify();
    }

    public function test_a_bookkeeper_and_a_viewer_cannot_post_a_manual_journal(): void
    {
        $this->asMember($this->memberAs('bookkeeper'))->post(route('accounting.journals.store'), $this->manual())->assertForbidden();
        $this->asMember($this->memberAs('viewer'))->post(route('accounting.journals.store'), $this->manual())->assertForbidden();
        $this->assertSame(0, JournalEntry::query()->forContext($this->ledger)->where('source_type', 'manual')->count());
    }

    private function assertDatabaseRefuses(callable $statement): void
    {
        $changed = null;
        try {
            DB::beginTransaction();
            $changed = $statement();
            $refused = false;
            DB::rollBack();
        } catch (\Throwable) {
            $refused = true;
            DB::rollBack();
        }
        $this->assertTrue($refused, 'the database itself refuses to change a posted journal row (rows touched: '.json_encode($changed).')');
    }
}
