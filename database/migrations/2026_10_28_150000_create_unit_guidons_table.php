<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A company's, battalion's or the regiment's guidon (#446). Portal-owned (AD-22): written only by
     * `UnitGuidonRepository` for the audited `SaveUnitGuidon` Command. The design is stored, not an image: the page
     * draws the flag from these four fields, so it stays sharp at every size. Colors are `#rrggbb`, the texts are short
     * and limited to a safe set of characters, both checked before a save.
     */
    public function up(): void
    {
        Schema::create('unit_guidons', function (Blueprint $table) {
            $table->id();
            // One guidon per unit; it goes with the unit.
            $table->foreignId('unit_id')->unique()->constrained('units')->cascadeOnDelete();
            $table->string('primary_color', 7);
            $table->string('secondary_color', 7);
            $table->string('top_left_text', 6);
            $table->string('bottom_right_text', 6);
            // Who saved it last: the person, or the Admin while acting as someone.
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unit_guidons');
    }
};
