<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Tests\TestCase;

/**
 * Owner rule: the original logo is never redrawn, recoloured, restyled, replaced or generated. Only its size and place
 * may change. These tests pin the files, forbid the old drawn mark anywhere in the views, and check every layout shows
 * the real logo image with alt text.
 */
class OriginalLogoTest extends TestCase
{
    use RefreshDatabase;

    /** SHA-256 of the files as first committed (logo.png 2026-08-01, logo-light.png 2026-08-07, icons 2026-08-01). */
    private const FILE_HASHES = [
        'images/logo.png' => '8c7bee7e661e302d9e012b658d57b14815707191794107a72db62a70f1d4ca91',
        'images/logo-light.png' => 'a2fc9cdb86f260733760b8eead2d62d9783399d80b42995ceb1251e7382b33ac',
        'favicon-16x16.png' => '2ee208b20d87c4b5c49084e68cdd8444bcc3be6e4d9a6b128df39a1fc39f0b93',
        'favicon-32x32.png' => 'da7e0a830988d6eddb7e7c9485d9be5dcc9ad79c21040ac327ba9915ade877fb',
        'apple-touch-icon.png' => 'b2ff7227051b64b1379035bd858486e23d00ccbe84105f8427b9dde2878662d3',
    ];

    public function test_the_logo_and_icon_files_are_unchanged(): void
    {
        foreach (self::FILE_HASHES as $file => $hash) {
            $this->assertFileExists(public_path($file));
            $this->assertSame($hash, hash_file('sha256', public_path($file)), "{$file} is no longer the original artwork");
        }
    }

    public function test_no_view_draws_the_old_brand_mark(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/components/fx/mark.blade.php'));

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $source = (string) file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('fx.mark', $source, $file->getPathname().' uses the removed drawn mark');
            $this->assertStringNotContainsString('fx-mark', $source, $file->getPathname().' uses the removed drawn mark');
            $this->assertStringNotContainsString('application-logo', $source, $file->getPathname());
        }

        foreach (['shell.css', 'pages.css', 'components.css'] as $css) {
            $this->assertStringNotContainsString('.fx-mark', (string) file_get_contents(resource_path("css/{$css}")));
        }
    }

    public function test_the_signed_in_layout_shows_the_real_logo(): void
    {
        $page = $this->dom($this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk()->getContent());

        $this->assertSame(0, $page->query('//*[contains(@class, "fx-mark")]')->length);
        // The sidebar, the phone top bar and the landing each carry the day and the night file.
        $this->assertLogos($page, 3);
        $this->assertGreaterThan(0, $page->query('//aside//img[@alt="Dot.Finance"]')->length, 'the sidebar brand');
        $this->assertGreaterThan(0, $page->query('//header//img[@alt="Dot.Finance"]')->length, 'the top bar brand');
    }

    public function test_the_sign_in_register_and_landing_pages_show_the_real_logo(): void
    {
        foreach (['login', 'register', 'password.request'] as $route) {
            $this->assertLogos($this->dom($this->get(route($route))->assertOk()->getContent()), 1, $route);
        }

        $this->assertLogos($this->dom($this->get('/')->assertOk()->getContent()), 1, 'welcome');
    }

    public function test_the_error_pages_show_the_real_logo(): void
    {
        $this->assertLogos($this->dom($this->get('/no-such-page-for-the-logo-test')->assertNotFound()->getContent()), 1, '404');
        $this->assertLogos($this->dom(view('errors.503')->render()), 1, '503');
    }

    public function test_the_email_layout_shows_the_real_light_logo(): void
    {
        $html = app(Markdown::class)->render('emails.finance-notification', [
            'title' => 'Title', 'body' => 'Body', 'url' => null, 'ledger' => 'Ledger',
        ])->toHtml();
        $page = $this->dom($html);

        $logos = $page->query('//img[contains(@class, "logo")]');
        $this->assertSame(1, $logos->length);
        $this->assertSame('/images/logo-light.png', parse_url($logos->item(0)->getAttribute('src'), PHP_URL_PATH));
        $this->assertNotSame('', $logos->item(0)->getAttribute('alt'));
        $this->assertSame(0, $page->query('//svg')->length, 'no drawn brand in the email');
    }

    private function assertLogos(DOMXPath $page, int $groups, string $where = 'page'): void
    {
        $imgs = $page->query('//img[contains(@class, "fx-logo__")]');
        $this->assertSame($groups * 2, $imgs->length, "{$where}: the day and night file for each logo");
        $sources = [];
        foreach ($imgs as $img) {
            $this->assertSame('Dot.Finance', $img->getAttribute('alt'), $where);
            $this->assertSame('1181', $img->getAttribute('width'), $where);
            $this->assertSame('886', $img->getAttribute('height'), $where);
            $sources[] = parse_url($img->getAttribute('src'), PHP_URL_PATH);
        }
        $this->assertEqualsCanonicalizing(['/images/logo.png', '/images/logo-light.png'], array_unique($sources), $where);
        $this->assertSame(0, $page->query('//svg[contains(@class, "fx-mark")]')->length, $where);
    }

    private function dom(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
