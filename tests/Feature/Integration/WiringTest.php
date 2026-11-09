<?php

namespace Tests\Feature\Integration;

use App\Contracts\Integrations\PayrollSummarySink;
use App\Contracts\Intelligence\AffordabilitySource;
use App\Contracts\Intelligence\InvoiceReminderSender;
use App\Contracts\Intelligence\PayablesSource;
use App\Contracts\Intelligence\ReceivablesSource;
use App\Contracts\Intelligence\TaxDeadlineSource;
use App\Contracts\Intelligence\TaxPositionSource;
use App\Contracts\Planning\PayrollCommitmentsProvider;
use App\Contracts\Planning\TaxObligationsProvider;
use App\Contracts\Reporting\OpenItemsProvider;
use App\Contracts\Reporting\TaxSummaryProvider;
use App\Services\Accounting\FxRateProvider;
use App\Services\Accounting\FxServiceRateProvider;
use App\Services\Bank\DocumentSources;
use App\Services\Planning\PlanningAffordability;
use App\Services\Purchases\PurchaseOpenDocuments;
use App\Services\Purchases\PurchasesPayables;
use App\Services\Reporting\DocumentOpenItems;
use App\Services\Sales\InvoiceReminders;
use App\Services\Sales\SalesOpenDocuments;
use App\Services\Sales\SalesReceivables;
use App\Services\Sales\Support\VatStatus;
use App\Services\Tax\TaxDeadlines;
use App\Services\Tax\TaxObligationsForPlanning;
use App\Services\Tax\TaxPayrollSummarySink;
use App\Services\Tax\TaxPosition;
use App\Services\Tax\TaxProfileVatStatus;
use App\Services\Tax\TaxSummaryForReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** R-44 integration: every contract a module was built against in isolation is bound to the module that implements it. */
class WiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_contract_resolves_to_the_module_that_implements_it(): void
    {
        $expected = [
            OpenItemsProvider::class => DocumentOpenItems::class,
            TaxSummaryProvider::class => TaxSummaryForReports::class,
            TaxObligationsProvider::class => TaxObligationsForPlanning::class,
            PayrollCommitmentsProvider::class => TaxObligationsForPlanning::class,
            ReceivablesSource::class => SalesReceivables::class,
            PayablesSource::class => PurchasesPayables::class,
            TaxPositionSource::class => TaxPosition::class,
            TaxDeadlineSource::class => TaxDeadlines::class,
            AffordabilitySource::class => PlanningAffordability::class,
            InvoiceReminderSender::class => InvoiceReminders::class,
            PayrollSummarySink::class => TaxPayrollSummarySink::class,
            VatStatus::class => TaxProfileVatStatus::class,
        ];

        foreach ($expected as $contract => $implementation) {
            $this->assertInstanceOf($implementation, app($contract), "{$contract} is not bound to {$implementation}");
        }

        $this->assertTrue(app(InvoiceReminderSender::class)->available());
    }

    public function test_the_bank_module_sees_both_document_sources(): void
    {
        $this->assertTrue(app(DocumentSources::class)->available());

        $sources = collect(app()->tagged('bank.document-sources'))->all();
        $this->assertCount(2, $sources);
        $this->assertInstanceOf(SalesOpenDocuments::class, $sources[0]);
        $this->assertInstanceOf(PurchaseOpenDocuments::class, $sources[1]);
        $this->assertSame(['in', 'out'], [$sources[0]->direction(), $sources[1]->direction()]);
    }

    public function test_the_exchange_rate_provider_has_exactly_one_binding_and_it_is_the_stored_rates_service(): void
    {
        $this->assertInstanceOf(FxServiceRateProvider::class, app(FxRateProvider::class));

        // Read the source: a provider that binds FxRateProvider a second time would silently win or lose by registration order.
        $bindings = [];
        foreach (array_merge(glob(app_path('Providers/*.php')) ?: [], [base_path('bootstrap/app.php'), base_path('bootstrap/providers.php')]) as $file) {
            foreach (file($file) as $number => $line) {
                if (preg_match('/(bind|singleton|scoped|instance|extend)\(\s*(\\\\?[A-Za-z\\\\]*)?FxRateProvider::class/', $line)) {
                    $bindings[] = basename($file).':'.($number + 1);
                }
            }
        }
        $this->assertSame(['AppServiceProvider.php:'.$this->lineOf(app_path('Providers/AppServiceProvider.php'), 'FxRateProvider::class, FxServiceRateProvider::class')], $bindings, 'FxRateProvider is bound more than once: '.implode(', ', $bindings));

        // and no command swaps it out at run time
        foreach (glob(app_path('Console/Commands/*.php')) as $file) {
            $this->assertStringNotContainsString('instance(FxRateProvider::class', file_get_contents($file), basename($file).' replaces the FxRateProvider binding');
        }
    }

    private function lineOf(string $file, string $needle): int
    {
        foreach (file($file) as $number => $line) {
            if (str_contains($line, $needle)) {
                return $number + 1;
            }
        }

        $this->fail("{$needle} not found in {$file}");
    }
}
