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
        // Portal-owned (AD-22): Cav's roster API names a trooper's billet (positionId), never
        // their section (2.5) — this table is the curated, hand-maintained bridge between the
        // two, read by `Sync\SyncRosterHandler` to resolve `troopers.primary_section_id`. Not
        // synced from anywhere; an Admin maintains it directly for now (a maintenance UI is
        // future work).
        Schema::create('unit_billet_mappings', function (Blueprint $table) {
            $table->id();
            // `restrictOnDelete`, not `nullOnDelete`: a mapping row pointing at nothing is
            // meaningless, so removing a section first requires clearing (or moving) any mapping
            // that resolves to it — the same protective posture as `units.parent_id` (AD-18).
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            // One billet maps to exactly one section — the unique index is what makes the
            // mapping a function (deterministic), not a one-to-many guess.
            $table->unsignedBigInteger('cav_position_id')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unit_billet_mappings');
    }
};
