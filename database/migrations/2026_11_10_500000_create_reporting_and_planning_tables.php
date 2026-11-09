<?php

use App\Support\Database\Checks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-44 Reporting and Planning (wave 2): queued report exports, saved report presets, dimensions
 * (departments and projects), planning budgets with their monthly lines, and what-if scenarios.
 *
 * None of these tables is part of the books: a scenario or a plan never writes a journal. Every table
 * carries the ledger owner pair (user_id XOR team_id, CHECK on PostgreSQL, trigger on SQLite). Foreign
 * keys to users and teams are RESTRICT, as for the accounting tables: removing a person does not
 * silently remove a company's plans or exports.
 */
return new class extends Migration
{
    private const OWNED = ['report_exports', 'report_presets', 'dimensions', 'plan_budgets', 'plan_budget_lines', 'plan_scenarios'];

    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('report_key', 40);
            $table->string('format', 8);
            $table->json('params');
            $table->char('params_hash', 64);
            // Hash of (ledger, report, params, format, last journal id): asking twice for the same thing about the same
            // books is the same export. A new posting changes the key, so a stale file is never handed out as current.
            $table->char('idempotency_key', 64);
            $table->unsignedBigInteger('journal_seq')->default(0);
            $table->string('status', 10)->default('queued');
            $table->string('disk', 20)->nullable();
            $table->string('path', 255)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->unique(['team_id', 'idempotency_key']);
            $table->index(['user_id', 'id']);
            $table->index(['team_id', 'id']);
        });

        Schema::create('report_presets', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->string('report_key', 40);
            $table->json('params');
            $table->timestamps();

            $table->unique(['user_id', 'report_key', 'name']);
            $table->unique(['team_id', 'report_key', 'name']);
        });

        Schema::create('dimensions', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            // The string journal lines carry in `dimension`, e.g. "department:3" or "project:12".
            $table->string('ref', 80);
            $table->string('kind', 12);
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'ref']);
            $table->unique(['team_id', 'ref']);
        });

        Schema::create('plan_budgets', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->string('name', 120);
            $table->string('kind', 12)->default('budget');
            $table->unsignedSmallInteger('fiscal_year');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->char('currency', 3);
            $table->string('status', 10)->default('draft');
            $table->foreignId('based_on_id')->nullable()->constrained('plan_budgets')->restrictOnDelete();
            $table->string('method', 20)->nullable();
            $table->date('as_of')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'fiscal_year']);
            $table->index(['user_id', 'fiscal_year']);
        });

        Schema::create('plan_budget_lines', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('plan_budget_id')->constrained('plan_budgets')->cascadeOnDelete();
            $table->foreignId('gl_account_id')->constrained('gl_accounts')->restrictOnDelete();
            $table->string('dimension', 80)->nullable();
            $table->date('month');
            $table->unsignedBigInteger('amount_cents');
            $table->timestamps();

            $table->index(['plan_budget_id', 'gl_account_id']);
            $table->index('dimension');
        });

        Schema::create('plan_scenarios', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->string('name', 120);
            $table->string('horizon', 12)->default('weeks_13');
            $table->json('assumptions');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
            $table->unique(['team_id', 'name']);
        });

        foreach (self::OWNED as $table) {
            Checks::ownerRule($table);
        }

        $this->checks();
    }

    public function down(): void
    {
        foreach (array_reverse(self::OWNED) as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function owner(Blueprint $table): void
    {
        $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
        $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
    }

    private function checks(): void
    {
        Checks::add('report_exports', 'report_exports_status_valid', "status IN ('queued','running','done','failed')", ['status']);
        Checks::add('report_exports', 'report_exports_format_valid', "format IN ('csv','xlsx')", ['format']);
        Checks::add('plan_budgets', 'plan_budgets_status_valid', "status IN ('draft','pending','approved','rejected','archived')", ['status']);
        Checks::add('plan_budgets', 'plan_budgets_kind_valid', "kind IN ('budget','reforecast')", ['kind']);
        Checks::add('plan_budgets', 'plan_budgets_dates_valid', 'ends_on >= starts_on', ['starts_on', 'ends_on']);
        Checks::add('plan_budgets', 'plan_budgets_currency_format', Checks::currency('currency'), ['currency']);
        Checks::add('dimensions', 'dimensions_kind_valid', "kind IN ('department','project','other')", ['kind']);
        Checks::add('plan_scenarios', 'plan_scenarios_horizon_valid', "horizon IN ('weeks_13','months_12')", ['horizon']);

        // One line per budget, account, dimension and month; a NULL dimension counts as "none" for uniqueness.
        DB::statement("CREATE UNIQUE INDEX plan_budget_lines_one_per_cell ON plan_budget_lines (plan_budget_id, gl_account_id, COALESCE(dimension, ''), month)");
    }
};
