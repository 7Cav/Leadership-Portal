<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How long the capture saw the player on the server inside the practice window (#176): the roll call shows it on
        // each unmatched player, so a leader can tell a full practice from someone passing through. Null when the source
        // only knows the player was seen (BattleMetrics, Nightwatch, CRCON's first/last-seen fallback), and for every row
        // stored before this existed (a cache, rewritten by the next capture).
        Schema::table('practice_capture_players', function (Blueprint $table) {
            $table->unsignedSmallInteger('minutes')->nullable()->after('id_source');
        });
    }

    public function down(): void
    {
        Schema::table('practice_capture_players', function (Blueprint $table) {
            $table->dropColumn('minutes');
        });
    }
};
