<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set when `platform_id` is not the player's game ID but a service's own number for them: `battlemetrics`, a
        // BattleMetrics player number read through CRCON's fallback for a player BattleMetrics knew no game ID for. The
        // rollcall says so, and such a player is never linked to a trooper. Null for every game ID, and for every row
        // stored before this existed (a cache, rewritten by the next capture).
        Schema::table('practice_capture_players', function (Blueprint $table) {
            $table->string('id_source', 32)->nullable()->after('platform_id');
        });
    }

    public function down(): void
    {
        Schema::table('practice_capture_players', function (Blueprint $table) {
            $table->dropColumn('id_source');
        });
    }
};
