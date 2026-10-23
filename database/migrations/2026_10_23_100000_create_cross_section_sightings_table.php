<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every time a capture saw a linked trooper at a practice of a section that is not their own
     * (decided 2026-10-23): the groundwork for the Joint Service Achievement Medal candidates, a
     * later feature with no screen yet. A sighting is recorded whether or not it changed the
     * trooper's own mark: someone already present at their own practice that week, or a leader's
     * manual mark, still gets a row. The makeup a sighting may also put on the trooper's own
     * rollcall lives in `attendance`, not here.
     *
     * Portal-owned, one writer: the capture handler, through `CrossSectionSightingRepository`.
     * Unlike `practice_capture_players` (a cache the next run replaces), this is a record: a re-run
     * of the same capture finds the existing row through the UNIQUE key and leaves it, first
     * sighting wins, so nothing is duplicated and nothing earlier is lost when a later run reads a
     * different window.
     *
     * `week_start` repeats the host practice's (always a Saturday 00:00 UTC, AD-11, written from it
     * and never changed) so the later feature can count a trooper's sightings over a span of weeks
     * without a join. `seen_from`/`seen_until` are the capture window the trooper was seen in: the
     * game server reports presence over a window, not a time per player. `source` is `capture`
     * today, the only way a sighting is learnt.
     */
    public function up(): void
    {
        Schema::create('cross_section_sightings', function (Blueprint $table) {
            $table->id();
            // The trooper's history is kept, as in `practice_capture_players`: a trooper row is never
            // deleted while something still points at it.
            $table->foreignId('trooper_id')->constrained('troopers')->restrictOnDelete();
            // The host practice, the other section's, where the trooper was seen. A practice is only
            // ever deleted with generated demo history (#115), and its sightings go with it.
            $table->foreignId('section_practice_id')->constrained('section_practices')->cascadeOnDelete();
            $table->dateTime('week_start');
            $table->dateTime('seen_from');
            $table->dateTime('seen_until');
            $table->enum('source', ['capture']);
            $table->timestamps();

            $table->unique(['trooper_id', 'section_practice_id'], 'cross_section_sightings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cross_section_sightings');
    }
};
