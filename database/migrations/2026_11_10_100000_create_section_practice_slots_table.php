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
        // A section's weekly practice slot (#392): a weekday and a start time in Zulu. A section practice is always one
        // hour, so there is no duration. One slot per section; a section with none keeps manual capture over the week.
        Schema::create('section_practice_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->unique()->constrained('units')->cascadeOnDelete();
            // 0 Sunday to 6 Saturday, as Carbon numbers days.
            $table->unsignedTinyInteger('weekday');
            // Minutes after 00:00Z, 0 to 1439.
            $table->unsignedSmallInteger('start_minute');
            $table->timestamps();
        });

        // Per week: the window the capture read (so a later slot change never rewrites history), and a one-off move
        // or cancellation of that week's practice.
        Schema::table('section_practices', function (Blueprint $table) {
            $table->dateTime('window_start')->nullable()->after('capture_source');
            $table->dateTime('window_end')->nullable()->after('window_start');
            $table->dateTime('slot_moved_to')->nullable()->after('window_end');
            $table->boolean('slot_cancelled')->default(false)->after('slot_moved_to');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('section_practices', function (Blueprint $table) {
            $table->dropColumn(['window_start', 'window_end', 'slot_moved_to', 'slot_cancelled']);
        });

        Schema::dropIfExists('section_practice_slots');
    }
};
