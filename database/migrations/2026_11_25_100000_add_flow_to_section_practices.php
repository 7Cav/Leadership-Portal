<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which roll call flow a section-week follows (#703): 1 is the three steps (signatures, practice, review and post),
     * 2 the explicit cycle (create, post, sign and mark, close for review). Every week already here keeps 1, so a week
     * running when this ships finishes as it started; a row made later takes the flow its week starts under
     * (`RollcallFlow::forWeek()`), unless its maker pins one (the tutorial keeps 1). `rollcall_created_at`/`_by` record
     * a flow 2 roll call being created, with its date and topic.
     */
    public function up(): void
    {
        Schema::table('section_practices', function (Blueprint $table) {
            $table->unsignedTinyInteger('flow_version')->default(1)->after('week_start');
            $table->timestamp('rollcall_created_at')->nullable()->after('flow_version');
            $table->foreignId('rollcall_created_by')->nullable()->after('rollcall_created_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('section_practices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rollcall_created_by');
            $table->dropColumn(['flow_version', 'rollcall_created_at']);
        });
    }
};
