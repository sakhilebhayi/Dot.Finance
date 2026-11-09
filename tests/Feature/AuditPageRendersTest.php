<?php

namespace Tests\Feature;

use App\Http\Controllers\AuditController;
use App\Models\Account;
use App\Models\Category;
use App\Models\FinanceAuditLog;
use App\Models\User;
use App\Support\Audit\AuditEntryView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClassConstant;
use Tests\TestCase;

/**
 * The outside jury's audit page returned HTTP 500 on seeded data (array_key_exists() on a string) because some writers record a
 * bare value where others record a before/after pair. The page must show every kind of row, and a row it cannot read must
 * degrade to one line rather than take the page down. Field names are plain words, never storage names ("cents", a hash).
 */
class AuditPageRendersTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function subjects(): array
    {
        return (new ReflectionClassConstant(AuditController::class, 'SUBJECTS'))->getValue();
    }

    /** Every shape a writer is known to produce. @return list<mixed> */
    private function shapes(int $accountId, int $categoryId): array
    {
        return [
            ['amount_cents' => ['before' => 12_345, 'after' => 20_000], 'currency' => ['after' => 'ZAR']],
            ['description' => ['changed' => true], 'account_id' => ['before' => $accountId, 'after' => 999_999]],
            // flat values: the bank statement import, a tax return, a payroll run
            ['file' => 'karoo-cheque-2026-11.csv', 'account_id' => (string) $accountId, 'source' => 'csv', 'rows_total' => 17, 'in_cents' => 5_626_840, 'out_cents' => -6_440_950, 'currency' => 'ZAR'],
            ['payable_cents' => 3_528_765, 'paye_cents' => 1_489_200, 'gross_pay_cents' => 8_760_000, 'figures_hash' => '91ddc99c1d7a7253', 'category_id' => $categoryId],
            ['cells' => ['a' => 1, 'b' => 2], 'lines' => [], 'entries' => [['x' => 1]], 'is_active' => true, 'early_payment_discount_bp' => 250, 'fx_rate_e8' => 1_842_000_000],
            ['account_id' => ['unexpected' => 'shape'], 'amount_cents' => 'not a number', 'nested' => ['deeper' => ['still' => 'fine']]],
            [],
        ];
    }

    private function signIn(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_every_audit_subject_type_renders_with_every_known_row_shape(): void
    {
        $user = $this->signIn();
        $account = Account::factory()->for($user)->create(['name' => 'Cheque account']);
        $category = Category::factory()->for($user)->create(['name' => 'Rent']);

        foreach ($this->subjects() as $subject) {
            foreach ($this->shapes($account->id, $category->id) as $i => $changes) {
                FinanceAuditLog::create([
                    'user_id' => $user->id, 'actor' => 'user', 'actor_user_id' => $user->id, 'action' => 'updated', 'subject_type' => $subject, 'subject_id' => $i + 1,
                    'subject_label' => ucfirst($subject).' '.$i, 'currency' => 'ZAR', 'changes' => $changes,
                ]);
            }
        }

        $this->get(route('audit.index'))->assertOk()->assertSee('Cheque account')->assertSee('Payable');

        foreach ($this->subjects() as $subject) {
            $this->get(route('audit.index', ['subject' => $subject]))->assertOk();
        }
    }

    public function test_a_row_that_is_not_an_object_at_all_degrades_to_one_line(): void
    {
        $user = $this->signIn();
        // a writer that stored a bare string instead of an object (the model casts it to a string, not an array)
        \DB::table('finance_audit_log')->insert([
            'user_id' => $user->id, 'actor' => 'user', 'actor_user_id' => $user->id, 'action' => 'updated', 'subject_type' => 'transaction', 'subject_id' => 1,
            'subject_label' => 'Odd row', 'currency' => 'ZAR', 'changes' => json_encode('just a string'), 'created_at' => now(),
        ]);
        \DB::table('finance_audit_log')->insert([
            'user_id' => $user->id, 'actor' => 'user', 'actor_user_id' => $user->id, 'action' => 'created', 'subject_type' => 'transaction', 'subject_id' => 2,
            'subject_label' => 'Good row', 'currency' => 'ZAR', 'changes' => json_encode(['amount_cents' => ['after' => 5_000]]), 'created_at' => now(),
        ]);

        $this->get(route('audit.index'))->assertOk()->assertSee('Odd row')->assertSee('could not be shown in full')->assertSee('Good row')->assertSee('50.00');
    }

    public function test_fields_have_plain_names_and_units_not_storage_names(): void
    {
        $user = $this->signIn();
        FinanceAuditLog::create([
            'user_id' => $user->id, 'actor' => 'user', 'actor_user_id' => $user->id, 'action' => 'prepared', 'subject_type' => 'tax_return', 'subject_id' => 1,
            'subject_label' => 'VAT201', 'currency' => 'ZAR',
            'changes' => ['payable_cents' => ['after' => 3_528_765], 'paye_cents' => ['after' => 1_489_200], 'gross_pay_cents' => ['after' => 8_760_000], 'figures_hash' => ['after' => '91ddc99c1d7a7253']],
        ]);

        $page = $this->get(route('audit.index'))->assertOk();

        $page->assertSee('Payable')->assertSee('35,287.65')->assertSee('PAYE')->assertSee('Gross pay');
        $page->assertDontSee('Payable cents')->assertDontSee('Paye cents')->assertDontSee('Gross pay cents');
        $page->assertSee('Show working');   // the fingerprint is kept behind a disclosure, not beside the figures
    }

    public function test_the_label_rules(): void
    {
        $this->assertSame('Payable', AuditEntryView::label('payable_cents'));
        $this->assertSame('Opening balance', AuditEntryView::label('opening_balance_cents'));
        $this->assertSame('Staff count', AuditEntryView::label('staff_count_cents'));
        $this->assertSame('Fees paid', AuditEntryView::label('fees_paid_cents'));
        $this->assertSame('Budget', AuditEntryView::label('budget_id'));
        $this->assertSame('Early payment discount', AuditEntryView::label('early_payment_discount_bp'));
    }
}
