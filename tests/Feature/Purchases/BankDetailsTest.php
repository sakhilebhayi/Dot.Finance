<?php

namespace Tests\Feature\Purchases;

use App\Models\FinanceAuditLog;
use App\Models\Purchases\SupplierBankDetail;
use App\Models\Shared\Party;
use App\Services\Purchases\PurchasesException;
use App\Services\Purchases\SupplierBankDetails;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\BuildsPurchases;
use Tests\TestCase;

class BankDetailsTest extends TestCase
{
    use BuildsPurchases;
    use RefreshDatabase;

    private const DETAILS = ['bank_name' => 'FNB', 'account_holder' => 'Acme Supplies (Pty) Ltd', 'account_number' => '62123456789', 'branch_code' => '250655', 'account_type' => 'Cheque'];

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

    private function service(): SupplierBankDetails
    {
        return app(SupplierBankDetails::class);
    }

    private function request($by, array $details = [], string $reason = 'Called the supplier on the number on file', bool $callback = true, ?Party $party = null): SupplierBankDetail
    {
        return $this->service()->request($this->ctx, $by, $party ?? $this->supplier, $details + self::DETAILS, $reason, $callback);
    }

    public function test_the_account_number_is_encrypted_at_rest_and_masked_everywhere_else(): void
    {
        $row = $this->request($this->owner);

        $raw = DB::table('supplier_bank_details')->where('id', $row->id)->first();
        $this->assertStringNotContainsString('62123456789', json_encode($raw), 'not in any column in the clear');
        $this->assertNotSame('', $raw->payload);
        $this->assertSame('6789', $raw->account_last4);
        $this->assertSame('FNB, account ending 6789', $row->masked());
        $this->assertSame('62123456789', $this->service()->reveal($row->fresh())['account_number'], 'the cast decrypts for the one place that needs it');
        $this->assertStringNotContainsString('62123456789', json_encode($row->fresh()->toArray()), 'a serialised row never carries it');
    }

    public function test_a_first_set_entered_by_someone_who_may_approve_payments_is_active_at_once_and_marked(): void
    {
        $row = $this->request($this->owner);

        $this->assertTrue($row->isActive());
        $this->assertTrue($row->self_approved);
        $this->assertSame($row->id, $this->service()->active($this->supplier)->id);
    }

    public function test_a_first_set_entered_by_a_bookkeeper_waits_for_an_approver(): void
    {
        $bookkeeper = $this->member('bookkeeper');
        $approver = $this->member('approver');

        $row = $this->request($bookkeeper);
        $this->assertTrue($row->isPending());
        $this->assertNull($this->service()->active($this->supplier));

        $this->service()->approve($approver, $row);
        $this->assertTrue($row->fresh()->isActive());
        $this->assertFalse($row->fresh()->self_approved);
    }

    public function test_changing_active_details_needs_a_second_person_and_the_old_ones_stay_in_force_meanwhile(): void
    {
        $bookkeeper = $this->member('bookkeeper');
        $approver = $this->member('approver');
        $original = $this->request($this->owner);

        $change = $this->request($bookkeeper, ['account_number' => '11112222333'], 'Supplier changed banks');

        $this->assertTrue($change->isPending());
        $this->assertSame($original->id, $this->service()->active($this->supplier)->id);

        $this->service()->approve($approver, $change);

        $this->assertSame($change->id, $this->service()->active($this->supplier)->id);
        $this->assertSame('superseded', $original->fresh()->status);
        $this->assertSame([2, 1], $this->service()->history($this->supplier)->pluck('version')->all());
    }

    public function test_the_person_who_asked_cannot_approve_their_own_change_in_a_ledger_with_other_members(): void
    {
        $this->request($this->owner);
        $this->member('bookkeeper');
        $change = $this->request($this->owner, ['account_number' => '11112222333'], 'Changed banks');

        try {
            $this->service()->approve($this->owner, $change);
            $this->fail('separation of duties');
        } catch (PurchasesException $e) {
            $this->assertSame('separation_of_duties', $e->reason);
        }
        $this->assertTrue($change->fresh()->isPending());
    }

    public function test_a_bookkeeper_cannot_approve_even_someone_elses_change(): void
    {
        $this->request($this->owner);
        $bookkeeper = $this->member('bookkeeper');
        $other = $this->member('bookkeeper');
        $change = $this->request($bookkeeper, ['account_number' => '11112222333'], 'Changed banks');

        try {
            $this->service()->approve($other, $change);
            $this->fail('capability');
        } catch (PurchasesException $e) {
            $this->assertSame('missing_capability', $e->reason);
        }
    }

    public function test_in_a_one_person_ledger_the_same_person_may_approve_and_it_is_recorded(): void
    {
        // a company whose only member is its owner
        $this->request($this->owner);
        $change = $this->request($this->owner, ['account_number' => '11112222333'], 'Changed banks');

        $this->assertTrue($this->service()->approve($this->owner, $change)->isActive());
        $this->assertTrue($change->fresh()->self_approved);
    }

    public function test_only_one_change_can_be_waiting_and_a_rejection_keeps_the_old_details(): void
    {
        $original = $this->request($this->owner);
        $bookkeeper = $this->member('bookkeeper');
        $approver = $this->member('approver');
        $change = $this->request($bookkeeper, ['account_number' => '11112222333'], 'Changed banks');

        try {
            $this->request($bookkeeper, ['account_number' => '99998888777'], 'Another');
            $this->fail('one at a time');
        } catch (PurchasesException $e) {
            $this->assertSame('pending_exists', $e->reason);
        }

        $this->service()->reject($approver, $change, 'The call-back did not match');

        $this->assertSame('rejected', $change->fresh()->status);
        $this->assertSame($original->id, $this->service()->active($this->supplier)->id);
    }

    public function test_a_stored_version_cannot_be_edited_or_deleted(): void
    {
        $row = $this->request($this->owner);

        $this->expectException(LogicException::class);
        $row->forceFill(['account_last4' => '0000'])->save();
    }

    public function test_the_audit_log_records_every_step_and_never_the_account_number(): void
    {
        $bookkeeper = $this->member('bookkeeper');
        $approver = $this->member('approver');
        $this->request($this->owner);
        $change = $this->request($bookkeeper, ['account_number' => '11112222333'], 'Changed banks');
        $this->service()->approve($approver, $change);

        $rows = FinanceAuditLog::query()->forContext($this->ctx)->where('subject_type', 'supplier_bank')->orderBy('id')->get();

        $this->assertSame(['bank_details_requested', 'bank_details_activated', 'bank_details_requested', 'bank_details_activated'], $rows->pluck('action')->all());
        $this->assertSame([$this->owner->id, $this->owner->id, $bookkeeper->id, $approver->id], $rows->pluck('actor_user_id')->all());
        $json = json_encode($rows->toArray());
        foreach (['62123456789', '11112222333', 'Acme Supplies (Pty)'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_the_same_account_on_two_suppliers_is_reported(): void
    {
        $a = $this->request($this->owner);
        $other = Party::factory()->forCompany($this->company)->supplier()->create(['name' => 'Totally Different Trading']);
        $b = $this->request($this->owner, [], 'First details for the second supplier', true, $other);

        $this->assertSame([$other->id], $this->service()->sharedWith($a)->pluck('id')->all());
        $this->assertSame([$this->supplier->id], $this->service()->sharedWith($b)->pluck('id')->all());
    }

    public function test_bad_details_are_refused(): void
    {
        foreach ([['account_number' => 'abc'], ['account_number' => '12'], ['bank_name' => ''], ['branch_code' => 'xx'], ['iban' => 'nope'], ['swift' => '!!']] as $bad) {
            try {
                $this->request($this->owner, $bad);
                $this->fail('refused: '.json_encode($bad));
            } catch (PurchasesException) {
            }
        }
        $this->assertSame(0, SupplierBankDetail::query()->forContext($this->ctx)->count());
    }

    public function test_a_reason_is_required(): void
    {
        $this->expectException(PurchasesException::class);
        $this->request($this->owner, [], '  ');
    }

    public function test_another_ledgers_supplier_is_out_of_reach(): void
    {
        $other = $this->user();
        $theirs = $this->company($other, 'Theirs Ltd');
        $party = Party::factory()->forCompany($theirs)->supplier()->create();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->request($this->owner, [], 'Reason given here', true, $party);
    }

    public function test_the_whole_ledger_scope_holds_for_a_supplier_with_details(): void
    {
        $row = $this->request($this->owner);

        $this->assertSame(0, LedgerContext::run(LedgerContext::personal($this->user()), fn () => SupplierBankDetail::query()->count()));
        $this->assertSame(1, LedgerContext::run($this->ctx, fn () => SupplierBankDetail::query()->whereKey($row->id)->count()));
    }
}
