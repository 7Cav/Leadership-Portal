<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * #174: how an identity row was made. `typed` by a leader on one game, `from_account` by linking a Steam or Epic
     * account to every game that asks for it (or by an Admin's backfill of that account onto a game), `from_rollcall`
     * by linking an unmatched player from a capture. Rows saved before this column read as typed. Portal-owned (AD-22).
     */
    public function up(): void
    {
        Schema::table('trooper_game_identities', function (Blueprint $table) {
            $table->string('source', 20)->default('typed')->after('id_kind');
        });
    }

    public function down(): void
    {
        Schema::table('trooper_game_identities', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
