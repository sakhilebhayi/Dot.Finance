<?php

namespace Tests\Feature\Tax;

use App\Models\Tax\TaxPayrollRun;
use App\Services\Tax\Emp201Builder;
use App\Services\Tax\Exceptions\ImportRejected;
use App\Services\Tax\Exceptions\TaxException;
use App\Services\Tax\PayrollImport;
use App\Services\Tax\PayrollJournal;
use App\Services\Tax\PayrollRuns;
use App\Services\Tax\PayrollTaxCalculator;
use App\Services\Tax\TaxRules;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaxFixtures;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use BuildsTaxFixtures;
    use RefreshDatabase;

    private PayrollTaxCalculator $calc;

    private $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->taxLedger();
        $this->vatProfile();
        $this->calc = new PayrollTaxCalculator;
        $this->rules = app(TaxRules::class)->session($this->tax);
    }

    private function r(int $rand, int $cents = 0): int
    {
        return $rand * 100 + $cents;
    }

    private function m(string $d = '2026-10-01'): CarbonImmutable
    {
        return CarbonImmutable::parse($d);
    }

    public function test_uif_is_1_percent_each_up_to_the_monthly_ceiling_per_employee(): void
    {
        $u = fn (int $gross) => $this->calc->uif($this->rules, $gross, $this->m());

        $this->assertSame($this->r(100), $u($this->r(10_000))['employee_cents']);
        $this->assertSame(17_712, $u($this->r(17_712))['employee_cents'], 'R177.12 at the ceiling of R17,712.00');
        $this->assertFalse($u($this->r(17_712))['capped']);
        $this->assertSame(17_712, $u($this->r(17_712, 1))['employee_cents'], 'a cent over the ceiling adds nothing');
        $this->assertTrue($u($this->r(17_712, 1))['capped']);
        $this->assertSame(17_712, $u($this->r(500_000))['employer_cents']);
        $this->assertSame(0, $u(0)['employee_cents']);
        $this->assertSame(17_711, $u(1_771_100)['employee_cents'], '1% of R17,711.00 = R177.11');
    }

    public function test_sdl_is_1_percent_unless_the_annual_payroll_is_under_the_threshold(): void
    {
        $s = fn (int $monthly, int $annual) => $this->calc->sdl($this->rules, $monthly, $annual, $this->m());

        $this->assertSame(0, $s($this->r(41_666, 66), $this->r(499_999, 99))['cents'], 'R499,999.99 a year is under R500,000: exempt');
        $this->assertTrue($s(1, $this->r(499_999, 99))['exempt']);
        $this->assertSame($this->r(416, 67), $s($this->r(41_666, 67), $this->r(500_000))['cents'], 'at R500,000 SDL applies; 1% of R41,666.67 = R416.6667, rounded to R416.67');
        $this->assertFalse($s(1, $this->r(500_000))['exempt']);
    }

    private function capture(array $o = []): TaxPayrollRun
    {
        return LedgerContext::run($this->tax, fn () => app(PayrollRuns::class)->capture($this->tax, $this->m(), $o + [
            'gross_pay_cents' => $this->r(100_000), 'paye_cents' => $this->r(18_000), 'uif_employee_cents' => $this->r(1_000), 'uif_employer_cents' => $this->r(1_000),
            'sdl_cents' => $this->r(1_000), 'eti_claimed_cents' => 0, 'employee_count' => 5,
        ], $this->people['owner']));
    }

    public function test_a_month_is_captured_once_and_a_draft_can_be_replaced(): void
    {
        $a = $this->capture();
        $b = $this->capture(['paye_cents' => $this->r(19_000)]);

        $this->assertSame($a->id, $b->id);
        $this->assertSame($this->r(19_000), $b->paye_cents);
        $this->assertSame(1, TaxPayrollRun::query()->forContext($this->tax)->count());
        $this->assertSame($this->r(3_000) + $this->r(19_000) - 0, $b->emp201LiabilityCents() - $this->r(0) , 'PAYE 19,000 + UIF 2,000 + SDL 1,000 - ETI 0 = 22,000');
    }

    public function test_paye_cannot_exceed_gross_pay(): void
    {
        $this->expectException(TaxException::class);
        $this->capture(['paye_cents' => $this->r(100_001)]);
    }

    public function test_the_payroll_journal_balances_and_a_posted_run_is_never_overwritten(): void
    {
        $run = $this->capture(['eti_claimed_cents' => $this->r(1_500)]);
        $journal = app(PayrollJournal::class);

        $lines = $journal->suggest($run);
        $this->assertSame(array_sum(array_column($lines, 'debit_cents')), array_sum(array_column($lines, 'credit_cents')), 'a balanced suggestion');
        $entry = $journal->post($this->tax, $run, $this->people['owner']);
        $this->assertSame($run->fresh()->journal_entry_id, $entry->id);

        // salaries expense: Dr 18,000 + 1,000 withheld; Cr 1,500 ETI. PAYE payable: Cr 18,000, Dr 1,500
        $net = fn (string $key) => LedgerContext::run($this->tax, fn () => app(\App\Services\Accounting\GlQuery::class)->netThrough($this->tax, app(\App\Services\Accounting\Chart::class)->system($key)));
        $this->assertSame($this->r(17_500), $net('salaries_expense'), '19,000 withheld less 1,500 ETI');
        $this->assertSame(-$this->r(16_500), $net('paye_payable'), 'owed 18,000 less the 1,500 incentive');
        $this->assertSame(-$this->r(2_000), $net('uif_payable'));
        $this->assertSame(-$this->r(1_000), $net('sdl_payable'));
        $this->assertSame($this->r(1_000), $net('employer_uif_expense'));

        try {
            $this->capture(['paye_cents' => 1]);
            $this->fail('a posted run must not be overwritten');
        } catch (TaxException $e) {
            $this->assertStringContainsString('never overwritten', $e->getMessage());
        }
        try {
            $run->fresh()->forceFill(['paye_cents' => 5])->save();
            $this->fail('the model must refuse');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('never changed', $e->getMessage());
        }
        $this->expectException(TaxException::class);
        $journal->post($this->tax, $run->fresh(), $this->people['owner']);
    }

    public function test_the_emp201_ties_to_the_payroll_liabilities_once_posted_and_is_otherwise_a_warning(): void
    {
        $run = $this->capture(['eti_claimed_cents' => $this->r(1_500)]);
        $build = fn () => LedgerContext::run($this->tax, fn () => app(Emp201Builder::class)->build($this->tax, $this->m(), $this->m('2026-10-31')));

        $before = $build();
        $this->assertFalse($before->reconciliation['posted']);
        $this->assertFalse($before->reconciliation['ties']);

        app(PayrollJournal::class)->post($this->tax, $run, $this->people['owner']);
        $after = $build();
        $this->assertTrue($after->reconciliation['ties']);
        $this->assertSame(0, $after->reconciliation['unexplained_cents']);
        $this->assertSame($this->r(18_000) + $this->r(2_000) + $this->r(1_000), $after->outputTaxCents);
        $this->assertSame($this->r(1_500), $after->inputTaxCents);
        $this->assertSame($this->r(19_500), $after->payableCents, 'PAYE 18,000 + UIF 2,000 + SDL 1,000 - ETI 1,500');
        $this->assertSame($this->r(19_500), $after->amount('PAYABLE'));
    }

    public function test_a_month_with_no_summary_is_an_error_not_a_zero(): void
    {
        $r = LedgerContext::run($this->tax, fn () => app(Emp201Builder::class)->build($this->tax, $this->m(), $this->m('2026-10-31')));
        $this->assertContains('no_payroll_summary', array_column($r->issues, 'code'));
    }

    // ── the Dot.HR import contract ──────────────────────────────────────

    private function payload(array $o = []): array
    {
        return array_replace_recursive([
            'schema' => PayrollImport::SCHEMA, 'hr_run_id' => 'HR-2026-10', 'company_uuid' => $this->acme->infodot_team_uuid, 'period' => '2026-10', 'currency' => 'ZAR',
            'issued_at' => now()->toIso8601String(),
            'totals' => ['gross_pay_cents' => 3_000_000, 'paye_cents' => 400_000, 'uif_employee_cents' => 30_000, 'uif_employer_cents' => 30_000, 'sdl_cents' => 30_000, 'eti_claimed_cents' => 0, 'employee_count' => 2],
            'employees' => [
                ['ref' => 'E-1', 'name' => 'Thandi Test', 'gross_cents' => 2_000_000, 'paye_cents' => 300_000, 'uif_employee_cents' => 20_000, 'uif_employer_cents' => 20_000, 'sdl_cents' => 20_000],
                ['ref' => 'E-2', 'name' => 'Sipho Test', 'gross_cents' => 1_000_000, 'paye_cents' => 100_000, 'uif_employee_cents' => 10_000, 'uif_employer_cents' => 10_000, 'sdl_cents' => 10_000],
            ],
        ], $o);
    }

    private function sign(string $body, string $secret = 'test-secret'): array
    {
        return ['HTTP_X-Dot-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret), 'CONTENT_TYPE' => 'application/json'];
    }

    private function importPost(array $payload, ?string $secret = 'test-secret'): \Illuminate\Testing\TestResponse
    {
        config(['tax.payroll_import.secret' => 'test-secret']);
        $body = json_encode($payload);

        return $this->call('POST', '/api/tax/payroll-import', [], [], [], $secret === null ? ['CONTENT_TYPE' => 'application/json'] : $this->sign($body, $secret), $body);
    }

    public function test_a_signed_import_creates_a_draft_and_the_same_import_again_changes_nothing(): void
    {
        $this->importPost($this->payload())->assertStatus(201)->assertJsonPath('status', 'created');
        $run = TaxPayrollRun::query()->forContext($this->tax)->firstOrFail();
        $this->assertSame('draft', $run->status);
        $this->assertSame('hr_import', $run->source);
        $this->assertSame(2, LedgerContext::run($this->tax, fn () => $run->lines()->count()));
        $this->assertSame('Thandi Test', LedgerContext::run($this->tax, fn () => $run->lines()->orderBy('employee_ref')->first()->employee_name), 'the name decrypts for people allowed to read it');
        $this->assertStringNotContainsString('Thandi', (string) \DB::table('tax_payroll_lines')->value('employee_name'), 'but is stored encrypted');

        $this->importPost($this->payload())->assertOk()->assertJsonPath('status', 'unchanged');
        $this->assertSame(1, TaxPayrollRun::query()->forContext($this->tax)->count());
    }

    public function test_a_bad_or_missing_signature_and_an_unconfigured_server_are_refused_before_anything_is_read(): void
    {
        $this->importPost($this->payload(), 'wrong-secret')->assertStatus(401);
        $this->importPost($this->payload(), null)->assertStatus(401);
        config(['tax.payroll_import.secret' => null]);
        $this->call('POST', '/api/tax/payroll-import', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($this->payload()))->assertStatus(503);
        $this->assertSame(0, TaxPayrollRun::query()->forContext($this->tax)->count());
    }

    public function test_an_unknown_company_creates_nothing_and_a_sender_only_reaches_the_ledger_its_uuid_maps_to(): void
    {
        $other = $this->company($this->people['owner'], 'Other (Pty) Ltd');
        $otherCtx = $this->companyContext($other);
        $this->enableAccounting($otherCtx, 'ZAR');

        $this->importPost($this->payload(['company_uuid' => '11111111-1111-4111-8111-111111111111']))->assertStatus(404);
        $this->importPost($this->payload(['company_uuid' => $other->infodot_team_uuid]))->assertStatus(201);

        $this->assertSame(0, TaxPayrollRun::query()->forContext($this->tax)->count(), 'the first company got nothing');
        $this->assertSame(1, TaxPayrollRun::query()->forContext($otherCtx)->count());
    }

    public function test_changed_content_replaces_a_draft_but_never_a_posted_run(): void
    {
        $this->importPost($this->payload())->assertStatus(201);
        $changed = $this->payload();
        $changed['totals']['paye_cents'] = 410_000;
        $changed['employees'][0]['paye_cents'] = 310_000;
        $this->importPost($changed)->assertOk()->assertJsonPath('status', 'replaced_draft');
        $run = TaxPayrollRun::query()->forContext($this->tax)->firstOrFail();
        $this->assertSame(410_000, $run->paye_cents);

        app(PayrollJournal::class)->post($this->tax, $run, $this->people['owner']);
        $again = $changed;
        $again['totals']['paye_cents'] = 420_000;
        $again['employees'][0]['paye_cents'] = 320_000;
        $this->importPost($again)->assertStatus(409);
        $this->assertSame(410_000, $run->fresh()->paye_cents);
    }

    public function test_a_different_run_for_a_month_that_has_one_is_a_conflict(): void
    {
        $this->importPost($this->payload())->assertStatus(201);
        $this->importPost($this->payload(['hr_run_id' => 'HR-OTHER']))->assertStatus(409);
    }

    public function test_the_payload_is_validated_lines_must_add_up_and_stale_payloads_are_refused(): void
    {
        $bad = $this->payload();
        $bad['totals']['paye_cents'] = 999;
        $this->importPost($bad)->assertStatus(422);

        $count = $this->payload();
        $count['totals']['employee_count'] = 9;
        $this->importPost($count)->assertStatus(422);

        $old = $this->payload(['issued_at' => now()->subHour()->toIso8601String()]);
        $this->importPost($old)->assertStatus(422);

        $schema = $this->payload(['schema' => 'something.else']);
        $this->importPost($schema)->assertStatus(422);

        $currency = $this->payload(['currency' => 'USD']);
        $this->importPost($currency)->assertStatus(422);

        $this->assertSame(0, TaxPayrollRun::query()->forContext($this->tax)->count());
    }

    public function test_import_rejection_carries_a_status(): void
    {
        $e = new ImportRejected('x', 409);
        $this->assertSame(409, $e->status);
    }
}
