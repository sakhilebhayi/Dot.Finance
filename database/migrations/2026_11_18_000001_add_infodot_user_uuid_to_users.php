<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-54 phase 2: the stable link from a local user to their InfoDot identity (the OIDC `sub`, InfoDot's users.uuid).
 * Additive: nullable, unique, nothing is backfilled and no existing row changes. Money ledgers hang off the user row, so
 * linking attaches to the existing user (by this id, or by verified email, or by the emailed claim code); it never creates a
 * second user and never moves a ledger. Email is a lookup of last resort, never the identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'infodot_user_uuid')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('infodot_user_uuid', 64)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'infodot_user_uuid')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['infodot_user_uuid']);
                $table->dropColumn('infodot_user_uuid');
            });
        }
    }
};
