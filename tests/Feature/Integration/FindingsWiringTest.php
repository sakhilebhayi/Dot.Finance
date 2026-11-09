<?php

namespace Tests\Feature\Integration;

use App\Models\Intelligence\FinanceInsight;
use App\Services\Intelligence\InsightEngine;
use App\Services\Purchases\SupplierBankDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsAMonth;
use Tests\TestCase;

/** The findings detectors read what the other modules really record. */
class FindingsWiringTest extends TestCase
{
    use RefreshDatabase;
    use RunsAMonth;

    private const DETAILS = ['bank_name' => 'FNB', 'account_holder' => 'Paper Co (Pty) Ltd', 'account_number' => '62123456789', 'branch_code' => '250655', 'account_type' => 'Cheque'];

    public function test_a_change_of_a_suppliers_bank_details_in_purchases_becomes_a_critical_finding_without_the_number(): void
    {
        $this->runMonth(bank: false);
        $service = app(SupplierBankDetails::class);

        // the first set is not a change
        $service->request($this->ledger, $this->owner, $this->paperCo, self::DETAILS, 'Called the supplier on the number on file', true);
        app(InsightEngine::class)->scan($this->ledger);
        $this->assertSame(0, FinanceInsight::query()->forContext($this->ledger)->where('kind', 'bank_details_changed')->count());

        // a different account, approved by someone else, is
        $approver = $this->memberAs('approver');
        $change = $service->request($this->ledger, $this->owner, $this->paperCo, ['account_number' => '99887766554'] + self::DETAILS, 'Supplier asked to change accounts', true);
        $this->assertSame('pending', $change->status);
        $service->approve($approver, $change, 'Confirmed by phone');

        app(InsightEngine::class)->scan($this->ledger);
        $finding = FinanceInsight::query()->forContext($this->ledger)->where('kind', 'bank_details_changed')->firstOrFail();
        $this->assertSame('critical', $finding->severity);
        $this->assertStringContainsString('Paper Co', $finding->title);
        $this->assertStringContainsString($approver->name, $finding->body);
        $this->assertStringNotContainsString('99887766554', json_encode($finding->toArray()), 'the account number is never in a finding');
        $this->assertStringNotContainsString('62123456789', json_encode($finding->toArray()));
    }
}
