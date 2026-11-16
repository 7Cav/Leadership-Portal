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
        // A cache of the 7Cav forum calendar (#751), one row per time an event takes place: a weekly event has a row for
        // each week it falls in. Written only by the calendar sync (`Sync\SyncCalendarHandler`, AD-4), read by the roll
        // call. `uid` is the forum's event id, so an event and its occurrences are matched across reads by it and its
        // start. The title is the forum's, as written; a long one is cut to fit.
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 191);
            $table->string('title');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('url', 500)->nullable();
            $table->timestamps();

            $table->unique(['uid', 'starts_at']);
            $table->index('starts_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
