<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Story 7.7: a leader's "this player is not a member" (AD-19). Portal-owned (AD-22): not
     * re-fetchable from any source, so it belongs on the backup list. One writer — the audited
     * dismiss Command; linking the same identity later clears it (see `SetTrooperGameIdentityHandler`).
     */
    public function up(): void
    {
        Schema::create('game_player_dismissals', function (Blueprint $table) {
            $table->id();
            $table->enum('platform', ['hll', 'arma', 'wardogs']);
            $table->string('platform_id');
            $table->string('player_name');
            $table->foreignId('dismissed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['platform', 'platform_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_player_dismissals');
    }
};
