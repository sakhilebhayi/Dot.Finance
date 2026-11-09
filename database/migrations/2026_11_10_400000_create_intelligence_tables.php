<?php

use App\Support\Database\Checks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-44 Intelligence: the assistant's history and drafts, the findings the anomaly detectors write, the in-app
 * notifications and each person's notification preferences.
 *
 * Every table carries the ledger owner pair (user_id XOR team_id, enforced like the rest of the ledger). A person's
 * own rows (an exchange, a preference, a notification) also name the person: user_id is always the LEDGER's owner and
 * never "who asked".
 */
return new class extends Migration
{
    private const OWNED = ['assistant_messages', 'assistant_drafts', 'finance_insights', 'finance_notifications', 'notification_preferences'];

    public function up(): void
    {
        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('asker_id')->constrained('users')->cascadeOnDelete();
            $table->string('question', 500);
            $table->string('intent', 40);
            // answered | no_data | refused | unrecognised
            $table->string('status', 16);
            $table->text('answer_text');
            // The structured answer: facts, rows, sources. Numbers only ever come from here.
            $table->json('answer');
            // calculated | estimate | ai_summary
            $table->string('basis', 16);
            $table->boolean('model_used')->default(false);
            $table->boolean('model_rejected')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['team_id', 'asker_id', 'id']);
            $table->index(['user_id', 'asker_id', 'id']);
        });

        Schema::create('assistant_drafts', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('asker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('assistant_messages')->nullOnDelete();
            // transaction | categorise | invoice_reminder
            $table->string('kind', 24);
            $table->json('payload');
            $table->string('summary', 300);
            // pending | confirmed | dismissed | expired | failed
            $table->string('status', 12)->default('pending');
            $table->timestamp('expires_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('result_type', 40)->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->string('failure', 300)->nullable();
            $table->timestamps();

            $table->index(['team_id', 'asker_id', 'status']);
            $table->index(['user_id', 'asker_id', 'status']);
        });

        Schema::create('finance_insights', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->string('kind', 32);
            // What the finding is about, stable across scans (a category, a party, a pair of transactions).
            $table->string('subject_key', 120);
            // The month (or other window) it was found in, so the same thing in a later month is a new finding.
            $table->string('period_key', 16);
            // info | notice | warning | critical
            $table->string('severity', 10);
            $table->string('title', 200);
            $table->text('body');
            $table->json('evidence');
            $table->string('href', 300)->nullable();
            $table->string('basis', 16)->default('calculated');
            // open | dismissed | snoozed | resolved
            $table->string('status', 10)->default('open');
            $table->date('snoozed_until')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->index(['team_id', 'status', 'severity']);
            $table->index(['user_id', 'status', 'severity']);
        });

        Schema::create('finance_notifications', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            // payment_reminder | tax_deadline | approval_waiting | overdue_summary | insight | weekly_digest | system
            $table->string('kind', 24);
            $table->string('severity', 10)->default('info');
            $table->string('title', 200);
            $table->text('body');
            $table->string('href', 300)->nullable();
            // One notification per recipient per thing: a rerun of the scheduler cannot repeat it.
            $table->string('dedupe_key', 120);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('emailed_at')->nullable();
            // none | sent | failed | skipped
            $table->string('email_status', 8)->default('none');
            $table->string('notify_status', 8)->default('none');
            $table->timestamps();

            $table->index(['recipient_id', 'read_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('member_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 24);
            $table->boolean('in_app')->default(true);
            $table->boolean('email')->default(true);
            $table->boolean('notify')->default(false);
            $table->timestamps();
        });

        foreach (self::OWNED as $name) {
            Checks::ownerRule($name);
        }

        Checks::add('assistant_messages', 'status_valid', "status IN ('answered','no_data','refused','unrecognised')", ['status']);
        Checks::add('assistant_messages', 'basis_valid', "basis IN ('calculated','estimate','ai_summary')", ['basis']);
        Checks::add('assistant_drafts', 'status_valid', "status IN ('pending','confirmed','dismissed','expired','failed')", ['status']);
        Checks::add('assistant_drafts', 'kind_valid', "kind IN ('transaction','categorise','invoice_reminder')", ['kind']);
        Checks::add('finance_insights', 'status_valid', "status IN ('open','dismissed','snoozed','resolved')", ['status']);
        Checks::add('finance_insights', 'severity_valid', "severity IN ('info','notice','warning','critical')", ['severity']);
        Checks::add('finance_notifications', 'severity_valid', "severity IN ('info','notice','warning','critical')", ['severity']);
        Checks::add('finance_notifications', 'email_status_valid', "email_status IN ('none','sent','failed','skipped')", ['email_status']);

        // A finding is one row per (ledger, kind, subject, period); a notification one per (ledger, person, key);
        // a preference one per (ledger, person, kind). COALESCE because a NULL owner is "different" to a plain index.
        $this->unique('finance_insights', 'finance_insights_one_per_subject', 'COALESCE(user_id, 0), COALESCE(team_id, 0), kind, subject_key, period_key');
        $this->unique('finance_notifications', 'finance_notifications_one_per_key', 'COALESCE(user_id, 0), COALESCE(team_id, 0), recipient_id, dedupe_key');
        $this->unique('notification_preferences', 'notification_preferences_one_per_kind', 'COALESCE(user_id, 0), COALESCE(team_id, 0), member_user_id, kind');
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
        // restrict, never cascade: removing a company must not silently remove its records.
        $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
    }

    private function unique(string $table, string $name, string $columns): void
    {
        DB::statement("CREATE UNIQUE INDEX {$name} ON {$table} ({$columns})");
    }
};
