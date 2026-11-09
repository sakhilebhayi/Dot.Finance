<?php

namespace Tests\Feature\Integration;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** R-44 integration: the scheduler is one coherent plan, not 15 modules each picking midnight. */
class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<Event> */
    private function events(): array
    {
        return app(Schedule::class)->events();
    }

    public function test_no_two_scheduled_commands_start_in_the_same_minute(): void
    {
        $at = [];
        foreach ($this->events() as $event) {
            $cron = new CronExpression($event->expression);
            $run = CarbonImmutable::parse('2026-11-01 00:00:00')->subMinute();
            // a whole week covers every daily and weekly expression
            for ($i = 0; $i < 400; $i++) {
                $run = CarbonImmutable::instance($cron->getNextRunDate($run, 0, false));
                if ($run->gte(CarbonImmutable::parse('2026-11-08 00:00:00'))) {
                    break;
                }
                $at[$run->format('D H:i')][] = $event->command;
            }
        }

        $collisions = array_filter($at, fn (array $commands) => count($commands) > 1);
        $this->assertSame([], $collisions, 'Commands share a minute: '.json_encode($collisions));
    }

    public function test_every_scheduled_command_cannot_overlap_itself_and_runs_on_one_server(): void
    {
        $this->assertNotEmpty($this->events());

        foreach ($this->events() as $event) {
            $this->assertTrue($event->withoutOverlapping, $event->command.' can overlap itself');
            $this->assertTrue($event->onOneServer, $event->command.' is not onOneServer');
        }
    }

    public function test_the_findings_scan_runs_after_the_things_that_feed_it_and_the_notifications_after_the_tax_calendar(): void
    {
        $time = function (string $needle): string {
            foreach ($this->events() as $event) {
                if (str_contains($event->command, $needle)) {
                    [$minute, $hour] = explode(' ', $event->expression);

                    return sprintf('%02d:%02d', $hour, $minute);
                }
            }
            $this->fail("{$needle} is not scheduled");
        };

        $this->assertLessThan($time('finance:intel:scan'), $time('finance:gl:post-pending'), 'the scan reads posted journals');
        $this->assertLessThan($time('finance:intel:scan'), $time('finance:gl:verify'));
        $this->assertLessThan($time('finance:intel:scan'), $time('finance:purchases:recurring-bills'), 'the scan reads the recurring drafts');
        $this->assertLessThan($time('finance:notifications:run'), $time('finance:tax:calendar'), 'notifications deliver the reminders the calendar creates');
        $this->assertLessThan($time('finance:notifications:run'), $time('finance:intel:scan'));
        $this->assertLessThan($time('finance:gl:post-pending'), $time('sales:run-recurring'), 'repeating invoices are posted before the nightly sweep');
        $this->assertLessThan($time('sales:check-receivables'), $time('finance:gl:post-pending'), 'the receivables proof runs after the sweep');
    }

    public function test_the_deploy_workflow_installs_the_scheduler_line_and_seeds_reference_data_loudly(): void
    {
        $deploy = file_get_contents(base_path('.github/workflows/deploy.yml'));

        $this->assertStringContainsString('artisan schedule:run', $deploy);
        $this->assertStringContainsString('artisan schedule:list', $deploy, 'the cron line is decided from schedule:list');

        // the seed runs right after migrate, and must not be able to fail silently
        $this->assertMatchesRegularExpression('/artisan migrate --force\s*\n(?:\s*#[^\n]*\n)*\s*\$PHP artisan finance:seed-reference\s*\n/', $deploy);
        $this->assertDoesNotMatchRegularExpression('/finance:seed-reference[^\n]*\|\|/', $deploy, 'finance:seed-reference must fail the deploy, not be swallowed');
    }
}
