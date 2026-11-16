<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** R-53: the outbox of confirmations owed to InfoDot. A row is written in the same transaction as the fact it reports and is never deleted. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licence_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 120)->unique();
            $table->uuid('batch_uuid')->index();
            $table->string('type', 24);
            $table->text('payload');
            // pending (to send or retry), sent, rejected (InfoDot refused the content: a person must look), dead (gave up after the attempt limit)
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->string('last_error', 200)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('infodot_answer', 24)->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licence_outbox');
    }
};
