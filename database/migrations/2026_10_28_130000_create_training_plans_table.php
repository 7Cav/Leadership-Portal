<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portal-owned (AD-22): a platoon's monthly training plan (#380), one training subject per Cav week of the month,
     * which the company reviews. Written only by `TrainingPlanRepository` for the audited `SaveTrainingPlan`,
     * `ApproveTrainingPlan` and `ReturnTrainingPlan` Commands (AD-16). An approved plan writes each week's subject to
     * `platoon_training_topics`, which the section reports already read; the plan itself is never read by a report.
     */
    public function up(): void
    {
        Schema::create('training_plans', function (Blueprint $table) {
            $table->id();
            // Planning for the platoon, like its weekly topic: it goes with the platoon (`cascadeOnDelete`).
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            // `YYYY-MM`. The plan holds every Cav week whose Saturday (AD-11) falls in this month, so each week
            // belongs to exactly one plan. A string, not a date: no driver turns it into a timestamp.
            $table->char('month', 7);
            $table->enum('status', ['pending', 'approved', 'returned'])->default('pending');
            // Whether the approved subjects are written locked on every section's report, or as a default.
            $table->boolean('enforced')->default(false);
            // Bumped on every save, so an approval names the version the reviewer read.
            $table->unsignedInteger('revision')->default(1);
            // Who wrote it last, with the Admin acting as them or the billet an Admin held (#379), as for a topic's
            // setter: `restrictOnDelete` for the person, `nullOnDelete` for the billet's unit.
            $table->foreignId('written_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('written_by_admin')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('written_as_unit')->nullable()->constrained('units')->nullOnDelete();
            // The last review, cleared by the next save: who approved or sent it back, and the comment they sent it
            // back with. The same delete rules as the writer's.
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by_admin')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_as_unit')->nullable()->constrained('units')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->timestamps();

            // One plan per platoon per month.
            $table->unique(['unit_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_plans');
    }
};
