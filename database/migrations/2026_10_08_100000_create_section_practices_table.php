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
            // Always a Saturday 00:00 UTC (AD-11). A `datetime` stores the literal clock time with
            // no session-timezone conversion (unlike MySQL's `timestamp`), so it is the same text
            // on every driver. That is correct because `SectionPracticeRepository` converts to UTC
            // before writing and the app timezone (`config/app.php`) is UTC, so the cast reads it
            // back as the same instant.
            $table->dateTime('week_start');
            // The auto-capture seam (AD-14, AD-19): null until server retrieval lands (sequenced
            // last), which then fills these on the existing row. The manual/auto distinction
            // itself lives in `attendance.source`; auto-capture is never a second writer of record.
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
