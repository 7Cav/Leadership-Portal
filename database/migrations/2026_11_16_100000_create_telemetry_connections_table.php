<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How each game's telemetry connections last went (#183), for the Game server connections page: one row per game and
     * source it reads, kept up to date by every capture run and every "Test connection". A row is the outcome, never the
     * credentials or the address (AD-23). `target` names the source within the game: `server:{id}:{interface}` for a
     * selected server (a CRCON server read through its BattleMetrics fallback is the fallback's own row), or
     * `env:{interface}` for the server set in env.
     */
    public function up(): void
    {
        Schema::create('telemetry_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->string('target');
            $table->foreignId('game_server_id')->nullable()->constrained('game_servers')->cascadeOnDelete();
            $table->string('interface');
            $table->dateTime('last_succeeded_at')->nullable();
            $table->dateTime('last_failed_at')->nullable();
            $table->string('last_failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'target']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_connections');
    }
};
