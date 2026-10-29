<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The game a unit plays (policy, 2026-10-29), picked by an Admin from the Games list. Null means the unit inherits
     * the game of the nearest unit above it that has one. Portal-owned (AD-22): `SetUnitGame` is the one writer. The
     * free-text `label` stays as the unit's team label; nothing is copied from it.
     */
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            // A game a unit plays is in use: retired, not deleted (#388).
            $table->foreignId('game_id')->nullable()->after('label')->constrained('games')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('game_id');
        });
    }
};
