<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Joint Service Achievement Medal evidence: a host section's leader may
     * say by hand that a trooper from another section attended their practice, alongside what the game-server capture
     * saw. `source` widens to `manual` for those entries, and `recorded_by` names the leader who said so, so the guest's
     * own leader can see whose word put the makeup on their rollcall. `nullOnDelete`, like the other actor columns
     * (`confirmed_by`, `rollcall_posted_by`): removing a user must not take the attendance evidence with it.
     *
     * `seen_from`/`seen_until` become nullable: they are the capture window the server saw the trooper in, and a leader's
     * entry has no window. A capture sighting still always carries both (the repository writes them); the week is in
     * `week_start` either way, which is what the candidates are counted by.
     *
     * Still one row per (trooper, host practice), whatever the source: a trooper the capture already saw at this
     * practice cannot be added again by hand, and a hand-added guest the capture then sees becomes the capture's row
     * (keeping `recorded_by`), so it can no longer be removed by hand.
     */
    public function up(): void
    {
        Schema::table('cross_section_sightings', function (Blueprint $table) {
            $table->enum('source', ['capture', 'manual'])->change();
            $table->dateTime('seen_from')->nullable()->change();
            $table->dateTime('seen_until')->nullable()->change();
            $table->foreignId('recorded_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Refused while any hand-added guest exists: the narrower enum cannot hold those rows (MySQL in strict mode fails the
     * ALTER), they have no capture window for the NOT NULL columns, and deleting evidence a leader entered is not a
     * rollback's call (the same rule as the WARDOGS platform rollback).
     */
    public function down(): void
    {
        $manual = DB::table('cross_section_sightings')->where('source', 'manual')->count();

        if ($manual > 0) {
            throw new RuntimeException("Cannot roll back hand-added guests: {$manual} cross_section_sightings row(s) were recorded by a leader. Remove them on the rollcall first, then roll back again.");
        }

        Schema::table('cross_section_sightings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by');
        });

        Schema::table('cross_section_sightings', function (Blueprint $table) {
            $table->enum('source', ['capture'])->change();
            $table->dateTime('seen_from')->nullable(false)->change();
            $table->dateTime('seen_until')->nullable(false)->change();
        });
    }
};
