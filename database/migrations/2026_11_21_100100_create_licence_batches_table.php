<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** R-53: one row per InfoDot licence batch (one Paystack subscription per batch). Additive; amounts in minor units. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licence_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_uuid')->unique();
            $table->uuid('team_uuid')->index();
            $table->unsignedInteger('quantity');
            $table->string('interval', 16);
            $table->string('interval_raw', 16);
            $table->unsignedBigInteger('total_minor');
            $table->string('currency', 3);
            $table->string('customer_name', 200);
            $table->string('customer_email', 200);
            // pending (no checkout yet), awaiting_payment, active, past_due, cancelled, refunded
            $table->string('status', 24)->default('pending')->index();
            $table->string('paystack_plan_code', 64)->nullable();
            $table->string('paystack_reference', 120)->nullable()->unique();
            $table->unsignedSmallInteger('init_attempts')->default(0);
            $table->text('checkout_url')->nullable();
            $table->string('paystack_customer_code', 64)->nullable()->index();
            $table->text('authorization_code')->nullable();
            $table->string('paystack_subscription_code', 64)->nullable()->index();
            $table->text('subscription_email_token')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('first_paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licence_batches');
    }
};
