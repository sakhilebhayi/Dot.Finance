<?php

use App\Support\Database\TableChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-44 shared data layer, part 4: document sequences (invoice, credit note, quote...
 * numbers). One row per ledger and document type; the number is taken under a row lock
 * inside the caller's transaction, so numbers are gap-free at issue (see Numbering).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->string('doc_type', 40);
            $table->string('prefix', 20)->default('');
            // the next number to hand out
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(5);
            // restart at 1 each calendar year, and put the year in the number
            $table->boolean('year_reset')->default(false);
            // the year next_number belongs to (only used when year_reset)
            $table->unsignedSmallInteger('current_year')->nullable();
            $table->timestamp('last_issued_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'doc_type']);
            $table->unique(['team_id', 'doc_type']);
        });

        TableChecks::ownerRule('document_sequences');
        TableChecks::add('document_sequences', 'next_number_positive', 'next_number >= 1', ['next_number'], 'document_sequences: numbering starts at 1');
        TableChecks::add('document_sequences', 'padding_range', 'padding >= 1 AND padding <= 12', ['padding'], 'document_sequences: padding is 1 to 12 digits');
        TableChecks::add('document_sequences', 'doc_type_format', 'length(doc_type) > 0', ['doc_type'], 'document_sequences: a document type is required');
    }

    public function down(): void
    {
        foreach (['owner_rule', 'next_number_positive', 'padding_range', 'doc_type_format'] as $rule) {
            TableChecks::drop('document_sequences', $rule);
        }
        Schema::dropIfExists('document_sequences');
    }
};
