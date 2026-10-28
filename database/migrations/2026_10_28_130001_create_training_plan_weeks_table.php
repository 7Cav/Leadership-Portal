<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portal-owned (AD-22): the subject a training plan (#380) gives one Cav week. A week the platoon leader left blank
     * has no row. Written only by `TrainingPlanRepository`, replaced as a whole with each audited save of the plan.
     */
    public function up(): void
    {
        Schema::create('training_plan_weeks', function (Blueprint $table) {
            $table->id();
            // A week is part of its plan and means nothing without it (`cascadeOnDelete`).
            $table->foreignId('training_plan_id')->constrained('training_plans')->cascadeOnDelete();
            // Always a Saturday 00:00 UTC (AD-11), stored like `platoon_training_topics.week_start`.
            $table->dateTime('week_start');
            $table->string('subject');
            $table->timestamps();

            // One subject per week of a plan.
            $table->unique(['training_plan_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_plan_weeks');
    }
};
