<?php

namespace Tests\Feature\Security;

use App\Enums\FinanceRole;
use App\Models\Controls\LedgerMemberRole;
use App\Models\Shared\Party;
use App\Models\Tax\TaxPayrollLine;
use App\Models\Tax\TaxPayrollRun;
use App\Models\Team;
use App\Models\User;
use App\Services\Purchases\SupplierBankDetails;
use App\Support\Ledger\LedgerContext;
use Database\Seeders\TaxHolidaysSeeder;
use Database\Seeders\TaxModuleRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\Concerns\SeedsSentinelLedgers;
use Tests\TestCase;

/**
 * R-44 security sweep, part 10 and 11: who may take what out of the books, that every export leaves an audit row, that payroll
 * detail is only for view-payroll-detail, and that sensitive values (bank account numbers, employee names, tokens) do not leak
 * into the audit log, the notifications, the logs or the pages of people who may not see them.
 */
class ExportAndDataControlsTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;
    use SeedsSentinelLedgers;

    private User $me;

    private Team $mine;

    /** @var array<string, User> */
    private array $people = [];

    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(TaxModuleRulesSeeder::class);
        $this->seed(TaxHolidaysSeeder::class);
        $this->me = $this->user();
        $this->mine = $this->company($this->me, 'Mine Ltd');
        $this->people = [
            'owner' => $this->me, 'manager' => $this->memberOf($this->mine, 'admin'), 'accountant' => $this->memberOf($this->mine, 'editor'), 'bookkeeper' => $this->memberOf($this->mine, 'editor'),
            'approver' => $this->memberOf($this->mine, 'viewer'), 'viewer' => $this->memberOf($this->mine, 'viewer'),
        ];
        foreach (['accountant' => FinanceRole::Accountant, 'approver' => FinanceRole::Approver] as $who => $role) {
            LedgerMemberRole::create(['team_id' => $this->mine->id, 'member_user_id' => $this->people[$who]->id, 'role' => $role->value, 'granted_by' => $this->me->id, 'granted_at' => now()]);
        }
        $this->ids = $this->seedContext('mine', ['user_id' => null, 'team_id' => $this->mine->id], company: $this->mine);
    }

    /** @return array<string, array{0: string, 1: array}> label => [route name, parameters] */
    private function csvExports(): array
    {
        return [
            'accounts payable CSV' => ['purchases.ap.index', ['format' => 'csv']],
            'supplier statement CSV' => ['purchases.suppliers.statement', ['party' => $this->ids['supplierParty'], 'format' => 'csv']],
            'customer statement CSV' => ['sales.customers.statement.csv', ['party' => $this->ids['party']]],
            'receivables CSV' => ['sales.receivables.csv', []],
            'access review CSV' => ['controls.access-review.export', []],
            'bank reconciliation CSV' => ['bank.reconciliation.csv', ['reconciliation' => $this->ids['reconciliation']]],
            'report export (streamed)' => ['reports.exports.store', []],
        ];
    }

    private function fetch(string $routeName, array $params)
    {
        if ($routeName === 'reports.exports.store') {
            return $this->post(route($routeName, [], false), ['report' => 'profit-loss', 'format' => 'csv', 'preset' => 'this_year']);
        }
        $route = \Route::getRoutes()->getByName($routeName);
        $uriParams = array_intersect_key($params, array_flip($route->parameterNames()));
        $query = array_diff_key($params, $uriParams);

        return $this->get(route($routeName, $uriParams, false).($query ? '?'.http_build_query($query) : ''));
    }

    public function test_only_roles_with_the_export_capability_can_take_a_csv_out_and_each_download_is_audited(): void
    {
        $canExport = ['owner', 'manager', 'accountant', 'bookkeeper'];

        foreach ($this->csvExports() as $label => [$routeName, $params]) {
            foreach ($this->people as $role => $user) {
                $before = \DB::table('finance_audit_log')->where('team_id', $this->mine->id)->where('action', 'exported')->count();
                $response = $this->inCompany($user, $this->mine)->fetch($routeName, $params);
                $after = \DB::table('finance_audit_log')->where('team_id', $this->mine->id)->where('action', 'exported')->count();

                // reading the access review needs the audit capability as well as the export one
                $allowed = $label === 'access review CSV' ? ['owner', 'manager', 'accountant'] : $canExport;
                if (in_array($role, $allowed, true)) {
                    $this->assertContains($response->getStatusCode(), [200, 302], "{$role} on {$label}: ".$response->getStatusCode());
                    if ($response->getStatusCode() === 200) {
                        $this->assertGreaterThan($before, $after, "{$role} took out {$label} and no audit row says so");
                    }
                } else {
                    $this->assertSame(403, $response->getStatusCode(), "{$role} must not export {$label}");
                    $this->assertSame($before, $after, "a refused export writes no audit row ({$role}, {$label})");
                }
            }
        }
    }

    public function test_the_audit_row_of_an_export_names_the_export_not_its_contents(): void
    {
        $this->inCompany($this->me, $this->mine)->get(route('sales.receivables.csv', [], false))->assertOk();
        $row = \DB::table('finance_audit_log')->where('team_id', $this->mine->id)->where('action', 'exported')->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertSame('export', $row->subject_type);
        $this->assertSame($this->me->id, (int) $row->actor_user_id);
        $this->assertStringNotContainsString('Sentinel', json_encode($row));
    }

    // ── payroll ───────────────────────────────────────────────────────────

    private function payroll(): TaxPayrollRun
    {
        $owner = ['user_id' => null, 'team_id' => $this->mine->id];
        $run = TaxPayrollRun::create($owner + ['period_month' => '2026-09-01', 'source' => 'hr_import', 'status' => 'draft', 'gross_pay_cents' => 3_000_000, 'paye_cents' => 400_000, 'uif_employee_cents' => 30_000, 'uif_employer_cents' => 30_000, 'sdl_cents' => 30_000, 'eti_claimed_cents' => 0, 'employee_count' => 1]);
        TaxPayrollLine::create($owner + ['tax_payroll_run_id' => $run->id, 'employee_ref' => 'E-0001', 'employee_name' => 'Zandile Marker-Employee', 'gross_cents' => 3_000_000, 'paye_cents' => 400_000, 'uif_employee_cents' => 30_000, 'uif_employer_cents' => 30_000, 'sdl_cents' => 30_000, 'eti_cents' => 0]);

        return $run;
    }

    public function test_per_employee_payroll_detail_is_shown_only_to_people_with_the_payroll_capability(): void
    {
        $run = $this->payroll();

        foreach ($this->people as $role => $user) {
            $html = $this->inCompany($user, $this->mine)->get(route('tax.payroll.show', $run, false))->assertOk()->getContent();
            $sees = in_array($role, ['owner', 'manager', 'accountant'], true);

            if ($sees) {
                $this->assertStringContainsString('Zandile Marker-Employee', $html, "{$role} should see payroll detail");
            } else {
                $this->assertStringNotContainsString('Zandile', $html, "{$role} must not see an employee's name");
                $this->assertStringNotContainsString('E-0001', $html, "{$role} must not see an employee reference");
            }
        }
    }

    public function test_the_accountant_pack_leaves_out_payroll_detail_for_anyone_without_the_capability_even_if_asked(): void
    {
        $this->payroll();
        $body = ['from' => '2026-03-01', 'to' => '2026-11-30', 'payroll_detail' => '1'];

        foreach (['bookkeeper' => false, 'owner' => true] as $role => $expectDetail) {
            $response = $this->inCompany($this->people[$role], $this->mine)->post(route('tax.pack.download', [], false), $body)->assertOk();
            $path = $response->baseResponse->getFile()->getPathname();
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path) === true);
            $all = '';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $all .= $zip->getFromIndex($i);
            }
            $zip->close();

            $expectDetail ? $this->assertStringContainsString('Zandile Marker-Employee', $all) : $this->assertStringNotContainsString('Zandile', $all);
        }
    }

    // ── sensitive values ──────────────────────────────────────────────────

    public function test_a_bank_account_number_is_never_written_to_the_audit_log_the_logs_or_a_page_of_someone_who_may_not_see_it(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.json_encode($event->context);
        });

        $ctx = LedgerContext::company($this->mine);
        $supplier = Party::factory()->forCompany($this->mine)->supplier()->create(['name' => 'Marker Supplies']);
        LedgerContext::run($ctx, fn () => app(SupplierBankDetails::class)->request($ctx, $this->me, $supplier, [
            'bank_name' => 'Marker Bank', 'account_holder' => 'Marker Holder', 'account_number' => '62012345678901', 'branch_code' => '250655', 'account_type' => 'cheque', 'change_reason' => 'new account letter',
        ], 'new account letter', false));

        foreach (['finance_audit_log', 'finance_notifications', 'finance_insights', 'assistant_messages', 'ai_insights'] as $table) {
            $this->assertStringNotContainsString('62012345678901', json_encode(\DB::table($table)->get()->all()), "{$table} holds a full account number");
        }
        $this->assertStringNotContainsString('62012345678901', implode("\n", $logged));

        foreach (['viewer', 'approver'] as $role) {
            $page = $this->inCompany($this->people[$role], $this->mine)->get(route('purchases.suppliers.show', $supplier, false))->assertOk()->getContent();
            $this->assertStringNotContainsString('62012345678901', $page, "{$role} must not see the full account number");
        }
    }
}
