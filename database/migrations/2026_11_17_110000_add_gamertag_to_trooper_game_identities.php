<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The player's current in-game name on an identity, for Xbox, PlayStation and Epic players whose ID tells a leader
     * nothing. Shown only, never matched on: matching stays by `platform_id`, and a gamertag can change at any time.
     * `gamertag_from_server` is set when a game server gave it, clear when a leader typed it. Portal-owned (AD-22).
     */
    public function up(): void
    {
        Schema::table('trooper_game_identities', function (Blueprint $table) {
            $table->string('gamertag', 100)->nullable()->after('id_kind');
            $table->boolean('gamertag_from_server')->default(false)->after('gamertag');
        });
    }

    public function down(): void
    {
        Schema::table('trooper_game_identities', function (Blueprint $table) {
            $table->dropColumn(['gamertag', 'gamertag_from_server']);
        });
    }
};
