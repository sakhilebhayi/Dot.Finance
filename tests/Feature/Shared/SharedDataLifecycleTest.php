<?php

namespace Tests\Feature\Shared;

use App\Actions\Jetstream\DeleteUser;
use App\Models\Account;
use App\Models\Shared\Attachment;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Models\Team;
use App\Services\Shared\Attachments;
use App\Services\Shared\FxService;
use App\Services\Shared\Numbering;
use App\Services\Shared\PaymentAllocator;
use App\Services\Shared\PaymentRecorder;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\Concerns\BuildsSharedFixtures;
use Tests\Support\StubInvoice;
use Tests\TestCase;

/**
 * What happens to shared data when a person or a company goes away, and the maintenance command for the files.
 */
class SharedDataLifecycleTest extends TestCase
{
    use BuildsCompanyLedgers;
    use BuildsSharedFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** Parties, a numbered sequence, a file, a payment with an allocation, an FX rate and a tax override, all in one ledger. */
    private function fill(LedgerContext $context, Account $account): void
    {
        $ownerId = $context->userId;
        $party = Party::factory()->state($context->ownerAttributes())->create();
        DB::transaction(fn () => app(Numbering::class)->next('invoice', null, $context));
        app(Attachments::class)->store(UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n% ".uniqid()."\n%%EOF\n"), $party, null, context: $context);
        $payment = app(PaymentRecorder::class)->record(['direction' => 'in', 'party_id' => $party->id, 'cash_account_id' => $account->id, 'amount_cents' => 1_000, 'paid_on' => '2026-11-09', 'idempotency_key' => 'k'], null, $context);
        app(PaymentAllocator::class)->allocate($payment, new StubInvoice(1_000, owner: $context->ownerAttributes()), 500);
        app(FxService::class)->forContext($context)->record('USD', 'ZAR', 1_850_000_000, '2026-11-06');
        $this->rate(['jurisdiction' => 'XX', 'rate_key' => 'K', 'rate_bp' => 1, ...($ownerId ? ['user_id' => $ownerId] : ['team_id' => $context->teamId])]);
    }

    public function test_a_person_with_shared_data_can_be_deleted_and_it_goes_with_them(): void
    {
        $user = $this->user();
        $other = $this->user();
        $mine = Account::factory()->create(['user_id' => $user->id, 'currency' => 'ZAR']);
        $theirs = Account::factory()->create(['user_id' => $other->id, 'currency' => 'ZAR']);
        $this->fill(LedgerContext::personal($user), $mine);
        $this->fill(LedgerContext::personal($other), $theirs);

        app(DeleteUser::class)->delete($user->fresh());

        foreach (['parties', 'document_sequences', 'attachments', 'payments', 'payment_allocations', 'fx_rates', 'tax_rates'] as $table) {
            $this->assertSame(0, DB::table($table)->where('user_id', $user->id)->count(), "{$table}: the person's rows are gone");
            $this->assertGreaterThan(0, DB::table($table)->where('user_id', $other->id)->count(), "{$table}: someone else's rows are untouched");
        }
    }

    public function test_the_files_of_a_deleted_person_are_found_as_orphans_and_only_those(): void
    {
        $user = $this->user();
        $other = $this->user();
        $this->fill(LedgerContext::personal($user), Account::factory()->create(['user_id' => $user->id, 'currency' => 'ZAR']));
        $this->fill(LedgerContext::personal($other), Account::factory()->create(['user_id' => $other->id, 'currency' => 'ZAR']));
        $this->assertCount(2, Storage::disk('local')->allFiles('finance-attachments'));

        app(DeleteUser::class)->delete($user->fresh());

        $this->artisan('finance:attachments:orphans')->expectsOutputToContain('1 orphaned file(s)')->assertSuccessful();
        $this->assertCount(2, Storage::disk('local')->allFiles('finance-attachments'), 'a dry run deletes nothing');

        $this->artisan('finance:attachments:orphans', ['--delete' => true])->expectsOutputToContain('Deleted 1 orphaned file(s)')->assertSuccessful();

        $remaining = Storage::disk('local')->allFiles('finance-attachments');
        $this->assertCount(1, $remaining);
        $this->assertSame($remaining[0], Attachment::query()->forContext(LedgerContext::personal($other))->value('path'), 'the file that has a record is kept');

        $this->artisan('finance:attachments:orphans')->expectsOutputToContain('No orphaned attachment files')->assertSuccessful();
    }

    public function test_a_company_with_shared_data_cannot_be_deleted(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        Party::factory()->forCompany($company)->create();

        $this->assertTrue($company->hasLedgerData(), 'parties alone count as ledger data');

        $this->expectException(ValidationException::class);
        app(DeleteUser::class)->delete($owner->fresh());
    }

    public function test_a_number_sequence_counts_as_company_ledger_data(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $this->assertFalse($company->hasLedgerData());

        $context = LedgerContext::company($company);
        DB::transaction(fn () => app(Numbering::class)->next('invoice', null, $context));
        $this->assertTrue(Team::find($company->id)->hasLedgerData(), 'a number sequence');
    }
}
