<?php

namespace Tests\Feature\Acceptance;

use App\Services\Reporting\ReportResult;
use App\Services\Reporting\ReportRunner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AcceptanceHelpers;
use Tests\Concerns\RunsAMonth;
use Tests\TestCase;

/**
 * Base of the R-44 acceptance tests. A test starts either from a fresh, configured company (setUpIntegration) or from the whole
 * month in the life (runMonth) and then drives real HTTP routes against real services. Nothing is mocked.
 */
abstract class AcceptanceCase extends TestCase
{
    use AcceptanceHelpers;
    use RefreshDatabase;
    use RunsAMonth;

    /** A report built by the same runner the report pages and the exports use. @param array<string, mixed> $input */
    protected function report(string $key, array $input = []): ReportResult
    {
        return app(ReportRunner::class)->run($this->ledger, $key, $input, CarbonImmutable::parse('2026-11-30'));
    }

    /** Every reconciliation check on a report passed. */
    protected function assertReportChecksPass(ReportResult $result): void
    {
        $this->assertNotEmpty($result->checks, $result->title.' carries reconciliation checks');
        $this->assertSame([], array_map(fn ($c) => $c['label'].': '.$c['detail'], $result->failedChecks()), $result->title.' reconciles to the ledger');
    }
}
