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
        // Cache table (AD-4/AD-5/AD-22): the sole writer is `Sync\SyncRosterHandler`, upserting
        // keyed by `cav_id` onto this table's own auto-increment PK so the PK never changes
        // under dependent `attendance`/`weekly_reports` rows. A departed trooper's `status`
        // flips to inactive; the row is never truncated or hard-deleted (AD-4).
        Schema::create('troopers', function (Blueprint $table) {
            $table->id();
            // Matches `users.cav_id`'s width (unsignedBigInteger) — both hold the same milpac
            // relation id, and a later FK between them needs identical column types.
            $table->unsignedBigInteger('cav_id')->unique();
            $table->string('rank')->nullable();
            $table->string('mos')->nullable();
            $table->date('tis')->nullable();
            $table->date('tig')->nullable();
            $table->string('status');
            // Populated by 2.5/2.7, not by the sync handler — nullable until then. `nullOnDelete`
            // rather than `restrictOnDelete`: removing a unit must never block on a cached
            // roster row, unlike `units.parent_id` (AD-18's own tree integrity).
            $table->foreignId('primary_section_id')->nullable()->constrained('units')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('troopers');
    }
};
