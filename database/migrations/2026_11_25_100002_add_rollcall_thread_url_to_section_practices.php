<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The forum thread the week's roll call was posted in (#703), as the leader pasted it when marking the roll call
     * posted: the close step links to it to edit the post, and Platoon Staff open it from the report. Null until given.
     */
    public function up(): void
    {
        Schema::table('section_practices', function (Blueprint $table) {
            $table->string('rollcall_thread_url', 500)->nullable()->after('rollcall_posted_by');
        });
    }

    public function down(): void
    {
        Schema::table('section_practices', function (Blueprint $table) {
            $table->dropColumn('rollcall_thread_url');
        });
    }
};
