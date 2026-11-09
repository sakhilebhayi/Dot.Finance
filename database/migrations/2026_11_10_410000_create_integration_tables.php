<?php

use App\Support\Database\Checks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-44 Integrations: the receipt log for signed inbound calls (so a replay is recognised) and the staging table
 * for Dot.Emall sales lines. Nothing arriving from another platform is journalled directly: it lands here, is
 * recomputed, and a person with the right capability posts it.
 */
return new class extends Migration
{
    private const OWNED = ['integration_inbound_events', 'emall_sale_lines'];

    public function up(): void
    {
        Schema::create('integration_inbound_events', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->string('source', 24);
            // The sender's own id for this delivery (a batch id), unique per ledger and source.
            $table->string('external_id', 100);
            $table->char('payload_sha256', 64);
            // received | duplicate | rejected
            $table->string('status', 10);
            $table->json('summary')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('emall_sale_lines', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('inbound_event_id')->nullable()->constrained('integration_inbound_events')->nullOnDelete();
            // Emall's order_item id: the idempotency key. A line is never imported twice.
            $table->string('order_item_id', 64);
            $table->string('order_ref', 64);
            $table->string('seller_ref', 64)->nullable();
            $table->date('sold_on');
            $table->char('currency', 3);
            $table->string('description', 255);
            $table->unsignedInteger('quantity')->default(1);
            // As recomputed here, from the unit price, quantity and discount in the payload.
            $table->unsignedBigInteger('gross_cents');
            $table->unsignedBigInteger('vat_cents')->default(0);
            $table->unsignedBigInteger('fee_cents')->default(0);
            $table->string('tax_code', 40)->nullable();
            // What the sender claimed, kept so a difference stays visible.
            $table->json('claimed');
            // staged | needs_attention | posted | rejected
            $table->string('status', 16);
            $table->json('problems')->nullable();
            // No foreign key on purpose (as payments.gl_entry_id): the journal tables belong to the accounting migration, which an
            // older test rolls back, and a posted journal entry is append-only so the link can never dangle. Same-ledger is checked
            // by LedgerOwnership through the model's ledgerReferences().
            $table->unsignedBigInteger('journal_entry_id')->nullable()->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        foreach (self::OWNED as $name) {
            Checks::ownerRule($name);
        }

        Checks::add('integration_inbound_events', 'status_valid', "status IN ('received','duplicate','rejected')", ['status']);
        Checks::add('emall_sale_lines', 'status_valid', "status IN ('staged','needs_attention','posted','rejected')", ['status']);
        Checks::add('emall_sale_lines', 'currency_valid', Checks::currency('currency'), ['currency']);

        DB::statement('CREATE UNIQUE INDEX integration_inbound_events_one_per_delivery ON integration_inbound_events (COALESCE(user_id, 0), COALESCE(team_id, 0), source, external_id)');
        DB::statement('CREATE UNIQUE INDEX emall_sale_lines_one_per_order_item ON emall_sale_lines (COALESCE(user_id, 0), COALESCE(team_id, 0), order_item_id)');
    }

    public function down(): void
    {
        foreach (array_reverse(self::OWNED) as $name) {
            Schema::dropIfExists($name);
        }
    }

    private function owner(Blueprint $table): void
    {
        $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
        $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
    }
};
