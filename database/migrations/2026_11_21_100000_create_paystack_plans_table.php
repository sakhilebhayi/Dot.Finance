<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** R-53: the cache of Paystack plans, one per exact (amount, currency, interval). Additive. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paystack_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('interval', 16);
            $table->string('plan_code', 64)->unique();
            $table->string('name', 160);
            $table->timestamps();
            $table->unique(['amount_minor', 'currency', 'interval']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paystack_plans');
    }
};
