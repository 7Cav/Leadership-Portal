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
        // Portal-owned: the training topic a platoon leader sets for their sections for one Cav
        // week. Written only by `PlatoonTrainingTopicRepository` for the audited
        // `SetPlatoonTrainingTopic` Command. A section's report reads it as its training subject:
        // locked when `enforced`, otherwise a default the section leader may change. A sent report
        // keeps the subject it froze, whatever happens to this row later.
        Schema::create('platoon_training_topics', function (Blueprint $table) {
            $table->id();
            // A topic is planning for a week, not a record of what was reported, so it goes with
            // the platoon.
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            // Always a Saturday 00:00 UTC (AD-11), stored like `weekly_reports.week_start`.
            $table->dateTime('week_start');
            $table->string('subject');
            $table->boolean('enforced')->default(false);
            $table->foreignId('set_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // One topic per platoon per week.
            $table->unique(['unit_id', 'week_start']);
            $table->index('set_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platoon_training_topics');
    }
};
