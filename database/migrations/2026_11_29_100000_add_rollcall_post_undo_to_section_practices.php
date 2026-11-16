<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The posted stamp a "mark posted" replaced (`{at, by, threadUrl}`, each possibly null), so unticking "I edited the
     * forum post" by mistake can put it back. Null when there is nothing to undo: never stamped, or undone already.
     */
    public function up(): void
    {
        Schema::table('section_practices', function (Blueprint $table) {
            $table->json('rollcall_post_undo')->nullable()->after('rollcall_thread_url');
        });
    }

    public function down(): void
    {
        Schema::table('section_practices', function (Blueprint $table) {
            $table->dropColumn('rollcall_post_undo');
        });
    }
};
