<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portal-owned (AD-22). Staff (HQ staff and staff departments) are on no section's rollcall, so the section practices
     * they attend were recorded nowhere (#448). A staff member, their staff leader or an Admin now enters one by hand:
     * `source` widens to `staff` for those entries, in the same table as the guests a host leader adds, one row per
     * (trooper, host practice) under the same UNIQUE key. `recorded_by` (and the acting columns beside it) names who
     * entered it, so a leader can see when a staff member reported it themselves.
     *
     * A `staff` row is never a guest: the rollcall's guest list, the automatic makeup and the JSAM counts read only
     * `capture` and `manual` rows (`CrossSectionSightingRepository`).
     */
    public function up(): void
    {
        Schema::table('cross_section_sightings', function (Blueprint $table) {
            $table->enum('source', ['capture', 'manual', 'staff'])->change();
        });
    }

    /**
     * Refused while any staff entry exists: the narrower enum cannot hold those rows, and deleting what a staff member
     * or their leader entered is not a rollback's call (the same rule as the hand-added guests' rollback).
     */
    public function down(): void
    {
        $staff = DB::table('cross_section_sightings')->where('source', 'staff')->count();

        if ($staff > 0) {
            throw new RuntimeException("Cannot roll back staff practice entries: {$staff} cross_section_sightings row(s) were entered for staff. Remove them on the milpac first, then roll back again.");
        }

        Schema::table('cross_section_sightings', function (Blueprint $table) {
            $table->enum('source', ['capture', 'manual'])->change();
        });
    }
};
