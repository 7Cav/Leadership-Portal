<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asking to edit a report that locked at its deadline: a unit's weekly report (`trooper_id` null) or a staff
     * member's staff report (`trooper_id` the staff member, `unit_id` their HQ). A leader above approves or denies it.
     * An approval sets `unlocked_until` here and on the report, which can then be edited until that time.
     */
    public function up(): void
    {
        Schema::create('report_edit_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('trooper_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('week_start');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'denied'])->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('unlocked_until')->nullable();
            $table->timestamps();

            $table->index(['unit_id', 'trooper_id', 'week_start'], 'report_edit_requests_report');
        });

        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->timestamp('unlocked_until')->nullable();
        });

        Schema::table('staff_reports', function (Blueprint $table) {
            $table->timestamp('unlocked_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->dropColumn('unlocked_until');
        });

        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->dropColumn('unlocked_until');
        });

        Schema::dropIfExists('report_edit_requests');
    }
};
