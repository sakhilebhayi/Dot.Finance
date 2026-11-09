<?php

namespace Tests\Feature\Ops;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** The watchdog for the nightly backup: fresh and intact passes; missing, old, incomplete or altered fails. */
class BackupCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/backup-check-'.uniqid();
        File::makeDirectory($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function makeSet(string $stamp, array $files = ['database.dump' => 'dump', 'env.backup' => 'APP_KEY=base64:x', 'storage.tar.gz' => 'tar']): string
    {
        $set = "{$this->dir}/set-{$stamp}";
        File::makeDirectory($set, 0700, true);
        $sums = '';
        foreach ($files as $name => $content) {
            file_put_contents("{$set}/{$name}", $content);
            $sums .= hash('sha256', $content)."  {$name}\n";
        }
        file_put_contents("{$set}/SHA256SUMS", $sums);

        return $set;
    }

    private function stamp(int $hoursAgo): string
    {
        return now('UTC')->subHours($hoursAgo)->format('Ymd\THis').'Z';
    }

    public function test_it_does_nothing_until_a_backup_folder_is_configured(): void
    {
        config(['ops.backup_dir' => null]);
        $this->artisan('finance:ops:backup-check')->expectsOutputToContain('not set')->assertExitCode(0);
    }

    public function test_a_fresh_intact_set_passes(): void
    {
        $this->makeSet($this->stamp(2));
        $this->artisan('finance:ops:backup-check', ['--dir' => $this->dir])->expectsOutputToContain('fresh and intact')->assertExitCode(0);
    }

    public function test_no_set_a_stale_set_a_missing_file_and_an_altered_file_each_fail(): void
    {
        $this->artisan('finance:ops:backup-check', ['--dir' => $this->dir])->expectsOutputToContain('No backup set')->assertExitCode(1);

        $old = $this->makeSet($this->stamp(50));
        $this->artisan('finance:ops:backup-check', ['--dir' => $this->dir])->expectsOutputToContain('hours old')->assertExitCode(1);
        File::deleteDirectory($old);

        $set = $this->makeSet($this->stamp(1));
        file_put_contents("{$set}/database.dump", 'tampered');
        $this->artisan('finance:ops:backup-check', ['--dir' => $this->dir])->expectsOutputToContain('does not match its checksum')->assertExitCode(1);
        File::deleteDirectory($set);

        $set = $this->makeSet($this->stamp(1), ['database.dump' => 'dump']);
        $this->artisan('finance:ops:backup-check', ['--dir' => $this->dir])->expectsOutputToContain('no copy of .env')->assertExitCode(1);
        File::deleteDirectory($set);

        $set = $this->makeSet($this->stamp(1));
        unlink("{$set}/storage.tar.gz");
        $this->artisan('finance:ops:backup-check', ['--dir' => $this->dir])->expectsOutputToContain('is listed but missing')->assertExitCode(1);
    }

    public function test_the_watchdog_is_on_the_schedule(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('finance:ops:backup-check')->assertExitCode(0);
    }

    public function test_the_backup_and_restore_scripts_parse_and_the_drill_refuses_to_touch_the_source_database(): void
    {
        foreach (['backup.sh', 'restore-drill.sh'] as $script) {
            $path = base_path('scripts/ops/'.$script);
            $this->assertFileExists($path);
            $this->assertTrue(is_executable($path), "{$script} is executable");
            exec('bash -n '.escapeshellarg($path).' 2>&1', $out, $code);
            $this->assertSame(0, $code, "{$script} has a syntax error: ".implode(' ', $out));
        }

        // a drill database must be named restore_drill_<digits> and may never be the source database
        $script = (string) file_get_contents(base_path('scripts/ops/restore-drill.sh'));
        $this->assertStringContainsString('^restore_drill_[0-9]{8,14}$', $script);
        $this->assertStringContainsString('the drill database equals the source database', $script);
        $this->assertStringContainsString('APP_CONFIG_CACHE', $script, 'a cached config cannot point the checks at the live database');
    }
}
