<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A sent report can be edited until it locks at its deadline (`WeekOf::reportDue()`). A weekly report is reopened
     * first: `reopened_at`/`reopened_by` say who opened it, and are cleared when it is sent again. Both tables keep what
     * every earlier send froze in `revisions` (oldest first, null until a report is edited), so a re-send never loses
     * the version it replaces. A staff report keeps the sheet range its row went to (`sheet_range`), so an edit
     * overwrites that row in the reports Google Sheet instead of adding a second one.
     */
    public function up(): void
    {
        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->timestamp('reopened_at')->nullable()->after('sent_by');
            $table->foreignId('reopened_by')->nullable()->after('reopened_at')->constrained('users')->restrictOnDelete();
            $table->json('revisions')->nullable()->after('makeup_checks');

            // SQLite (the test DB) rebuilds the table to add a foreign key, and the rebuild drops the CHECK behind
            // the `status` enum. Restating the column puts it back. MySQL alters in place and keeps its enum.
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->enum('status', ['draft', 'sent'])->default('draft')->change();
            }
        });

        Schema::table('staff_reports', function (Blueprint $table) {
            $table->json('revisions')->nullable();
            $table->string('sheet_range')->nullable()->after('sheet_pushed_at');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reopened_by');
            $table->dropColumn(['reopened_at', 'revisions']);

            // The same SQLite rebuild as in `up()`.
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->enum('status', ['draft', 'sent'])->default('draft')->change();
            }
        });

        Schema::table('staff_reports', function (Blueprint $table) {
            $table->dropColumn(['revisions', 'sheet_range']);
        });
    }
};
