<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * #174: an identity is matched by its platform ID, and the player name it held was only shown, and went stale when a
     * player renamed. The name goes, and with it `name_mixes_scripts` (#450), the flag on that name. Names the game
     * servers report (capture players, dismissals) are kept: they are what a leader knows an unknown player by.
     */
    public function up(): void
    {
        Schema::table('trooper_game_identities', function (Blueprint $table) {
            $table->dropColumn(['name_mixes_scripts', 'player_name']);
        });
    }

    /** Both come back nullable: the names dropped are gone, and rows saved since have none. */
    public function down(): void
    {
        Schema::table('trooper_game_identities', function (Blueprint $table) {
            $table->string('player_name')->nullable()->after('game_id');
            $table->boolean('name_mixes_scripts')->nullable()->after('player_name');
        });
    }
};
