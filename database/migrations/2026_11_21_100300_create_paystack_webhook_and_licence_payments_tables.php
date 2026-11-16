<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-53: processed Paystack events (a duplicate delivery is a no-op) and one row per verified licence payment (unique by Paystack
 * reference, so one renewal seen as both invoice.update and charge.success grants value once). Additive. No card data is stored:
 * the event row keeps a hash of the body, not the body.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paystack_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 190)->unique();
            $table->string('event_type', 60);
            $table->string('reference', 120)->nullable()->index();
            $table->string('body_hash', 64);
            // received (in progress or failed once), processed, ignored (verified but not ours / nothing to do)
            $table->string('status', 16)->default('received');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 200)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('licence_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('licence_batch_id')->nullable()->constrained('licence_batches')->restrictOnDelete();
            $table->string('paystack_reference', 120)->unique();
            $table->string('kind', 12); // initial | renewal
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->timestamp('paid_at');
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            // paid, refunded, partially_refunded, unmatched (money in that no batch could be tied to: a person must look)
            $table->string('status', 20)->default('paid');
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->string('refund_id', 60)->nullable();
            $table->timestamps();
            $table->index('licence_batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licence_payments');
        Schema::dropIfExists('paystack_webhook_events');
    }
};
