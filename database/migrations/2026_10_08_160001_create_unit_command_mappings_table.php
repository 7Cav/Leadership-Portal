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
        // Portal-owned (AD-22): which unit a Cav leadership billet commands (1.16, AD-7) —
        // `Section Leader 2/1/A/1-7` → that section, `Commander A/1-7` → that company,
        // `1-7 Commanding Officer` → that battalion. Written by the org-structure import (2.9),
        // read by ScopeResolver. Deliberately a separate table from `unit_billet_mappings`: a
        // Section Leader billet is both a section billet (2.5's placement) and a command billet,
        // and keeping the two apart means a command mapping can never place a trooper in a
        // non-section.
        Schema::create('unit_command_mappings', function (Blueprint $table) {
            $table->id();
            // `cascadeOnDelete`: a command over a removed unit means nothing, so it goes with the
            // unit rather than blocking the delete — and the leader's derived scope simply
            // shrinks to whatever else they hold.
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            // One billet commands exactly one unit.
            $table->unsignedBigInteger('cav_position_id')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unit_command_mappings');
    }
};
