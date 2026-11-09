<?php

namespace Tests\Feature\Shared;

use App\Models\Shared\Currency;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * The database itself refuses bad rows, whatever the application does. Every case here goes round the models
 * with a raw insert, then proves the row did not land. Each attempt runs in a savepoint so PostgreSQL's
 * "transaction aborted" state does not hide the next one.
 */
class SharedSchemaTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private int $userId;

    private int $otherUserId;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = $this->user();
        $this->userId = $owner->id;
        $this->otherUserId = $this->user()->id;
        $this->teamId = $this->company($owner)->id;
    }

    private function stamp(string $table, array $row): array
    {
        foreach (['created_at', 'updated_at'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                $row += [$column => now()];
            }
        }

        return $row;
    }

    private function rejects(string $table, array $row, string $why): void
    {
        $before = DB::table($table)->count();

        try {
            DB::transaction(fn () => DB::table($table)->insert($this->stamp($table, $row)));
            $this->fail("{$table}: {$why} should have been refused");
        } catch (QueryException $e) {
            // refused for the right kind of reason: not because the test sent a malformed statement
            $this->assertDoesNotMatchRegularExpression('/no column named|no such (table|column)|syntax error|undefined column|does not exist/i', $e->getMessage(), "{$table}: {$why} failed for the wrong reason");
            $this->assertSame($before, DB::table($table)->count(), "{$table}: {$why}");
        }
    }

    private function accepts(string $table, array $row): void
    {
        $before = DB::table($table)->count();
        DB::transaction(fn () => DB::table($table)->insert($this->stamp($table, $row)));
        $this->assertSame($before + 1, DB::table($table)->count());
    }

    private function party(array $overrides = []): array
    {
        return $overrides + ['user_id' => $this->userId, 'team_id' => null, 'type' => 'customer', 'name' => 'Acme', 'status' => 'active'];
    }

    // ── currencies ────────────────────────────────────────────────────

    public function test_the_currency_list_is_loaded_with_each_currencys_digits(): void
    {
        $digits = Currency::query()->pluck('minor_units', 'code')->all();

        foreach (['ZAR', 'USD', 'EUR', 'GBP'] as $code) {
            $this->assertSame(2, $digits[$code]);
        }
        $this->assertSame(0, $digits['JPY']);
        $this->assertSame(3, $digits['KWD']);
        $this->assertGreaterThanOrEqual(30, count($digits));
        $this->assertSame(2, Currency::minorUnits('zar'));
    }

    public function test_an_unknown_currency_is_an_error_not_a_guess(): void
    {
        Currency::forgetCache();
        $this->expectException(\InvalidArgumentException::class);
        Currency::minorUnits('XYZ');
    }

    public function test_currency_rows_are_checked(): void
    {
        $this->rejects('currencies', ['code' => 'ABC', 'name' => 'x', 'minor_units' => 5, 'is_active' => true], 'five digits');
        $this->rejects('currencies', ['code' => 'abd', 'name' => 'x', 'minor_units' => 2, 'is_active' => true], 'lower-case code');
        $this->rejects('currencies', ['code' => 'ZAR', 'name' => 'dup', 'minor_units' => 2, 'is_active' => true], 'duplicate code');
    }

    // ── the owner rule on every new table ─────────────────────────────

    public function test_every_new_ledger_table_refuses_two_owners_and_no_owner(): void
    {
        $cases = [
            'parties' => $this->party(),
            'document_sequences' => ['doc_type' => 'invoice'],
            'attachments' => ['disk' => 'local', 'path' => 'p', 'sha256' => str_repeat('a', 64), 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 1],
        ];

        foreach ($cases as $table => $row) {
            $row = array_diff_key($row, ['user_id' => 1, 'team_id' => 1]);
            $this->rejects($table, $row + ['user_id' => $this->userId, 'team_id' => $this->teamId], 'two owners');
            $this->rejects($table, $row + ['user_id' => null, 'team_id' => null], 'no owner');
            $this->accepts($table, $row + ['user_id' => $this->userId, 'team_id' => null]);
        }
    }

    public function test_global_reference_tables_allow_no_owner_but_never_two(): void
    {
        $rate = ['jurisdiction' => 'ZA', 'rate_key' => 'K', 'rate_bp' => 1, 'valid_from' => '2026-01-01', 'legal_status' => 'enacted', 'source_url' => 'https://x.test', 'accessed_at' => '2026-11-09', 'confidence' => 'high'];

        $this->rejects('tax_rates', $rate + ['user_id' => $this->userId, 'team_id' => $this->teamId], 'two owners');
        $this->accepts('tax_rates', $rate + ['user_id' => null, 'team_id' => null]);
        $this->accepts('tax_rates', array_merge($rate, ['rate_key' => 'K2', 'user_id' => $this->userId, 'team_id' => null]));
    }

    // ── parties ───────────────────────────────────────────────────────

    public function test_party_rows_are_checked(): void
    {
        $this->rejects('parties', $this->party(['type' => 'friend']), 'unknown type');
        $this->rejects('parties', $this->party(['status' => 'deleted']), 'unknown status');
        $this->rejects('parties', $this->party(['currency' => 'zar']), 'lower-case currency');
        $this->rejects('parties', $this->party(['currency' => 'ZA']), 'two-letter currency');
        $this->rejects('parties', $this->party(['credit_limit_cents' => -1]), 'negative credit limit');
        $this->rejects('parties', $this->party(['payment_terms_days' => 3651]), 'terms past ten years');
        $this->rejects('parties', $this->party(['name' => '   ']), 'blank name');

        $this->accepts('parties', $this->party(['currency' => 'ZAR', 'credit_limit_cents' => 0, 'payment_terms_days' => 30]));
    }

    public function test_a_tax_number_is_unique_among_a_ledgers_live_parties(): void
    {
        $this->accepts('parties', $this->party(['name' => 'A', 'tax_number' => '4123456789']));

        $this->rejects('parties', $this->party(['name' => 'B', 'tax_number' => '4123456789']), 'same tax number in one ledger');

        // another person's ledger and a company's ledger are different ledgers
        $this->accepts('parties', $this->party(['name' => 'C', 'tax_number' => '4123456789', 'user_id' => $this->otherUserId]));
        $this->accepts('parties', $this->party(['name' => 'D', 'tax_number' => '4123456789', 'user_id' => null, 'team_id' => $this->teamId]));

        // parties with no number never collide
        $this->accepts('parties', $this->party(['name' => 'E']));
        $this->accepts('parties', $this->party(['name' => 'F']));
    }

    public function test_an_archived_party_releases_its_tax_number_and_reference(): void
    {
        $this->accepts('parties', $this->party(['name' => 'Old', 'tax_number' => '4000000001', 'external_ref' => 'X-1', 'deleted_at' => now(), 'status' => 'archived']));
        $this->accepts('parties', $this->party(['name' => 'New', 'tax_number' => '4000000001', 'external_ref' => 'X-1']));
        $this->rejects('parties', $this->party(['name' => 'Third', 'external_ref' => 'X-1']), 'same reference while the live one exists');
    }

    // ── FX ────────────────────────────────────────────────────────────

    public function test_fx_rate_rows_are_checked(): void
    {
        $row = ['user_id' => null, 'team_id' => null, 'from_currency' => 'USD', 'to_currency' => 'ZAR', 'rate_e8' => 1_846_490_000, 'as_of' => '2026-11-06', 'source' => 'manual', 'fetched_at' => now()];

        $this->rejects('fx_rates', array_merge($row, ['rate_e8' => 0]), 'zero rate');
        $this->rejects('fx_rates', array_merge($row, ['rate_e8' => -5]), 'negative rate');
        $this->rejects('fx_rates', array_merge($row, ['to_currency' => 'USD']), 'a currency against itself');
        $this->rejects('fx_rates', array_merge($row, ['to_currency' => 'XYZ']), 'unknown currency');
        $this->rejects('fx_rates', array_merge($row, ['user_id' => $this->userId, 'team_id' => $this->teamId]), 'two owners');

        $this->accepts('fx_rates', $row);
        $this->accepts('fx_rates', array_merge($row, ['user_id' => $this->userId]));
    }

    // ── tax tables ────────────────────────────────────────────────────

    public function test_tax_rule_rows_are_checked(): void
    {
        $row = ['jurisdiction' => 'ZA', 'tax_type' => 'VAT', 'rule_key' => 'k', 'qualifier' => '', 'value_type' => 'integer', 'value' => '1', 'valid_from' => '2026-01-01', 'legal_status' => 'enacted', 'source_url' => 'https://x.test', 'accessed_at' => '2026-11-09', 'confidence' => 'high'];

        $this->rejects('tax_rules', array_merge($row, ['confidence' => 'certain']), 'unknown confidence');
        $this->rejects('tax_rules', array_merge($row, ['legal_status' => 'rumoured']), 'unknown legal status');
        $this->rejects('tax_rules', array_merge($row, ['value_type' => 'float']), 'unknown value type');
        $this->rejects('tax_rules', array_merge($row, ['valid_to' => '2025-12-31']), 'ends before it starts');
        $this->rejects('tax_rules', array_merge($row, ['source_url' => '  ']), 'no source');

        $this->accepts('tax_rules', $row);
        $this->rejects('tax_rules', $row, 'the same global version twice (the unique index treats a missing owner as one owner)');
        $this->accepts('tax_rules', array_merge($row, ['valid_from' => '2027-01-01']));
        $this->accepts('tax_rules', array_merge($row, ['user_id' => $this->userId]));
        $this->rejects('tax_rules', array_merge($row, ['user_id' => $this->userId]), 'the same override version twice');
    }

    public function test_tax_rate_and_code_rows_are_checked(): void
    {
        $rate = ['jurisdiction' => 'ZA', 'rate_key' => 'K', 'rate_bp' => 1500, 'valid_from' => '2026-01-01', 'legal_status' => 'enacted', 'source_url' => 'https://x.test', 'accessed_at' => '2026-11-09', 'confidence' => 'high'];
        $this->rejects('tax_rates', array_merge($rate, ['rate_bp' => 100_001]), 'an absurd rate');
        $this->rejects('tax_rates', array_merge($rate, ['confidence' => 'sure']), 'unknown confidence');
        $this->rejects('tax_rates', array_merge($rate, ['valid_to' => '2025-01-01']), 'ends before it starts');
        $this->accepts('tax_rates', $rate);
        $this->rejects('tax_rates', $rate, 'the same version twice');

        $code = ['jurisdiction' => 'ZA', 'code' => 'C', 'name' => 'C', 'kind' => 'standard', 'applies_to' => 'both', 'deductible_policy' => 'allowed', 'valid_from' => '2026-01-01', 'is_active' => true, 'sort_order' => 0];
        $this->rejects('tax_codes', array_merge($code, ['kind' => 'other']), 'unknown kind');
        $this->rejects('tax_codes', array_merge($code, ['applies_to' => 'nobody']), 'unknown direction');
        $this->rejects('tax_codes', array_merge($code, ['deductible_policy' => 'whatever']), 'unknown policy');
        $this->accepts('tax_codes', $code);
        $this->rejects('tax_codes', $code, 'the same version twice');
    }

    // ── numbering, attachments, payments ──────────────────────────────

    public function test_document_sequence_rows_are_checked(): void
    {
        $row = ['user_id' => $this->userId, 'team_id' => null, 'doc_type' => 'invoice'];

        $this->rejects('document_sequences', $row + ['next_number' => 0], 'numbering from zero');
        $this->rejects('document_sequences', $row + ['padding' => 0], 'no padding');
        $this->rejects('document_sequences', $row + ['padding' => 13], 'too much padding');
        $this->accepts('document_sequences', $row);
        $this->rejects('document_sequences', $row, 'two sequences for one type in one ledger');
        $this->accepts('document_sequences', array_merge($row, ['doc_type' => 'credit_note']));
        $this->accepts('document_sequences', array_merge($row, ['user_id' => $this->otherUserId]));
    }

    public function test_attachment_rows_are_checked(): void
    {
        $row = ['user_id' => $this->userId, 'team_id' => null, 'disk' => 'local', 'path' => 'a/1.pdf', 'sha256' => str_repeat('b', 64), 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 10];

        $this->rejects('attachments', array_merge($row, ['sha256' => 'short']), 'a bad hash');
        $this->rejects('attachments', array_merge($row, ['size' => 0]), 'an empty file');
        $this->rejects('attachments', array_merge($row, ['extraction_status' => 'magic']), 'unknown extraction status');
        $this->rejects('attachments', array_merge($row, ['attachable_type' => 'party']), 'a type with no id');
        $this->accepts('attachments', $row);
        $this->rejects('attachments', $row, 'the same stored path twice (originals are never overwritten)');
    }

    public function test_payment_rows_are_checked(): void
    {
        $account = $this->accountFor($this->userId);
        $row = ['user_id' => $this->userId, 'team_id' => null, 'direction' => 'in', 'cash_account_id' => $account, 'amount_cents' => 100, 'currency' => 'ZAR', 'paid_on' => '2026-11-09', 'method' => 'eft', 'idempotency_key' => 'k-1', 'status' => 'recorded'];

        $this->rejects('payments', array_merge($row, ['amount_cents' => 0]), 'a zero payment');
        $this->rejects('payments', array_merge($row, ['direction' => 'sideways']), 'unknown direction');
        $this->rejects('payments', array_merge($row, ['method' => 'barter']), 'unknown method');
        $this->rejects('payments', array_merge($row, ['status' => 'maybe']), 'unknown status');
        $this->rejects('payments', array_merge($row, ['currency' => 'rand']), 'bad currency');
        $this->rejects('payments', array_merge($row, ['fx_rate_e8' => 0]), 'zero rate');
        $this->rejects('payments', array_merge($row, ['idempotency_key' => '']), 'no idempotency key');
        $this->rejects('payments', array_merge($row, ['status' => 'reversed']), 'reversed with no reversal time');
        $this->rejects('payments', array_merge($row, ['reversed_at' => now()]), 'a reversal time while still recorded');

        $this->accepts('payments', $row);
        $this->rejects('payments', array_merge($row, ['amount_cents' => 5]), 'the same idempotency key twice in one ledger');
        $this->accepts('payments', array_merge($row, ['status' => 'reversed', 'reversed_at' => now(), 'idempotency_key' => 'k-2']));
        $this->accepts('payments', array_merge($row, ['user_id' => $this->otherUserId, 'cash_account_id' => $this->accountFor($this->otherUserId)]));
    }

    public function test_payment_allocation_rows_are_checked(): void
    {
        $account = $this->accountFor($this->userId);
        DB::table('payments')->insert(['user_id' => $this->userId, 'team_id' => null, 'direction' => 'in', 'cash_account_id' => $account, 'amount_cents' => 1000, 'currency' => 'ZAR', 'paid_on' => '2026-11-09', 'method' => 'eft', 'idempotency_key' => 'k', 'status' => 'recorded', 'created_at' => now(), 'updated_at' => now()]);
        $paymentId = (int) DB::table('payments')->value('id');
        $row = ['user_id' => $this->userId, 'team_id' => null, 'payment_id' => $paymentId, 'allocatable_type' => 'invoice', 'allocatable_id' => 7, 'amount_cents' => 100, 'tax_cents' => 15, 'fx_diff_cents' => 0];

        $this->rejects('payment_allocations', array_merge($row, ['amount_cents' => 0]), 'a zero allocation');
        $this->rejects('payment_allocations', array_merge($row, ['tax_cents' => 101]), 'tax larger than the allocation');
        $this->rejects('payment_allocations', array_merge($row, ['tax_cents' => -1]), 'negative tax');
        $this->rejects('payment_allocations', array_merge($row, ['released_by' => $this->userId]), 'release details with no release time');
        $this->rejects('payment_allocations', array_merge($row, ['payment_id' => 999_999]), 'a payment that does not exist');
        $this->accepts('payment_allocations', $row);
    }

    private function accountFor(int $userId): int
    {
        return \App\Models\Account::factory()->create(['user_id' => $userId, 'currency' => 'ZAR'])->id;
    }
}
