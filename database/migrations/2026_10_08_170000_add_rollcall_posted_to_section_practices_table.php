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
        // When the section's rollcall was last marked as posted to the forum thread, and by whom
        // (3.10). The portal never posts itself (AD-20): a leader copies the BBCode, posts it, then
        // records that here through the audited `MarkRollcallPosted` Command. Marking again after
        // an edit overwrites both — the latest post wins. Null until first marked.
        Schema::table('section_practices', function (Blueprint $table) {
            $table->dateTime('rollcall_posted_at')->nullable()->after('capture_source');
            // `nullOnDelete`: removing a user must not take the section's practice history with it.
            $table->foreignId('rollcall_posted_by')->nullable()->after('rollcall_posted_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('section_practices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rollcall_posted_by');
            $table->dropColumn('rollcall_posted_at');
        });
    }
};
