<?php

namespace Tests\Feature\Integrations;

use App\Contracts\Integrations\PayrollSummarySink;
use App\Services\Integrations\Hr\PayrollSummary;
use App\Services\Integrations\NotifyClient;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class NotifyClientAndPayrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_notify_is_inert_without_url_and_secret_and_never_throws(): void
    {
        Http::fake();
        $client = new NotifyClient;

        $this->assertFalse($client->enabled());
        $this->assertSame('skipped', $client->send('finance.system', 'a@b.test', ['title' => 'x']));
        Http::assertNothingSent();

        config(['integrations.notify.url' => 'https://notify.test/webhooks/tok', 'integrations.notify.secret' => 's']);
        Http::fake(fn () => throw new ConnectionException('down'));
        $this->assertSame('failed', $client->send('finance.system', 'a@b.test', ['title' => 'x']));
    }

    public function test_notify_sends_flat_scalars_only_signed_over_the_raw_body(): void
    {
        config(['integrations.notify.url' => 'https://notify.test/webhooks/tok', 'integrations.notify.secret' => 's3']);
        Http::fake(['notify.test/*' => Http::response(['status' => 'received'])]);

        $this->assertSame('sent', (new NotifyClient)->send('finance.tax_deadline', 'a@b.test', ['title' => 'VAT201 due', 'nested' => ['x' => 1], 'obj' => new \stdClass, 'n' => 5, 'none' => null]));

        Http::assertSent(function ($r) {
            $body = json_decode($r->body(), true);

            return $r->header('X-Dot-Signature')[0] === hash_hmac('sha256', $r->body(), 's3')
                && $body['event'] === 'finance.tax_deadline' && $body['recipient'] === 'a@b.test' && $body['title'] === 'VAT201 due' && $body['n'] === 5
                && ! array_key_exists('nested', $body) && ! array_key_exists('obj', $body);
        });
    }

    private function summary(array $override = []): array
    {
        return array_replace_recursive([
            'company_uuid' => '3F2B8C1E-9A4D-4E7B-8C55-1D2E3F4A5B6C', 'run_id' => 'run-2026-10', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'pay_date' => '2026-10-25',
            'currency' => 'ZAR', 'employees' => 12,
            'totals' => ['gross_cents' => 24000000, 'net_cents' => 19000000, 'paye_cents' => 3500000, 'uif_employee_cents' => 177120, 'uif_employer_cents' => 177120, 'sdl_cents' => 240000],
        ], $override);
    }

    public function test_a_payroll_summary_contract_is_validated_and_totals_only(): void
    {
        $s = PayrollSummary::fromArray($this->summary(['totals' => ['eti_cents' => 100000]]));

        $this->assertSame('3f2b8c1e-9a4d-4e7b-8c55-1d2e3f4a5b6c', $s->companyUuid);
        $this->assertSame(24000000, $s->totals['gross_cents']);
        $this->assertSame(100000, $s->totals['eti_cents']);
        $this->assertSame(12, $s->employees);

        // Names, ID numbers and per-person pay are not part of the contract and are dropped, never carried.
        $extra = PayrollSummary::fromArray($this->summary() + ['employees_detail' => [['name' => 'Thandi', 'id_number' => '8001015009087']]]);
        $this->assertStringNotContainsString('Thandi', json_encode($extra));
    }

    public function test_an_inconsistent_or_malformed_summary_is_refused(): void
    {
        foreach ([
            ['totals' => ['net_cents' => 99000000]],                       // net above gross
            ['currency' => 'zar'], ['period_end' => '2026-09-30'], ['pay_date' => '25/10/2026'], ['employees' => -1], ['company_uuid' => 'nope'],
            ['totals' => ['paye_cents' => -5]], ['totals' => ['gross_cents' => '1e6']], ['run_id' => 'has space'],
        ] as $bad) {
            try {
                PayrollSummary::fromArray($this->summary($bad));
                $this->fail('accepted '.json_encode($bad));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_without_a_payroll_import_a_summary_is_refused_not_swallowed(): void
    {
        $this->expectException(LogicException::class);

        app(PayrollSummarySink::class)->accept(LedgerContext::personal(1), PayrollSummary::fromArray($this->summary()));
    }
}
