<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Portal-owned (AD-5/AD-22): not re-fetchable, so it must be backed up. One creating
        // writer — 3.3's audited `MarkAttendance` Command, through `SectionPracticeRepository` —
        // and rows are never truncate-and-reinserted: `attendance` rows hang off this PK.
        // The grain is AD-14's: one practice row per (section, week), the anchor every
        // trooper-week's single counting SP mark attaches to.
        Schema::create('section_practices', function (Blueprint $table) {
            $table->id();
            // `restrictOnDelete`: a section with recorded practices cannot be deleted out from
            // under its attendance history (no orphaned marks) — the same protective posture as
            // `units.parent_id` (AD-18).
            $table->foreignId('section_id')->constrained('units')->restrictOnDelete();
            // Always a Saturday 00:00 UTC (AD-11). A datetime rather than a date so the stored
            // value is that exact instant on every driver, and no session-timezone conversion
            // (as `timestamp` would apply on MySQL) can shift it off the boundary.
            $table->dateTime('week_start');
            // The auto-capture seam (AD-14, AD-19): null until server retrieval lands (sequenced
            // last). It becomes a second *source* for this row, never a second writer of record.
            $table->string('server_ref')->nullable();
            $table->dateTime('captured_at')->nullable();
            $table->string('capture_source')->nullable();
            $table->timestamps();

            $table->unique(['section_id', 'week_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('section_practices');
    }
};
