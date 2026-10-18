<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly summaries become one per user per ISO week, and the transactions table
 * gets the two composite indexes the reports and balance queries read through.
 *
 * Existing summaries are never deleted. Each gets its ISO week from created_at;
 * where one user already has several for the same week (the job used to insert a
 * new row on every run), only the latest takes the week and the older ones keep
 * their row with a null iso_week, which the unique index ignores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_insights', function (Blueprint $table) {
            $table->string('iso_week', 8)->nullable()->after('type');
        });

        $taken = [];

        DB::table('ai_insights')->orderByDesc('created_at')->orderByDesc('id')->chunk(500, function ($rows) use (&$taken) {
            foreach ($rows as $row) {
                $week = Carbon::parse($row->created_at)->format('o-\WW');
                $key = $row->user_id.'|'.$week;

                if (isset($taken[$key])) {
                    continue;
                }

                $taken[$key] = true;
                DB::table('ai_insights')->where('id', $row->id)->update(['iso_week' => $week]);
            }
        });

        Schema::table('ai_insights', function (Blueprint $table) {
            $table->unique(['user_id', 'iso_week']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['user_id', 'date']);
            $table->index(['account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'date']);
            $table->dropIndex(['account_id', 'date']);
        });

        Schema::table('ai_insights', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'iso_week']);
            $table->dropColumn('iso_week');
        });
    }
};
