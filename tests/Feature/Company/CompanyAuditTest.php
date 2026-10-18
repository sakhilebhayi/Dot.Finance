<?php

namespace Tests\Feature\Company;

use App\Actions\Jetstream\DeleteUser;
use App\Models\Account;
use App\Models\FinanceAuditLog;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Services\TransactionRecurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * The append-only audit log records the CONTEXT of every change and the PERSON who made it.
 * A company's log holds every member's actions with their names, and only an owner or admin
 * may read it; the personal log stays the person's own.
 */
class CompanyAuditTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function entry(): array
    {
        return ['description' => 'Tolls', 'amount' => '12.00', 'type' => 'expense', 'date' => now()->toDateString()];
    }

    public function test_a_company_change_is_logged_against_the_company_and_the_person_who_made_it(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $account = $this->companyAccount($company);
        $editor = $this->memberOf($company, 'editor');

        $this->inCompany($editor, $company)->post(route('transactions.store'), $this->entry() + ['account_id' => $account->id])->assertRedirect();

        $row = FinanceAuditLog::withoutGlobalScopes()->where('subject_type', 'transaction')->where('action', 'created')->sole();
        $this->assertSame($company->id, $row->team_id);
        $this->assertNull($row->user_id, 'a company row has no personal owner');
        $this->assertSame($editor->id, $row->actor_user_id);
        $this->assertSame('user', $row->actor);
    }

    public function test_a_personal_change_is_logged_against_the_person_with_no_company(): void
    {
        $user = $this->user();
        $account = Account::factory()->create(['user_id' => $user->id]);

        $this->inPersonal($user)->post(route('transactions.store'), $this->entry() + ['account_id' => $account->id]);

        $row = FinanceAuditLog::withoutGlobalScopes()->where('subject_type', 'transaction')->sole();
        $this->assertSame([$user->id, null, $user->id], [$row->user_id, $row->team_id, $row->actor_user_id]);
    }

    public function test_the_company_log_shows_every_members_actions_with_their_names(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $account = $this->companyAccount($company);
        $editor = $this->memberOf($company, 'editor');
        $admin = $this->memberOf($company, 'admin');
        $editor->forceFill(['name' => 'Edith Editor'])->save();
        $admin->forceFill(['name' => 'Adam Admin'])->save();

        $this->inCompany($editor->fresh(), $company)->post(route('transactions.store'), $this->entry() + ['account_id' => $account->id]);
        $this->inCompany($admin->fresh(), $company)->post(route('categories.store'), ['name' => 'Fuel', 'type' => 'expense']);

        $html = $this->inCompany($owner, $company)->get(route('audit.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Edith Editor', $html);
        $this->assertStringContainsString('Adam Admin', $html);
        $this->assertStringContainsString('Fuel', $html);
        $this->assertStringContainsString('Everyone in', $html);
    }

    public function test_an_admin_reads_the_company_log_and_an_editor_or_viewer_cannot(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $this->companyAccount($company);

        $this->inCompany($this->memberOf($company, 'admin'), $company)->get(route('audit.index'))->assertOk();
        $this->inCompany($this->memberOf($company, 'editor'), $company)->get(route('audit.index'))->assertForbidden();
        $this->inCompany($this->memberOf($company, 'viewer'), $company)->get(route('audit.index'))->assertForbidden();
    }

    public function test_the_personal_context_shows_the_personal_log_and_never_the_companys(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $this->companyAccount($company, ['name' => 'Company-only account name']);
        Account::factory()->create(['user_id' => $owner->id, 'name' => 'Personal-only account name']);

        $personal = $this->inPersonal($owner)->get(route('audit.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Personal-only account name', $personal);
        $this->assertStringNotContainsString('Company-only account name', $personal);

        $shared = $this->inCompany($owner, $company)->get(route('audit.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Company-only account name', $shared);
        $this->assertStringNotContainsString('Personal-only account name', $shared);
    }

    public function test_another_companys_log_is_never_visible(): void
    {
        $owner = $this->user();
        $mine = $this->company($owner, 'Mine Ltd');
        $theirs = $this->company($this->user(), 'Theirs Ltd');
        $this->companyAccount($theirs, ['name' => 'Their private account']);

        $this->inCompany($owner, $mine)->get(route('audit.index'))->assertOk()->assertDontSee('Their private account');
        $this->get(route('audit.index', ['context' => $theirs->id]))->assertNotFound();
    }

    public function test_the_log_can_be_filtered_to_one_member(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $account = $this->companyAccount($company);
        $editor = $this->memberOf($company, 'editor');
        $admin = $this->memberOf($company, 'admin');

        $this->inCompany($editor, $company)->post(route('transactions.store'), $this->entry() + ['account_id' => $account->id, 'description' => 'by editor']);
        $this->inCompany($admin, $company)->post(route('categories.store'), ['name' => 'AdminOnlyCategory', 'type' => 'expense']);

        $html = $this->inCompany($owner, $company)->get(route('audit.index', ['actor' => $editor->id]))->assertOk()->getContent();

        $this->assertStringNotContainsString('AdminOnlyCategory', $html);
        $this->assertStringContainsString('Transaction', $html);
    }

    public function test_the_actor_filter_only_offers_people_in_this_companys_log(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $account = $this->companyAccount($company);
        $editor = $this->memberOf($company, 'editor');
        $editor->forceFill(['name' => 'Edith Editor'])->save();
        $stranger = $this->user();
        $stranger->forceFill(['name' => 'Sam Stranger'])->save();
        Account::factory()->create(['user_id' => $stranger->id]);

        $this->inCompany($editor->fresh(), $company)->post(route('transactions.store'), $this->entry() + ['account_id' => $account->id]);

        $html = $this->inCompany($owner, $company)->get(route('audit.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Edith Editor', $html);
        $this->assertStringNotContainsString('Sam Stranger', $html);
    }

    public function test_an_actor_filter_naming_someone_outside_the_company_shows_nothing_extra(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $this->companyAccount($company);
        $stranger = $this->user();

        $this->inCompany($owner, $company)->get(route('audit.index', ['actor' => $stranger->id]))->assertOk()->assertSee('Nothing recorded yet');
    }

    public function test_scheduled_changes_are_attributed_to_the_system_not_to_a_person(): void
    {
        Carbon::setTestNow('2026-09-15');
        $company = $this->company($this->user());
        RecurringRule::factory()->forCompany($company)->create(['next_occurrence_date' => '2026-09-01', 'start_date' => '2026-09-01']);

        app(TransactionRecurrenceService::class)->generateDueOccurrences();

        $row = FinanceAuditLog::withoutGlobalScopes()->where('subject_type', 'transaction')->where('action', 'created')->sole();
        $this->assertSame([$company->id, 'system', null], [$row->team_id, $row->actor, $row->actor_user_id]);
    }

    public function test_the_company_page_calls_scheduled_changes_automatic(): void
    {
        Carbon::setTestNow('2026-09-15');
        $owner = $this->user();
        $company = $this->company($owner);
        RecurringRule::factory()->forCompany($company)->create(['next_occurrence_date' => '2026-09-01', 'start_date' => '2026-09-01']);
        app(TransactionRecurrenceService::class)->generateDueOccurrences();

        $this->inCompany($owner, $company)->get(route('audit.index'))->assertOk()->assertSee('automatic');
    }

    public function test_an_import_in_a_company_records_who_ran_it(): void
    {
        Storage::fake('local');
        $owner = $this->user();
        $company = $this->company($owner);
        $account = $this->companyAccount($company);
        $editor = $this->memberOf($company, 'editor');

        $this->inCompany($editor, $company);
        $token = basename(parse_url($this->post(route('transactions.import.upload'), [
            'account_id' => $account->id, 'file' => UploadedFile::fake()->createWithContent('s.csv', "date,description,amount\n2026-08-01,Supplier,-1.00\n"),
        ])->headers->get('Location'), PHP_URL_PATH));
        $this->post(route('transactions.import.store'), ['token' => $token, 'date_format' => 'ymd', 'amount_locale' => 'en']);

        $row = FinanceAuditLog::withoutGlobalScopes()->where('subject_type', 'import')->sole();
        $this->assertSame([$company->id, null, $editor->id], [$row->team_id, $row->user_id, $row->actor_user_id]);
    }

    public function test_the_log_stays_append_only_in_a_company_too(): void
    {
        $company = $this->company($this->user());
        $this->companyAccount($company);
        $row = FinanceAuditLog::withoutGlobalScopes()->firstOrFail();

        $this->expectException(LogicException::class);
        $row->update(['action' => 'tampered']);
    }

    public function test_the_log_cannot_be_bulk_changed_in_a_company_either(): void
    {
        $company = $this->company($this->user());
        $this->companyAccount($company);

        $this->expectException(LogicException::class);
        FinanceAuditLog::withoutGlobalScopes()->where('team_id', $company->id)->delete();
    }

    public function test_a_member_leaving_keeps_the_companys_log_and_it_says_a_former_member(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $account = $this->companyAccount($company);
        $editor = $this->memberOf($company, 'editor');
        $this->inCompany($editor, $company)->post(route('transactions.store'), $this->entry() + ['account_id' => $account->id]);
        $rowsBefore = FinanceAuditLog::withoutGlobalScopes()->where('team_id', $company->id)->count();

        app(DeleteUser::class)->delete($editor->fresh());

        $this->assertSame($rowsBefore, FinanceAuditLog::withoutGlobalScopes()->where('team_id', $company->id)->count(), 'the log is never shortened');
        $this->assertNull(FinanceAuditLog::withoutGlobalScopes()->where('subject_type', 'transaction')->sole()->actor_user_id);
        $this->inCompany($owner, $company)->get(route('audit.index'))->assertOk()->assertSee('a former member');
    }

    public function test_every_audit_row_in_a_company_names_exactly_one_owner(): void
    {
        $company = $this->company($this->user());
        $account = $this->companyAccount($company);
        $this->entryOn($account);

        $this->assertSame(0, FinanceAuditLog::withoutGlobalScopes()->whereNotNull('user_id')->count());
        $this->assertGreaterThan(0, FinanceAuditLog::withoutGlobalScopes()->where('team_id', $company->id)->count());
        $this->assertNotNull(Transaction::withoutGlobalScopes()->first());
    }
}
