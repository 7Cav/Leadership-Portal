<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clock changes (16NOV26). `users.timezone` is the time zone the person's browser reports, kept so the portal knows
     * when clocks change where a platoon leader lives. `practice_slot_confirmations` holds each platoon leader's answer
     * for a section and one clock change: the slot kept, or moved to stay at the same local time. A section's weekly
     * report waits for it in the week before and the week of the change.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable()->after('email');
        });

        Schema::create('practice_slot_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('units')->cascadeOnDelete();
            // The moment the clocks change, in UTC.
            $table->timestamp('change_at');
            $table->unsignedTinyInteger('weekday');
            $table->unsignedSmallInteger('start_minute');
            $table->boolean('moved')->default(false);
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['section_id', 'change_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_slot_confirmations');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
