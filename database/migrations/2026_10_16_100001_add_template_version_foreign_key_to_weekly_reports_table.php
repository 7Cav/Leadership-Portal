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
        // `weekly_reports.template_version_id` was created without a constraint because
        // `template_versions` (3.5) did not exist yet. It does now, so the column gets the foreign key
        // its create migration deferred.
        //
        // `restrictOnDelete`, like `active_template_versions.template_version_id`: a version is part
        // of the record of what was sent (AD-12, AD-17) and is covered by the report's
        // `content_digest`, so it cannot be deleted from under a sent report. No flow deletes a
        // version today; they are written once and kept.
        //
        // Existing rows: the send path has only ever written null here, so the constraint applies
        // cleanly. A row holding an id that names no version makes this migration fail rather than
        // be rewritten, because the id is part of that report's digest.
        Schema::table('weekly_reports', function (Blueprint $table) {
            // Covers the reverse FK lookup on SQLite (the test DB), where a foreign key gets no index
            // of its own; the same reason `sent_by` has one.
            $table->index('template_version_id');
            $table->foreign('template_version_id')->references('id')->on('template_versions')->restrictOnDelete();

            // SQLite (the test DB) cannot add a foreign key in place, so Laravel rebuilds the table, and the
            // rebuild drops the CHECK that backs the `status` enum. Restating the column puts it back. MySQL
            // alters in place and keeps its enum, so it is left alone there. Values as in the create migration.
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->enum('status', ['draft', 'sent'])->default('draft')->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->dropForeign(['template_version_id']);
            $table->dropIndex(['template_version_id']);

            // The same SQLite rebuild as in `up()`.
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->enum('status', ['draft', 'sent'])->default('draft')->change();
            }
        });
    }
};
