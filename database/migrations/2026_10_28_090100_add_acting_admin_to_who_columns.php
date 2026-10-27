<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portal-owned (AD-22). When an Admin acting as someone (#379) sends a report, adds a guest or sets a platoon's
     * topic, the existing column keeps naming the person, and these name the Admin who really did it, so the screens
     * read "Hale.S for SGT Mercer.J". Null for everything done in one's own name, so every existing row reads as before.
     * Each follows the delete rule of the column beside it: `restrictOnDelete` for a report's sender and a topic's
     * setter, `nullOnDelete` for a guest's adder.
     */
    public function up(): void
    {
        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->foreignId('sent_by_admin')->nullable()->after('sent_by')->constrained('users')->restrictOnDelete();
            $this->restoreChecks($table, 'weekly_reports');
        });
        Schema::table('cross_section_sightings', function (Blueprint $table) {
            $table->foreignId('recorded_by_admin')->nullable()->after('recorded_by')->constrained('users')->nullOnDelete();
            $this->restoreChecks($table, 'cross_section_sightings');
        });
        Schema::table('platoon_training_topics', function (Blueprint $table) {
            $table->foreignId('set_by_admin')->nullable()->after('set_by')->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('platoon_training_topics', fn (Blueprint $table) => $table->dropConstrainedForeignId('set_by_admin'));
        Schema::table('cross_section_sightings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by_admin');
            $this->restoreChecks($table, 'cross_section_sightings');
        });
        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_by_admin');
            $this->restoreChecks($table, 'weekly_reports');
        });
    }

    /**
     * SQLite (the test DB) cannot add or drop a foreign key in place, so Laravel rebuilds the table, and the rebuild
     * drops the CHECK that backs an enum column. Restating the column puts it back, as
     * 2026_10_16_100001_add_template_version_foreign_key_to_weekly_reports_table does. MySQL alters in place and keeps
     * its enums, so it is left alone there. Values as in the migrations that last set them.
     */
    private function restoreChecks(Blueprint $table, string $tableName): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        match ($tableName) {
            'weekly_reports' => $table->enum('status', ['draft', 'sent'])->default('draft')->change(),
            'cross_section_sightings' => $table->enum('source', ['capture', 'manual'])->change(),
        };
    }
};
