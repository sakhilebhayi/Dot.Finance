<?php

namespace Tests\Feature\Security;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * R-44 security sweep: the doors with no sign-in. The CSP report sink, the framework's file-serving route, Livewire's upload
 * endpoints, the removed-member fallback, and the response headers on errors.
 */
class PublicSurfaceTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function cspBodies(): array
    {
        return [
            'arrays everywhere' => ['{"csp-report":{"effective-directive":["a"],"blocked-uri":{"x":1}}}'],
            'nested arrays' => ['[[[[]]]]'],
            'not json' => ['<script>'],
            'huge' => ['{"csp-report":{"effective-directive":"'.str_repeat('a', 1_000_000).'","blocked-uri":"http://'.str_repeat('b', 100000).'.example/"}}'],
            'newlines' => ['{"csp-report":{"effective-directive":"script-src\\nERROR forged line","blocked-uri":"http://x\\r\\nforged.example/"}}'],
            'null' => ['null'],
            'number' => ['42'],
            'empty' => [''],
        ];
    }

    #[DataProvider('cspBodies')]
    public function test_the_csp_report_sink_answers_204_to_any_junk_and_never_errors(string $body): void
    {
        $response = $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $body);

        $this->assertSame(204, $response->getStatusCode(), (string) $response->exception?->getMessage());
    }

    public function test_the_csp_report_sink_logs_only_a_directive_and_a_host_on_one_line(): void
    {
        $lines = [];
        \Log::listen(function ($event) use (&$lines) {
            $lines[] = $event->message.json_encode($event->context);
        });

        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], '{"csp-report":{"effective-directive":"script-src\\nERROR forged","blocked-uri":"https://evil.example/path?token=SECRET123"}}');

        $joined = implode("\n", $lines);
        $this->assertStringContainsString('csp.violation', $joined);
        $this->assertStringNotContainsString('SECRET123', $joined);
        $this->assertStringNotContainsString("\n", $lines[0] ?? '');
    }

    public function test_the_framework_file_route_serves_nothing_from_the_private_disk_without_a_signature(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('finance-attachments/company-1/secret.pdf', '%PDF-1.4 secret');
        Storage::disk('local')->put('.env.backup', 'APP_KEY=base64:secret');

        foreach (['/storage/finance-attachments/company-1/secret.pdf', '/storage/.env.backup', '/storage/../.env', '/storage/%2e%2e/.env', '/storage/..%2f..%2f.env'] as $uri) {
            $response = $this->get($uri);
            $this->assertContains($response->getStatusCode(), [403, 404], "{$uri} -> ".$response->getStatusCode());
            $this->assertStringNotContainsString('secret', (string) $response->getContent());
        }
        $this->put('/storage/finance-attachments/company-1/planted.pdf', ['x' => 1])->assertForbidden();
        $this->assertFalse(Storage::disk('local')->exists('finance-attachments/company-1/planted.pdf'));
    }

    public function test_the_livewire_upload_endpoints_refuse_an_unsigned_caller(): void
    {
        $file = UploadedFile::fake()->createWithContent('x.png', 'not really a png');

        $this->post('/livewire/upload-file', ['files' => [$file]])->assertStatus(401);
        $this->assertContains($this->get('/livewire/preview-file/anything.png')->getStatusCode(), [401, 403, 404]);
    }

    public function test_a_member_removed_while_signed_in_loses_the_company_ledger_on_the_next_request(): void
    {
        $owner = $this->user();
        $company = $this->company($owner, 'Mine Ltd');
        $member = $this->memberOf($company, 'editor');
        \DB::table('accounts')->insert(['team_id' => $company->id, 'name' => 'Company current', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance_cents' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->inCompany($member, $company)->get(route('accounts.index'))->assertOk()->assertSee('Company current');

        $company->users()->detach($member->id);

        $this->inCompany($member, $company)->get(route('accounts.index'))->assertOk()->assertDontSee('Company current');
        $this->get(route('accounts.index', ['context' => $company->id]))->assertNotFound();
    }

    public function test_error_pages_and_not_found_carry_the_enforced_csp_with_a_nonce(): void
    {
        $this->actingAs($this->user());
        foreach ([$this->get('/no-such-page-at-all'), $this->get('/accounts/999999/edit'), $this->post('/dashboard'), $this->get('/accounts?context=999999')] as $response) {
            $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\\/=]{20,}'/", (string) $response->headers->get('Content-Security-Policy'), 'status '.$response->getStatusCode());
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
            $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
        }
    }

    public function test_no_response_leaks_a_stack_trace_or_a_path_when_debug_is_off(): void
    {
        config(['app.debug' => false]);
        $body = $this->actingAs($this->user())->get('/accounts/not-a-number/edit')->getContent();

        $this->assertStringNotContainsString(base_path(), $body);
        $this->assertStringNotContainsString('Stack trace', $body);
        $this->assertStringNotContainsString('vendor/laravel', $body);
        $this->assertInstanceOf(Team::class, $this->company($this->user()));
        $this->assertInstanceOf(User::class, $this->user());
    }
}
