<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** R-53 slice 7: the buyer's address and VAT treatment as InfoDot sent them (stored, not used for maths), and when a cancel was asked. Additive. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licence_batches', function (Blueprint $table) {
            $table->text('customer_address')->nullable();
            $table->string('vat_treatment', 24)->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('licence_batches', function (Blueprint $table) {
            $table->dropColumn(['customer_address', 'vat_treatment', 'cancel_requested_at']);
        });
    }
};
