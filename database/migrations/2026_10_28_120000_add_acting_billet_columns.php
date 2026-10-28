<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portal-owned (AD-22). An Admin can act as a billet (#379 follow-up): a unit's leader, with no person behind it.
     * What they do is recorded in their own name, with the unit whose billet they held beside it, so the screens read
     * "Hale.S acting as Section Leader 2/1/A/1-7". Null for everything else, so every existing row reads as before.
     *
     * `audit_log.on_behalf_of_unit_id` carries no foreign key, like `target_id`: the log is append-only and must keep
     * naming a unit that is later deleted. The record columns null out with their unit instead (`nullOnDelete`).
     */
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->unsignedBigInteger('on_behalf_of_unit_id')->nullable()->after('on_behalf_of_id');
        });
        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->foreignId('sent_as_unit')->nullable()->after('sent_by_admin')->constrained('units')->nullOnDelete();
            $this->restoreChecks($table, 'weekly_reports');
        });
        Schema::table('cross_section_sightings', function (Blueprint $table) {
            $table->foreignId('recorded_as_unit')->nullable()->after('recorded_by_admin')->constrained('units')->nullOnDelete();
            $this->restoreChecks($table, 'cross_section_sightings');
        });
        Schema::table('platoon_training_topics', function (Blueprint $table) {
            $table->foreignId('set_as_unit')->nullable()->after('set_by_admin')->constrained('units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('platoon_training_topics', fn (Blueprint $table) => $table->dropConstrainedForeignId('set_as_unit'));
        Schema::table('cross_section_sightings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_as_unit');
            $this->restoreChecks($table, 'cross_section_sightings');
        });
        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_as_unit');
            $this->restoreChecks($table, 'weekly_reports');
        });
        Schema::table('audit_log', fn (Blueprint $table) => $table->dropColumn('on_behalf_of_unit_id'));
    }

    /**
     * SQLite (the test DB) rebuilds a table to add or drop a foreign key, and the rebuild drops the CHECK behind an enum
     * column; restating the column puts it back (as 2026_10_28_090100 does). MySQL alters in place.
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
