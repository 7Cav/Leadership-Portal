<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Story 7.7: what a capture run found but could not credit, kept so a leader can resolve it
     * (the queued job used to discard it). One writer — the capture handler, through
     * `PracticeCapturePlayerRepository`, replacing a practice's rows on every run. Re-derivable by
     * re-running the capture, so it is a cache, not a record of truth (unlike the identity links).
     *
     * `trooper_id` null = unmatched (no link, AD-19); set = linked to a trooper of another section.
     */
    public function up(): void
    {
        Schema::create('practice_capture_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_practice_id')->constrained('section_practices')->cascadeOnDelete();
            $table->enum('platform', ['hll', 'arma', 'wardogs']);
            $table->string('player_name');
            $table->string('platform_id');
            $table->foreignId('trooper_id')->nullable()->constrained('troopers')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['section_practice_id', 'platform', 'platform_id'], 'practice_capture_players_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_capture_players');
    }
};
