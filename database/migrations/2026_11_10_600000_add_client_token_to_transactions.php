<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-44 acceptance QA (D-1604): the "Add a transaction" form carries a one-time token so a double click, a retried request or a
 * browser re-submit records one transaction, as the invoice and bill forms already do. The token is a random UUID the server hands
 * out with the form, so it is unique on its own (an index on the owner columns would also stop the older company-context
 * migration being rolled back, which a test does on purpose). Rows made by other means (imports, settlements, recurring rules, the
 * API) have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('client_token', 64)->nullable();
            $table->unique('client_token', 'transactions_client_token_unique');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_client_token_unique');
            $table->dropColumn('client_token');
        });
    }
};
