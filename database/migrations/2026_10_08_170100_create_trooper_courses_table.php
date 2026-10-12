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
        // Portal-owned (AD-5/AD-22): no external source says who holds which course, so these rows are
        // manually set, must be backed up, and have exactly one creating writer, the audited
        // `SetTrooperCourseStatus` Command through `TrooperCourseRepository`. A missing row and a `none`
        // row mean the same thing: the trooper neither holds the course nor is required to.
        Schema::create('trooper_courses', function (Blueprint $table) {
            $table->id();
            // `restrictOnDelete` on both: troopers are never deleted (AD-4) and neither are courses, so
            // nothing can orphan a status and leave the matrix with a cell for something that is gone.
            $table->foreignId('trooper_id')->constrained('troopers')->restrictOnDelete();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            // An enum column like `courses.category`: the database refuses a value `CourseStatus` does not know.
            $table->enum('status', ['qualified', 'in_progress', 'required', 'none']);
            // The day the trooper qualified. Set only while `status` is `qualified`; the Command clears it
            // when the status moves away from it.
            $table->date('awarded_at')->nullable();
            // Who last set the status. Metadata, so a deleted user nulls it rather than blocking the delete.
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // A trooper has at most one status per course. A second row for the pair is refused by the
            // database, never upserted behind the caller's back.
            $table->unique(['trooper_id', 'course_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trooper_courses');
    }
};
