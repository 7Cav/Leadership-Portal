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
        });

        Schema::table('staff_reports', function (Blueprint $table) {
            $table->dropColumn(['revisions', 'sheet_range']);
        });
    }
};
