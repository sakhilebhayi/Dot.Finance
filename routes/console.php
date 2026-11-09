<?php

use App\Console\Commands\GenerateInsights;
use App\Console\Commands\GenerateRecurringTransactions;
use App\Console\Commands\Gl\PostPending;
use App\Console\Commands\Gl\Verify;
use App\Console\Commands\PruneImports;
use App\Console\Commands\Sales\CheckReceivables;
use App\Console\Commands\Sales\ExpireQuotes;
use App\Console\Commands\Sales\RunRecurring;
use App\Console\Commands\Sales\SendReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Generate each user's weekly AI Insight from their real reserve-runway
// figures. See docs/superpowers/specs/2026-08-09-weekly-insight-generator-design.md.
// This is this repo's first scheduled command -- establishes rather than
// follows an existing schedule convention.
Schedule::command(GenerateInsights::class)
    ->weekly()
    ->withoutOverlapping()
    ->onOneServer();

// Generate any recurring transaction occurrences due today, across all
// users -- daily rather than matching each transaction's own cadence,
// since a recurring transaction can be due at any interval (daily through
// yearly) and this just checks "is next_occurrence_date <= today" each run.
Schedule::command(GenerateRecurringTransactions::class)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();

// Staged CSV uploads that were previewed but never imported are deleted after a day.
Schedule::command(PruneImports::class)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();

// The journals' safety net: post anything the cash ledger changed that explicit posting missed, then prove
// the books still tie out. A failing verify exits non-zero, which the scheduler watchdog reports.
Schedule::command(PostPending::class)
    ->dailyAt('02:10')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(Verify::class)
    ->dailyAt('02:40')
    ->withoutOverlapping()
    ->onOneServer();

// --- sales ---
// Payment reminders (each is sent once: the log's unique key makes every run safe), repeating invoices (one per template and
// period), quotes past their date, and the proof that receivables still equal the books (a difference exits non-zero).
Schedule::command(RunRecurring::class)->dailyAt('01:30')->withoutOverlapping()->onOneServer();
Schedule::command(ExpireQuotes::class)->dailyAt('01:45')->withoutOverlapping()->onOneServer();
Schedule::command(SendReminders::class)->dailyAt((string) config('sales.reminders.time', '07:30'))->withoutOverlapping()->onOneServer();
Schedule::command(CheckReceivables::class)->dailyAt('02:50')->withoutOverlapping()->onOneServer();
// --- end sales ---
