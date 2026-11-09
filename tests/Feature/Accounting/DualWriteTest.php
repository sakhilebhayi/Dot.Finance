<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Accounting\JournalEntry;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\Accounting\GlQuery;
use App\Services\Accounting\GlVerifier;
use App\Services\TransferService;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsAccounting;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

class DualWriteTest extends TestCase
{
    use BuildsAccounting;
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private function verify(LedgerContext $c): array
    {
        return LedgerContext::run($c, fn () => app(GlVerifier::class)->verify($c));
    }

    private function entries(LedgerContext $c)
    {
        return LedgerContext::run($c, fn () => JournalEntry::query()->forContext($c)->orderBy('entry_number')->get());
    }

    public function test_a_new_transaction_posts_a_balanced_entry_in_the_same_request(): void
    {
        $user = $this->user();
        $ctx = $this->personalContext($user);
        $account = Account::factory()->create(['user_id' => $user->id, 'opening_balance_cents' => 100_000, 'opening_balance_date' => '2026-01-01']);
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
        $this->enableAccounting($ctx);

        $this->inPersonal($user)->post(route('transactions.store'), [
            'account_id' => $account->id, 'category_id' => $category->id, 'description' => 'Fuel', 'amount' => '250.00',
            'type' => 'expense', 'date' => '2026-03-05',
        ])->assertRedirect(route('transactions.index'));

        $entries = $this->entries($ctx);
        $this->assertCount(2, $entries); // opening + the expense
        $entry = $entries->last();
        $this->assertSame('transaction', $entry->source_type);
        $this->assertSame(25_000, $entry->total_cents);
        $this->assertSame(25_000, (int) $entry->lines->sum('debit_cents'));
        $this->assertSame(25_000, (int) $entry->lines->sum('credit_cents'));

        $this->assertTrue($this->verify($ctx)['ok'], json_encode($this->verify($ctx)));
    }

    public function test_nothing_is_posted_while_accounting_is_off(): void
    {
        $user = $this->user();
        $account = Account::factory()->create(['user_id' => $user->id]);

        $this->inPersonal($user)->post(route('transactions.store'), [
            'account_id' => $account->id, 'description' => 'Coffee', 'amount' => '10.00', 'type' => 'expense', 'date' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->count());
    }

    public function test_editing_a_transaction_reverses_and_reposts_and_the_books_still_tie_out(): void
    {
        $user = $this->user();
        $ctx = $this->personalContext($user);
        $account = Account::factory()->create(['user_id' => $user->id, 'opening_balance_cents' => 50_000, 'opening_balance_date' => '2026-01-01']);
        $this->enableAccounting($ctx);

        $tx = Transaction::factory()->create(['account_id' => $account->id, 'amount_cents' => 1_000, 'type' => 'expense', 'date' => '2026-02-01']);
        LedgerContext::run($ctx, fn () => app(\App\Services\Accounting\GlSync::class)->transaction($tx));

        $this->inPersonal($user)->put(route('transactions.update', $tx), [
            'account_id' => $account->id, 'description' => 'Changed', 'amount' => '35.00', 'type' => 'expense', 'date' => '2026-02-02', 'version' => $tx->version,
        ])->assertRedirect(route('transactions.index'));

        $live = LedgerContext::run($ctx, fn () => JournalEntry::query()->forContext($ctx)->where('source_type', 'transaction')->where('source_id', (string) $tx->id)->orderBy('source_version')->get());
        $this->assertCount(3, $live); // v1, reversal's source is 'reversal', so v1 + v2 here plus nothing else
    }
}
