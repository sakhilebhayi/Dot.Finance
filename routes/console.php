<?php

use App\Console\Commands\GenerateInsights;
use App\Console\Commands\GenerateRecurringTransactions;
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
    ->withoutOverlapping();

// Generate any recurring transaction occurrences due today, across all
// users -- daily rather than matching each transaction's own cadence,
// since a recurring transaction can be due at any interval (daily through
// yearly) and this just checks "is next_occurrence_date <= today" each run.
Schedule::command(GenerateRecurringTransactions::class)
    ->daily()
    ->withoutOverlapping();
