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
        // Every change to a section's weekly practice slot (#630), so a past week keeps the slot it had after a later
        // change or removal. `effective_from` is the Cav week (its Saturday) the change starts with, null for "from the
        // start"; a null `weekday`/`start_minute` is a removal. `section_practice_slots` stays the current slot. A
        // section's first change records what it had before, so existing slots need no backfill.
        Schema::create('section_practice_slot_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('units')->cascadeOnDelete();
            $table->date('effective_from')->nullable();
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedSmallInteger('start_minute')->nullable();
            $table->timestamps();
            $table->index(['section_id', 'effective_from']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('section_practice_slot_changes');
    }
};
