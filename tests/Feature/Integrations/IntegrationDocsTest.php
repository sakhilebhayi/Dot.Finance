<?php

namespace Tests\Feature\Integrations;

use App\Services\Integrations\IntegrationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

class IntegrationDocsTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    public function test_the_readme_and_the_status_page_say_the_same_thing(): void
    {
        $readme = file_get_contents(base_path('docs/integrations/README.md'));
        $words = ['connected' => 'connected', 'contract_only' => 'contract only', 'not_possible' => 'not possible'];

        foreach (app(IntegrationStatus::class)->all() as $row) {
            preg_match('/^\| '.preg_quote($row['name'], '/').' \| ([a-z ]+) \|/m', $readme, $m);

            $this->assertNotEmpty($m, "{$row['name']} is missing from docs/integrations/README.md");
            $this->assertSame($words[$row['status']], $m[1], "{$row['name']}: the README and the status page disagree");
        }
    }

    public function test_the_contract_files_exist_and_name_their_status(): void
    {
        foreach (['emall-sales-contract.md' => 'not yet connected', 'hr-payroll-contract.md' => 'not connected', 'analytics-finance-metrics.md' => 'finance'] as $file => $needle) {
            $this->assertStringContainsString($needle, file_get_contents(base_path('docs/integrations/'.$file)), $file);
        }
    }

    public function test_the_status_page_lists_every_integration_with_its_honest_label(): void
    {
        $owner = $this->user();
        $team = $this->company($owner);

        $this->inCompany($owner, $team)->get(route('intel.integrations.index'))->assertOk()
            ->assertSee('Contract only')->assertSee('Not possible')->assertSee('Built, switched off')->assertDontSee('Connected</span>', false);

        config(['ecosystem.infodot.secret' => 'x']);
        $this->get(route('intel.integrations.index'))->assertSee('Connected</span>', false);
    }
}
