<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** R-53: which invoice a licence payment produced, and the credit notes its refunds produced. Additive. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licence_payments', function (Blueprint $table) {
            // null = not tried, issued, skipped (seller not configured), failed (error recorded, retried by licences:invoices)
            $table->string('invoice_status', 16)->nullable()->index();
            $table->unsignedBigInteger('sales_document_id')->nullable()->index();
            $table->string('invoice_error', 255)->nullable();
        });

        Schema::create('licence_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('licence_payment_id')->constrained('licence_payments')->restrictOnDelete();
            $table->string('refund_id', 60);
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 16)->default('pending'); // pending, issued, failed
            $table->unsignedBigInteger('sales_document_id')->nullable()->index();
            $table->string('error', 255)->nullable();
            $table->timestamps();
            $table->unique(['licence_payment_id', 'refund_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licence_credit_notes');
        Schema::table('licence_payments', function (Blueprint $table) {
            $table->dropColumn(['invoice_status', 'sales_document_id', 'invoice_error']);
        });
    }
};
