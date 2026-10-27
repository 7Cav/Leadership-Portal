<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The game servers the telemetry APIs list (#389), and each capture's read of one. Portal-owned (AD-22): a fetch
     * writes the listing; an Admin selects a server, assigns it a game and marks it training or public. Credentials are
     * not here: they stay in env, one set per interface (AD-23). `external_id` is the server's id at its interface
     * (the BattleMetrics or Nightwatch server id, `crcon` for the one CRCON instance).
     */
    public function up(): void
    {
        Schema::create('game_servers', function (Blueprint $table) {
            $table->id();
            $table->string('interface');
            $table->string('external_id');
            $table->string('name');
            // A game a server is assigned to is in use: retired, not deleted (#388).
            $table->foreignId('game_id')->nullable()->constrained('games')->restrictOnDelete();
            $table->string('type')->default('public');
            $table->boolean('selected')->default(false);
            // A CRCON server's BattleMetrics twin, read when CRCON cannot be.
            $table->foreignId('fallback_server_id')->nullable()->constrained('game_servers')->nullOnDelete();
            // Set when a fetch of its interface succeeded without it; cleared when it is listed again.
            $table->dateTime('missing_since')->nullable();
            $table->timestamps();

            $table->unique(['interface', 'external_id']);
        });

        Schema::create('game_server_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_server_id')->constrained('game_servers')->cascadeOnDelete();
            $table->foreignId('sync_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->boolean('succeeded');
            $table->string('reason')->nullable();
            $table->dateTime('read_at');

            $table->index(['game_server_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_server_reads');
        Schema::dropIfExists('game_servers');
    }
};
