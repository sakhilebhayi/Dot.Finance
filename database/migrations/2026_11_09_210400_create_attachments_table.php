<?php

use App\Support\Database\TableChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-44 shared data layer, part 5: attachments (receipts, invoices, contracts).
 *
 * The file lives on the private disk; this row is its record. The sha256 of the content
 * is stored so a duplicate can be told within a ledger, and the original is never
 * overwritten or edited: a new upload is a new row. An attachment is archived, not
 * deleted (tax records are kept for five years).
 *
 * extraction_json / extraction_status hold a reviewable draft read from the document
 * (never trusted until a person has reviewed it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
            // the owning record (a bill, an invoice, a party); both null while the file waits in an inbox
            $table->string('attachable_type', 60)->nullable();
            $table->unsignedBigInteger('attachable_id')->nullable();
            $table->string('disk', 30);
            $table->string('path', 500);
            $table->char('sha256', 64);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('extraction_status', 12)->default('none');
            $table->string('extraction_engine', 60)->nullable();
            $table->json('extraction_json')->nullable();
            $table->timestamp('extracted_at')->nullable();
            $table->foreignId('extraction_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('extraction_reviewed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id'], 'attachments_attachable_index');
            $table->index(['user_id', 'sha256'], 'attachments_user_hash_index');
            $table->index(['team_id', 'sha256'], 'attachments_team_hash_index');
            $table->unique(['disk', 'path'], 'attachments_disk_path_unique');
        });

        TableChecks::ownerRule('attachments');
        TableChecks::add('attachments', 'hash_format', 'length(sha256) = 64', ['sha256'], 'attachments: sha256 is 64 hex characters');
        TableChecks::add('attachments', 'size_positive', 'size > 0', ['size'], 'attachments: an empty file cannot be attached');
        TableChecks::add('attachments', 'extraction_status_values', "extraction_status IN ('none', 'pending', 'draft', 'reviewed', 'rejected', 'failed')", ['extraction_status'], 'attachments: unknown extraction status');
        TableChecks::add('attachments', 'attachable_pair', '(attachable_type IS NULL) = (attachable_id IS NULL)', ['attachable_type', 'attachable_id'], 'attachments: attachable_type and attachable_id go together');
    }

    public function down(): void
    {
        foreach (['owner_rule', 'hash_format', 'size_positive', 'extraction_status_values', 'attachable_pair'] as $rule) {
            TableChecks::drop('attachments', $rule);
        }
        Schema::dropIfExists('attachments');
    }
};
